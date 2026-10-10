<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Reports\Report;

/**
 * Most-downloaded files ranked by the number of recorded download events ({@link DownloadLog}), for a period.
 * Each row is one download (file), with its event count and how many distinct customers fetched it. Logs whose
 * download has since been deleted are excluded.
 */
class DownloadActivityReport extends Report
{
    public function title()
    {
        return _t(__CLASS__ . '.TITLE', 'Download activity');
    }

    public function description()
    {
        return _t(__CLASS__ . '.DESC', 'Most-downloaded files by number of download events, for a period.');
    }

    public function group()
    {
        return _t('SilverShop\\Reports.GROUP', 'Shop');
    }

    public function sort()
    {
        return 330;
    }

    public function parameterFields()
    {
        return FieldList::create(
            DateField::create('StartDate', _t(__CLASS__ . '.Start', 'From (download date)')),
            DateField::create('EndDate', _t(__CLASS__ . '.End', 'To (download date)'))
        );
    }

    public function sourceRecords($params = null)
    {
        $query = SQLSelect::create();
        $query->setSelect([
            'Title' => '"d"."Title"',
            'Downloads' => 'COUNT("dl"."ID")',
            'Customers' => 'COUNT(DISTINCT "dl"."MemberID")',
        ]);
        $query->setFrom('"SilverShop_DownloadLog" AS "dl"');
        $query->addInnerJoin('SilverShop_Download', '"d"."ID" = "dl"."DownloadID"', 'd');

        if (!empty($params['StartDate'])) {
            $query->addWhere(['"dl"."Created" >= ?' => $params['StartDate'] . ' 00:00:00']);
        }
        if (!empty($params['EndDate'])) {
            $query->addWhere(['"dl"."Created" <= ?' => $params['EndDate'] . ' 23:59:59']);
        }

        $query->setGroupBy(['"d"."ID"', '"d"."Title"']);
        $query->setOrderBy('COUNT("dl"."ID")', 'DESC');

        $list = ArrayList::create();
        foreach ($query->execute() as $row) {
            $list->push(ArrayData::create([
                'Title' => $row['Title'],
                'Downloads' => (int) $row['Downloads'],
                'Customers' => (int) $row['Customers'],
            ]));
        }

        return $list;
    }

    public function columns()
    {
        return [
            'Title' => _t(__CLASS__ . '.ColTitle', 'Download'),
            'Downloads' => _t(__CLASS__ . '.ColDownloads', 'Downloads'),
            'Customers' => _t(__CLASS__ . '.ColCustomers', 'Customers'),
        ];
    }
}
