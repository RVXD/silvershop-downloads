<?php

declare(strict_types=1);

namespace SilverShop\Downloads\Tests;

use SilverShop\Downloads\Download;
use SilverShop\Downloads\DownloadActivityReport;
use SilverShop\Downloads\DownloadLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * The download-activity report instantiates, has a title, runs its raw SQL cleanly on an empty DB (SQLite in CI),
 * and surfaces a logged download event with the right event/customer counts.
 */
class DownloadActivityReportTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testReportInstantiatesAndHasTitle(): void
    {
        $report = new DownloadActivityReport();
        $this->assertNotEmpty((string) $report->title());
    }

    public function testRunsCleanlyWithNoData(): void
    {
        $report = new DownloadActivityReport();
        $this->assertCount(0, $report->sourceRecords());
    }

    public function testDownloadLogSurfacesInReport(): void
    {
        $member = Member::create();
        $member->Email = 'buyer@example.com';
        $member->write();

        $download = Download::create();
        $download->Title = 'Ebook PDF';
        $download->write();

        // Two download events for the same file, by the same customer.
        foreach ([1, 2] as $ignored) {
            $log = DownloadLog::create();
            $log->DownloadID = $download->ID;
            $log->MemberID = $member->ID;
            $log->write();
        }

        $rows = (new DownloadActivityReport())->sourceRecords();
        $this->assertCount(1, $rows, 'one row per downloaded file');

        $row = $rows->first();
        $this->assertSame('Ebook PDF', $row->Title);
        $this->assertSame(2, (int) $row->Downloads, 'counts every download event');
        $this->assertSame(1, (int) $row->Customers, 'counts distinct customers');
    }
}
