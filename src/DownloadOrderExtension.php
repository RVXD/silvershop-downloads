<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Product\OrderItem as ProductOrderItem;
use SilverShop\Model\Variation\OrderItem as VariationOrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Adds download delivery to {@link \SilverShop\Model\Order}: a per-order secret token (so download links work
 * for guest checkout, without a login) and helpers the order confirmation email uses to list the buyer's
 * downloads with tokenised links.
 *
 * @property string $DownloadToken
 * @extends Extension<\SilverShop\Model\Order>
 */
class DownloadOrderExtension extends Extension
{
    private static array $db = [
        'DownloadToken' => 'Varchar(64)',
    ];

    /**
     * Mint the download token once the order is paid and actually has downloadable items — this token authorises
     * guest downloads without a login.
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if ($owner->Paid && !$owner->DownloadToken && $this->hasDownloadableItems()) {
            $owner->DownloadToken = bin2hex(random_bytes(16));
        }
    }

    public function hasDownloadableItems(): bool
    {
        return $this->downloadsForOrder()->exists();
    }

    /**
     * The downloads for this order, each with a tokenised link, for the confirmation email / a receipt page.
     */
    public function DownloadLinksForOrder(): ArrayList
    {
        $owner = $this->getOwner();
        $list = ArrayList::create();
        if (!$owner->DownloadToken) {
            return $list;
        }

        foreach ($this->downloadsForOrder() as $download) {
            $list->push(ArrayData::create([
                'Title' => $download->Title,
                'Link' => $download->tokenLink($owner),
            ]));
        }

        return $list;
    }

    /**
     * Downloads attached to the products / variations in this order (product-wide files plus the specific
     * variation's files).
     *
     * @return \SilverStripe\ORM\DataList<Download>
     */
    private function downloadsForOrder()
    {
        $owner = $this->getOwner();

        $productIds = ProductOrderItem::get()->filter('OrderID', $owner->ID)->column('ProductID');
        $variationIds = VariationOrderItem::get()->filter('OrderID', $owner->ID)->column('ProductVariationID');

        return Download::get()->filterAny([
            'ProductID' => $productIds ? array_values(array_unique(array_map('intval', $productIds))) : [-1],
            'VariationID' => $variationIds ? array_values(array_unique(array_map('intval', $variationIds))) : [-1],
        ]);
    }
}
