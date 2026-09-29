<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;

/**
 * Adds downloadable-file support to {@link \SilverShop\Page\Product}: an "IsDigital" flag and a set of
 * {@link Download} records managed on a dedicated Downloads tab.
 *
 * @property bool $IsDigital
 * @extends Extension<\SilverShop\Page\Product>
 */
class ProductDownloadExtension extends Extension
{
    private static array $db = [
        'IsDigital' => 'Boolean',
    ];

    private static array $has_many = [
        'Downloads' => Download::class . '.Product',
    ];

    private static array $owns = ['Downloads'];

    private static array $cascade_deletes = ['Downloads'];

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        // The has_many auto-scaffolds a Downloads tab on *every* product. We only want it on digital ones, and
        // managed ourselves, so drop the scaffolded version first (this also removes its empty tab).
        $fields->removeByName('Downloads');

        $digitalField = CheckboxField::create(
            'IsDigital',
            _t(self::class . '.IsDigital', 'Digital product (delivered as a download)')
        );

        // Reveal the Downloads tab only once the product is marked digital and saved (mirrors WooCommerce's
        // "Downloadable" reveal), so physical products stay uncluttered.
        if ($owner->isInDB() && $owner->IsDigital) {
            $fields->findOrMakeTab('Root.Downloads')->setTitle(_t(self::class . '.Downloads', 'Downloads'));
            $fields->addFieldToTab('Root.Downloads', GridField::create(
                'Downloads',
                _t(self::class . '.Downloads', 'Downloads'),
                $owner->Downloads(),
                GridFieldConfig_RecordEditor::create()
            ));

            // A digital product doesn't ship and isn't stock-tracked, so drop those tabs (Shipping is core's,
            // Stock belongs to the optional silvershop/stock module — removeByName is a no-op if absent).
            $fields->removeByName(['Shipping', 'Stock']);
        } else {
            $digitalField->setDescription(
                _t(self::class . '.IsDigitalHint', 'Tick and save to add downloadable files (a Downloads tab appears).')
            );
        }

        $fields->addFieldToTab('Root.Main', $digitalField, 'Content');
    }

    /**
     * A digital product doesn't ship, so zero its weight/dimensions. Core's weight-based shipping modifier
     * returns 0 for a zero-weight order, and a digital line then adds nothing to a mixed order's shipping.
     * (Flat-rate/zone shipping ignores weight, so a shop using those still needs its shipping module to skip
     * digital orders — see isDigital().)
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if ($owner->IsDigital) {
            $owner->Weight = 0;
            $owner->Height = 0;
            $owner->Width = 0;
            $owner->Depth = 0;
        }
    }

    /**
     * A digital product is always in stock and needs no stock records. silvershop/stock fires this hook from
     * {@link \SilverShop\Stock\Extensions\ProductStockExtension::hasAvailableStock()}, letting us report the
     * product available without writing any ProductWarehouseStock row. No-op when stock isn't installed.
     */
    public function updateHasAvailableStock(&$available, int $require = 1): void
    {
        if ($this->getOwner()->IsDigital) {
            $available = true;
        }
    }

    /**
     * Is this a digital product (delivered as a download rather than shipped)? A shipping module can read this to
     * skip shipping for the line/order (weight is already zeroed above, which covers weight-based shipping).
     */
    public function isDigital(): bool
    {
        return (bool) $this->getOwner()->getField('IsDigital');
    }
}
