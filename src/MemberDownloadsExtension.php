<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Product\OrderItem as ProductOrderItem;
use SilverShop\Model\Variation\OrderItem as VariationOrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;

/**
 * Gives a member the set of downloads they've bought, so account templates can list them
 * (e.g. a "My downloads" section).
 *
 * @extends Extension<\SilverStripe\Security\Member>
 */
class MemberDownloadsExtension extends Extension
{
    /**
     * Downloads this member has bought: product-wide downloads for any product in their paid orders, plus
     * variation-specific downloads for the variations they bought.
     */
    public function AvailableDownloads(): SS_List
    {
        $member = $this->getOwner();

        $productIds = ProductOrderItem::get()
            ->filter(['Order.MemberID' => $member->ID, 'Order.Paid:not' => null])
            ->column('ProductID');

        $variationIds = VariationOrderItem::get()
            ->filter(['Order.MemberID' => $member->ID, 'Order.Paid:not' => null])
            ->column('ProductVariationID');

        $downloadIds = [];
        if (!empty($productIds)) {
            $downloadIds = array_merge($downloadIds, Download::get()
                ->filter('ProductID', array_values(array_unique(array_map('intval', $productIds))))
                ->column('ID'));
        }
        if (!empty($variationIds)) {
            $downloadIds = array_merge($downloadIds, Download::get()
                ->filter('VariationID', array_values(array_unique(array_map('intval', $variationIds))))
                ->column('ID'));
        }

        $downloadIds = array_values(array_unique(array_map('intval', $downloadIds)));
        if (empty($downloadIds)) {
            return ArrayList::create();
        }

        return Download::get()->filter('ID', $downloadIds);
    }
}
