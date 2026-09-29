<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;

/**
 * Adds per-variation downloadable files to {@link \SilverShop\Model\Variation\Variation}. A file added here is
 * delivered only to customers who buy this specific variation; product-wide files live on the product itself and
 * reach buyers of any variation.
 *
 * @extends Extension<\SilverShop\Model\Variation\Variation>
 */
class VariationDownloadExtension extends Extension
{
    private static array $has_many = [
        'Downloads' => Download::class . '.Variation',
    ];

    private static array $owns = ['Downloads'];

    private static array $cascade_deletes = ['Downloads'];

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();
        $fields->removeByName('Downloads');

        // Only offer per-variation downloads on a saved variation of a digital product.
        if (!$owner->isInDB() || !$owner->Product()->exists() || !$owner->Product()->IsDigital) {
            return;
        }

        // A digital variation doesn't ship or track stock, so drop those fields (StockLevels is the stock
        // module's variation grid; removeByName is a no-op if a field is absent).
        $fields->removeByName(['Weight', 'Height', 'Width', 'Depth', 'StockLevels']);

        $fields->push(GridField::create(
            'Downloads',
            _t(ProductDownloadExtension::class . '.Downloads', 'Downloads'),
            $owner->Downloads(),
            GridFieldConfig_RecordEditor::create()
        ));
    }

    /**
     * A variation of a digital product doesn't ship — zero its weight so weight-based shipping charges nothing.
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if ($owner->ProductID && $owner->Product()->exists() && $owner->Product()->IsDigital) {
            $owner->Weight = 0;
        }
    }
}
