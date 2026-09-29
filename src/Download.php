<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Order;
use SilverShop\Model\ProductOrderItem;
use SilverShop\Page\Product;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A downloadable file attached to a product. After a customer pays for an order containing the product, they may
 * download it (subject to a per-customer download limit and optional link expiry). The file is kept in the
 * protected asset store and only served through {@link DownloadController} — never a public URL.
 *
 * @property string $Title
 * @property int $SortOrder
 * @property int $FileID
 * @property int $ProductID
 * @method File File()
 * @method Product Product()
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
    ];

    private static array $owns = ['File'];

    private static string $default_sort = '"SortOrder" ASC, "Title" ASC';

    /** Max downloads per customer (0 = unlimited). */
    private static int $download_limit = 5;

    /** Days a download link stays valid after the order was paid (0 = never expires). */
    private static int $link_expiry_days = 0;

    private static array $summary_fields = [
        'Title' => 'Title',
        'File.Name' => 'File',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['SortOrder', 'ProductID']);

        $fields->replaceField('File', UploadField::create('File', _t(self::class . '.File', 'File'))
            ->setFolderName('downloads'));

        return $fields;
    }

    /**
     * Keep downloadable files out of the public asset store — they must only be reachable through the gated
     * controller.
     */
    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        if ($this->FileID && ($file = $this->File()) && $file->exists() && $file->isPublished()) {
            $file->protectFile();
        }
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
        if (!$member || !$this->FileID || !$this->ProductID) {
            return false;
        }

        $order = $this->grantingOrderFor($member);
        if (!$order) {
            return false;
        }

        $expiryDays = (int) static::config()->get('link_expiry_days');
        if ($expiryDays > 0 && $order->Paid && (strtotime((string) $order->Paid) + $expiryDays * 86400) < time()) {
            return false;
        }

        $limit = (int) static::config()->get('download_limit');
        if ($limit > 0) {
            $used = DownloadLog::get()->filter(['MemberID' => $member->ID, 'DownloadID' => $this->ID])->count();
            if ($used >= $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * The most recent paid order by this member that contains this download's product (grants access).
     */
    public function grantingOrderFor(Member $member): ?Order
    {
        $orderIds = ProductOrderItem::get()
            ->filter([
                'ProductID' => $this->ProductID,
                'Order.MemberID' => $member->ID,
                'Order.Status' => (array) Order::config()->get('placed_status'),
            ])
            ->column('OrderID');

        if (empty($orderIds)) {
            return null;
        }

        return Order::get()->filter('ID', array_map('intval', $orderIds))->sort('"Paid" DESC')->first();
    }
}
