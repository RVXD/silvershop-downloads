<?php

declare(strict_types=1);

namespace SilverShop\Downloads\Tests;

use SilverShop\Downloads\Download;
use SilverShop\Downloads\DownloadLog;
use SilverShop\Model\Order;
use SilverShop\Model\Product\OrderItem as ProductOrderItem;
use SilverShop\Model\Variation\OrderItem as VariationOrderItem;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers the security and access rules of the downloads module: who may view/download a file, protected storage,
 * the paid-order gate, per-customer limit and expiry (global + per-product), per-variation delivery, and the
 * sold-download delete guard.
 */
class DownloadTest extends SapphireTest
{
    protected $usesDatabase = true;

    private Member $buyer;

    private Member $stranger;

    private Product $product;

    private Download $download;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('DownloadTest');

        $this->buyer = $this->makeMember('buyer@example.com');
        $this->stranger = $this->makeMember('stranger@example.com');

        $this->product = Product::create();
        $this->product->Title = 'Ebook';
        $this->product->IsDigital = true;
        $this->product->write();

        $this->download = $this->makeDownload('Ebook PDF', (int) $this->product->ID);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testAnonymousCannotView(): void
    {
        Security::setCurrentUser(null);
        $this->assertFalse($this->download->canView());
    }

    public function testBuyerCanViewAfterPaidOrder(): void
    {
        $this->makePaidOrder($this->buyer, $this->product);
        $this->assertTrue($this->download->canView($this->buyer));
    }

    public function testStrangerCannotView(): void
    {
        $this->makePaidOrder($this->buyer, $this->product);
        $this->assertFalse($this->download->canView($this->stranger));
    }

    public function testUnpaidOrderDoesNotGrantAccess(): void
    {
        // An order that was placed but never paid must not deliver the file.
        $order = Order::create();
        $order->MemberID = $this->buyer->ID;
        $order->Status = 'Unpaid';
        $order->write();
        $this->addItem($order, $this->product);

        $this->assertNull($this->download->grantingOrderFor($this->buyer));
        $this->assertFalse($this->download->canDownloadFile($this->buyer));
    }

    public function testCannotDownloadWithoutPurchase(): void
    {
        $this->assertFalse($this->download->canDownloadFile($this->stranger));
    }

    public function testCanDownloadAfterPaidOrder(): void
    {
        $this->makePaidOrder($this->buyer, $this->product);
        $this->assertTrue($this->download->canDownloadFile($this->buyer));
    }

    public function testFileStoredProtected(): void
    {
        $file = $this->download->File();
        $this->assertSame('protected', $file->getVisibility());
        $this->assertSame('LoggedInUsers', $file->CanViewType);
    }

    public function testDownloadLimitReached(): void
    {
        SiteConfig::current_site_config()->update(['DownloadLimit' => 2])->write();
        $order = $this->makePaidOrder($this->buyer, $this->product);

        $this->assertTrue($this->download->canDownloadFile($this->buyer));

        // Record two downloads; the third is refused.
        $this->logDownload($order);
        $this->logDownload($order);
        $this->assertFalse($this->download->canDownloadFile($this->buyer));
    }

    public function testProductOverrideBeatsGlobalLimit(): void
    {
        // Global says unlimited (0), but the product caps at 1.
        SiteConfig::current_site_config()->update(['DownloadLimit' => 0])->write();
        $this->product->update([
            'OverrideDownloadSettings' => true,
            'DownloadLimit' => 1,
        ])->write();

        $order = $this->makePaidOrder($this->buyer, $this->product);
        $this->assertSame(1, $this->download->effectiveDownloadLimit());

        $this->logDownload($order);
        $this->assertFalse($this->download->canDownloadFile($this->buyer));
    }

    public function testExpiredDownloadDenied(): void
    {
        $this->product->update([
            'OverrideDownloadSettings' => true,
            'DownloadExpiryDays' => 1,
        ])->write();

        // Paid two days ago, expiry one day → expired.
        $this->makePaidOrder($this->buyer, $this->product, 2);
        $this->assertFalse($this->download->canDownloadFile($this->buyer));
    }

    public function testVariationDownloadOnlyForThatVariationBuyer(): void
    {
        [$variationA, $variationB] = $this->makeVariations($this->product);
        $downloadA = $this->makeDownload('Variation A file', null, (int) $variationA->ID);

        // Buyer purchases variation A; stranger purchases variation B.
        $this->makePaidVariationOrder($this->buyer, $variationA);
        $this->makePaidVariationOrder($this->stranger, $variationB);

        $this->assertTrue($downloadA->canView($this->buyer));
        $this->assertFalse($downloadA->canView($this->stranger));
    }

    public function testProductWideDownloadReachesVariationBuyer(): void
    {
        [$variationA] = $this->makeVariations($this->product);
        $this->makePaidVariationOrder($this->buyer, $variationA);

        // $this->download is product-scoped; a variation purchase carries the parent ProductID, so it grants it.
        $this->assertTrue($this->download->canView($this->buyer));
    }

    public function testAvailableDownloadsMergesProductAndVariation(): void
    {
        [$variationA] = $this->makeVariations($this->product);
        $variationDownload = $this->makeDownload('Variation A file', null, (int) $variationA->ID);
        $this->makePaidVariationOrder($this->buyer, $variationA);

        $ids = $this->buyer->AvailableDownloads()->column('ID');
        $this->assertContains((int) $this->download->ID, array_map('intval', $ids));
        $this->assertContains((int) $variationDownload->ID, array_map('intval', $ids));
    }

    public function testUnsoldDownloadCanBeDeleted(): void
    {
        $this->assertFalse($this->download->hasBeenSold());
        $this->assertTrue($this->download->canDelete());
    }

    public function testSoldDownloadCannotBeDeleted(): void
    {
        $this->makePaidOrder($this->buyer, $this->product);
        $this->assertTrue($this->download->hasBeenSold());
        $this->assertFalse($this->download->canDelete());
    }

    public function testGuestCanDownloadViaValidToken(): void
    {
        $order = $this->makePaidOrder($this->buyer, $this->product);

        $this->assertNotEmpty($order->DownloadToken);
        $this->assertTrue($this->download->canDownloadViaToken((int) $order->ID, (string) $order->DownloadToken));
        $this->assertFalse($this->download->canDownloadViaToken((int) $order->ID, 'wrong-token'));
    }

    public function testTokenAccessRequiresPaidOrder(): void
    {
        $order = Order::create();
        $order->MemberID = $this->buyer->ID;
        $order->Status = 'Unpaid';
        $order->write();
        $this->addItem($order, $this->product);
        $order->write();

        $this->assertEmpty($order->DownloadToken);
        $this->assertFalse($this->download->canDownloadViaToken((int) $order->ID, 'anything'));
    }

    public function testOrderDownloadLinksCarryToken(): void
    {
        $order = $this->makePaidOrder($this->buyer, $this->product);
        $links = $order->DownloadLinksForOrder();

        $this->assertCount(1, $links);
        $this->assertStringContainsString('token=' . $order->DownloadToken, (string) $links->first()->Link);
    }

    public function testDigitalProductZerosWeight(): void
    {
        $product = Product::create();
        $product->Title = 'Weighted digital';
        $product->IsDigital = true;
        $product->Weight = 5;
        $product->write();

        $this->assertEquals(0, $product->Weight);
    }

    // ---- helpers ----

    private function makeMember(string $email): Member
    {
        $member = Member::create();
        $member->Email = $email;
        $member->write();

        return $member;
    }

    private function makeDownload(string $title, ?int $productId = null, ?int $variationId = null): Download
    {
        $file = File::create();
        $file->setFromString('secret ' . $title, 'downloads/' . preg_replace('/\W+/', '-', strtolower($title)) . '.txt');
        $file->write();

        $download = Download::create();
        $download->Title = $title;
        if ($productId) {
            $download->ProductID = $productId;
        }
        if ($variationId) {
            $download->VariationID = $variationId;
        }
        $download->FileID = $file->ID;
        $download->write();

        return $download;
    }

    private function makePaidOrder(Member $member, Product $product, int $paidDaysAgo = 0): Order
    {
        $order = Order::create();
        $order->MemberID = $member->ID;
        $order->write();
        $this->addItem($order, $product);

        $order->Status = 'Paid';
        $order->Paid = date('Y-m-d H:i:s', strtotime("-{$paidDaysAgo} days"));
        $order->write();

        return $order;
    }

    private function addItem(Order $order, Product $product): void
    {
        $item = ProductOrderItem::create();
        $item->OrderID = $order->ID;
        $item->ProductID = $product->ID;
        $item->Quantity = 1;
        $item->write();
    }

    /**
     * @return Variation[]
     */
    private function makeVariations(Product $product): array
    {
        $variations = [];
        foreach (['A', 'B'] as $suffix) {
            $variation = Variation::create();
            $variation->ProductID = $product->ID;
            $variation->InternalItemID = 'VAR-' . $suffix;
            $variation->Price = 10;
            $variation->write();
            $variations[] = $variation;
        }

        return $variations;
    }

    private function makePaidVariationOrder(Member $member, Variation $variation): Order
    {
        $order = Order::create();
        $order->MemberID = $member->ID;
        $order->write();

        $item = VariationOrderItem::create();
        $item->OrderID = $order->ID;
        $item->ProductID = $variation->ProductID;
        $item->ProductVariationID = $variation->ID;
        $item->Quantity = 1;
        $item->write();

        $order->Status = 'Paid';
        $order->Paid = date('Y-m-d H:i:s');
        $order->write();

        return $order;
    }

    private function logDownload(Order $order): void
    {
        $log = DownloadLog::create();
        $log->DownloadID = $this->download->ID;
        $log->MemberID = $this->buyer->ID;
        $log->OrderID = $order->ID;
        $log->write();
    }
}
