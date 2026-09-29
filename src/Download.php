<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Order;
use SilverShop\Model\Product\OrderItem as ProductOrderItem;
use SilverShop\Model\Variation\OrderItem as VariationOrderItem;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A downloadable file attached to a product or one of its variations. After a customer pays for an order
 * containing that product/variation, they may download it (subject to a per-customer download limit and optional
 * link expiry). The file is kept in the protected asset store and only served through {@link DownloadController}
 * — never a public URL.
 *
 * A download is scoped to either a Product (delivered to any buyer of the product, including any variation) or a
 * specific Variation (delivered only to buyers of that variation).
 *
 * @property string $Title
 * @property int $SortOrder
 * @property int $FileID
 * @property int $ProductID
 * @property int $VariationID
 * @method File File()
 * @method Product Product()
 * @method Variation Variation()
 */
class Download extends DataObject
{
    private static string $table_name = 'SilverShop_Download';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'File' => File::class,
        'Product' => Product::class,
        'Variation' => Variation::class,
    ];

    private static array $owns = ['File'];

    private static string $default_sort = '"SortOrder" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Title' => 'Title',
        'File.Name' => 'File',
        'File.Size' => 'Size',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['SortOrder', 'ProductID', 'VariationID']);

        $fields->replaceField('File', UploadField::create('File', _t(self::class . '.File', 'File'))
            ->setFolderName('downloads'));

        return $fields;
    }

    /**
     * Keep the downloadable file out of the public asset store — it must only ever be reachable through the gated
     * {@link DownloadController}, never a public URL.
     *
     * Two things are needed, and the file's *own* canView is what governs both: (1) `protectFile()` moves it into
     * the protected store now; (2) setting the file's `CanViewType` to deny anonymous users makes SilverStripe's
     * AssetControlExtension keep it protected even when the versioned product that owns it is published (otherwise
     * the publish cascade would move it back to the public store). The controller streams via `getStream()`
     * server-side, which works regardless of canView, so gated delivery is unaffected.
     */
    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if (!$this->FileID || !($file = $this->File()) || !$file->exists()) {
            return;
        }

        $changed = false;
        if ($file->CanViewType !== 'LoggedInUsers') {
            $file->CanViewType = 'LoggedInUsers';
            $changed = true;
        }
        if ($file->getVisibility() !== AssetStore::VISIBILITY_PROTECTED) {
            $file->protectFile();
            $changed = true;
        }
        if ($changed) {
            $file->write();
        }
    }

    /**
     * A download record is never publicly viewable — only staff and the customer who bought its product. This is
     * also what keeps the attached file in the *protected* asset store: SilverStripe's AssetControlExtension only
     * publishes an owned file to the public store when its owning record is viewable by anonymous users. So this
     * method both authorises access and guarantees the file cannot be reached by a public URL.
     */
    public function canView($member = null): bool
    {
        $member = $member ?: Security::getCurrentUser();

        if ($member && Permission::checkMember($member, 'CMS_ACCESS')) {
            return true;
        }

        if ($member && ($this->ProductID || $this->VariationID) && $this->grantingOrderFor($member)) {
            return true;
        }

        return false;
    }

    /**
     * Don't allow a download to be deleted once its product/variation has been sold — customers who bought it
     * would lose access (and deleting the record would take its file with it).
     */
    public function canDelete($member = null): bool
    {
        if ($this->hasBeenSold()) {
            return false;
        }

        return parent::canDelete($member);
    }

    /**
     * Has this download's product (or variation) been sold in any *paid* order?
     */
    public function hasBeenSold(): bool
    {
        if ($this->VariationID) {
            return VariationOrderItem::get()
                ->filter(['ProductVariationID' => $this->VariationID, 'Order.Paid:not' => null])
                ->exists();
        }

        if ($this->ProductID) {
            return ProductOrderItem::get()
                ->filter(['ProductID' => $this->ProductID, 'Order.Paid:not' => null])
                ->exists();
        }

        return false;
    }

    /**
     * The download URL for the current customer.
     */
    public function DownloadLink(): string
    {
        return DownloadController::singleton()->Link('process/' . $this->ID);
    }

    /**
     * May this member download the file right now — i.e. they own a paid order containing the product, and are
     * within the download limit and expiry window?
     */
    public function canDownloadFile(?Member $member = null): bool
    {
        $member = $member ?: Security::getCurrentUser();
        if (!$member || !$this->FileID) {
            return false;
        }

        $order = $this->grantingOrderFor($member);

        return $order && !$this->hasExpiredFor($order) && !$this->limitReachedFor($order, $member);
    }

    /**
     * Has the download window closed for this order (expiry days counted from when it was paid)?
     */
    public function hasExpiredFor(?Order $order): bool
    {
        if (!$order || !$order->Paid) {
            return false;
        }

        $expiryDays = $this->effectiveExpiryDays();

        return $expiryDays > 0 && (strtotime((string) $order->Paid) + $expiryDays * 86400) < time();
    }

    /**
     * Has the download limit been reached for this purchase? Downloads are counted per order (the entitlement),
     * so a re-purchase resets the allowance.
     */
    public function limitReachedFor(?Order $order, ?Member $member = null): bool
    {
        $limit = $this->effectiveDownloadLimit();
        if ($limit <= 0) {
            return false;
        }

        if ($order) {
            $filter = ['DownloadID' => $this->ID, 'OrderID' => $order->ID];
        } elseif ($member) {
            $filter = ['DownloadID' => $this->ID, 'MemberID' => $member->ID];
        } else {
            return false;
        }

        return DownloadLog::get()->filter($filter)->count() >= $limit;
    }

    /**
     * The most recent paid order by this member that grants this download. For a variation-scoped download that
     * means an order containing the variation; for a product-scoped download, an order containing the product —
     * which also covers variation purchases, since a variation order item extends the product order item and
     * carries the parent ProductID.
     */
    public function grantingOrderFor(Member $member): ?Order
    {
        if ($this->VariationID) {
            $orderIds = VariationOrderItem::get()
                ->filter([
                    'ProductVariationID' => $this->VariationID,
                    'Order.MemberID' => $member->ID,
                    'Order.Paid:not' => null,
                ])
                ->column('OrderID');
        } elseif ($this->ProductID) {
            $orderIds = ProductOrderItem::get()
                ->filter([
                    'ProductID' => $this->ProductID,
                    'Order.MemberID' => $member->ID,
                    'Order.Paid:not' => null,
                ])
                ->column('OrderID');
        } else {
            return null;
        }

        if (empty($orderIds)) {
            return null;
        }

        return Order::get()->filter('ID', array_map('intval', $orderIds))->sort('"Paid" DESC')->first();
    }

    /**
     * Token-based access for guest checkout: the link carries the order id and the order's secret token, so a
     * customer without a login can download. Verifies the token, that the order is paid and contains this
     * download, and the expiry / per-order limit.
     */
    public function canDownloadViaToken(int $orderID, string $token): bool
    {
        if (!$this->tokenGrantsOrder($orderID, $token)) {
            return false;
        }

        $order = Order::get()->byID($orderID);

        return !$this->hasExpiredFor($order) && !$this->limitReachedFor($order);
    }

    /**
     * Does this order id + token authorise this download — a paid order carrying the matching secret token and
     * containing the download's product/variation? This is the entitlement check, before limit/expiry.
     */
    public function tokenGrantsOrder(int $orderID, string $token): bool
    {
        if (!$this->FileID || (!$this->ProductID && !$this->VariationID) || $token === '') {
            return false;
        }

        $order = Order::get()->byID($orderID);
        if (!$order || !$order->Paid || !$order->DownloadToken || !hash_equals((string) $order->DownloadToken, $token)) {
            return false;
        }

        return $this->orderContainsThis($order);
    }

    /**
     * The gated download URL for a specific order, carrying its token (guest-friendly).
     */
    public function tokenLink(Order $order): string
    {
        return DownloadController::singleton()->Link('process/' . $this->ID)
            . '?order=' . (int) $order->ID . '&token=' . urlencode((string) $order->DownloadToken);
    }

    private function orderContainsThis(Order $order): bool
    {
        if ($this->VariationID) {
            return VariationOrderItem::get()
                ->filter(['ProductVariationID' => $this->VariationID, 'OrderID' => $order->ID])
                ->exists();
        }
        if ($this->ProductID) {
            return ProductOrderItem::get()
                ->filter(['ProductID' => $this->ProductID, 'OrderID' => $order->ID])
                ->exists();
        }

        return false;
    }

    /**
     * The product this download belongs to (directly, or via its variation).
     */
    public function owningProduct(): ?Product
    {
        if ($this->ProductID) {
            return $this->Product();
        }
        if ($this->VariationID && ($variation = $this->Variation()) && $variation->exists()) {
            return $variation->Product();
        }

        return null;
    }

    /**
     * Effective per-customer download limit: the product's override if enabled, otherwise the shop-wide default
     * (SiteConfig → Shop → Downloads). 0 = unlimited.
     */
    public function effectiveDownloadLimit(): int
    {
        $product = $this->owningProduct();
        if ($product && $product->OverrideDownloadSettings) {
            return max(0, (int) $product->DownloadLimit);
        }

        return max(0, (int) SiteConfig::current_site_config()->DownloadLimit);
    }

    /**
     * Effective expiry window in days after the order was paid: the product's override if enabled, otherwise the
     * shop-wide default. 0 = never expires.
     */
    public function effectiveExpiryDays(): int
    {
        $product = $this->owningProduct();
        if ($product && $product->OverrideDownloadSettings) {
            return max(0, (int) $product->DownloadExpiryDays);
        }

        return max(0, (int) SiteConfig::current_site_config()->DownloadExpiryDays);
    }
}
