<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\NumericField;

/**
 * Global download defaults, stored on SiteConfig and edited under Shop → Downloads: the per-customer download
 * limit, the expiry window, and the text shown above the download links in the order confirmation email.
 *
 * @property int $DownloadLimit
 * @property int $DownloadExpiryDays
 * @property string $DownloadEmailText
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class ShopDownloadSettingsExtension extends Extension
{
    private static array $db = [
        'DownloadLimit' => 'Int',
        'DownloadExpiryDays' => 'Int',
        'DownloadEmailText' => 'HTMLText',
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
            HTMLEditorField::create('DownloadEmailText', _t(self::class . '.DownloadEmailText', 'Download email text'))
                ->setRows(4)
                ->setDescription(_t(
                    self::class . '.DownloadEmailTextDesc',
                    'Shown above the download links in the order confirmation email (for orders that contain '
                    . 'downloadable products).'
                )),
        ]);
        $fields->findOrMakeTab($tab)->setTitle(_t(self::class . '.TabDownloads', 'Downloads'));
    }
}
