<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Order;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * One row per file-download event — used to enforce the per-customer download limit and to give an audit trail.
 *
 * @property string $IPAddress
 */
class DownloadLog extends DataObject
{
    private static string $table_name = 'SilverShop_DownloadLog';

    private static array $db = [
        'IPAddress' => 'Varchar(45)',
    ];

    private static array $has_one = [
        'Download' => Download::class,
        'Member' => Member::class,
        'Order' => Order::class,
    ];

    private static string $default_sort = '"Created" DESC';
}
