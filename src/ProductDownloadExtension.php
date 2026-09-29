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
     * Is this a digital product (delivered as a download rather than shipped)?
     *
     * Shipping modules can read this to skip weight/shipping for the line. Wiring it into the shipping
     * calculation needs a core hook and is intentionally left to the shipping layer.
     */
    public function isDigital(): bool
    {
        return (bool) $this->getOwner()->getField('IsDigital');
    }
}
