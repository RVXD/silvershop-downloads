<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Order;
use SilverShop\Model\ProductOrderItem;
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
     * Downloads attached to products in this member's paid orders.
     */
    public function AvailableDownloads(): SS_List
    {
        $member = $this->getOwner();

        $productIds = ProductOrderItem::get()
            ->filter([
                'Order.MemberID' => $member->ID,
                'Order.Status' => (array) Order::config()->get('placed_status'),
            ])
            ->column('ProductID');

        if (empty($productIds)) {
            return ArrayList::create();
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        return Download::get()->filter('ProductID', $productIds);
    }
}
