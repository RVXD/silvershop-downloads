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

        $fields->addFieldToTab('Root.Main', CheckboxField::create(
            'IsDigital',
            _t(self::class . '.IsDigital', 'Digital product (delivered as a download)')
        ), 'Content');

        // Downloads need the product to exist first (the has_many relation is keyed on its ID).
        if ($owner->isInDB()) {
            $tab = $fields->findOrMakeTab('Root.Downloads');
            $tab->setTitle(_t(self::class . '.Downloads', 'Downloads'));
            $fields->addFieldToTab('Root.Downloads', GridField::create(
                'Downloads',
                _t(self::class . '.Downloads', 'Downloads'),
                $owner->Downloads(),
                GridFieldConfig_RecordEditor::create()
            ));
        }
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
