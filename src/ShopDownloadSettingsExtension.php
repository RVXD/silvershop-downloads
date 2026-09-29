<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;

/**
 * Global download defaults, stored on SiteConfig and edited under Shop → Downloads: the per-customer download
 * limit and the expiry window applied to every digital product unless a product overrides them.
 *
 * @property int $DownloadLimit
 * @property int $DownloadExpiryDays
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class ShopDownloadSettingsExtension extends Extension
{
    private static array $db = [
        'DownloadLimit' => 'Int',
        'DownloadExpiryDays' => 'Int',
    ];

    // Both default to 0 — unlimited downloads, never expiring — matching the market norm of perpetual access.
    // Merchants set a limit/window explicitly when they want one.

    public function updateCMSFields(FieldList $fields): void
    {
        // Nest under SilverShop's central "Shop" settings tab, falling back to a top-level tab if the Shop tab
        // isn't present.
        $tab = $fields->fieldByName('Root.Shop') ? 'Root.Shop.ShopTabs.Downloads' : 'Root.Downloads';

        $fields->addFieldsToTab($tab, [
            NumericField::create('DownloadLimit', _t(self::class . '.DownloadLimit', 'Download limit per customer'))
                ->setDescription(_t(self::class . '.DownloadLimitDesc', '0 = unlimited.')),
            NumericField::create('DownloadExpiryDays', _t(self::class . '.DownloadExpiryDays', 'Download expiry (days after purchase)'))
                ->setDescription(_t(self::class . '.DownloadExpiryDaysDesc', '0 = never expires.')),
        ]);
        $fields->findOrMakeTab($tab)->setTitle(_t(self::class . '.TabDownloads', 'Downloads'));
    }
}
