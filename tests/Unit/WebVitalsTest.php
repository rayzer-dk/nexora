<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Analytics\Vitals\WebVitals;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class WebVitalsTest extends TestCase
{
    private function vitals(): WebVitals
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_web_vital (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER, page_kind TEXT, device TEXT, metric TEXT, value REAL, created_at TEXT)');

        return new WebVitals($db);
    }

    public function testThePercentileIsTheNearestRank(): void
    {
        self::assertSame(3.0, WebVitals::percentile([1.0, 2.0, 3.0, 4.0], 0.75), "rank ceil(0.75 * 4) = 3");
        self::assertSame(7.0, WebVitals::percentile([9.0, 1.0, 7.0, 3.0, 5.0, 8.0, 2.0, 4.0], 0.75), 'unsorted input, 8 values: rank 6 of the sorted list');
        self::assertSame(5.0, WebVitals::percentile([5.0], 0.75));
    }

    public function testOnlyKnownPagesDevicesAndSaneValuesAreStored(): void
    {
        $vitals = $this->vitals();

        self::assertSame(3, $vitals->record(1, 'product', 'mobile', ['LCP' => 2100, 'CLS' => 0.04, 'INP' => 180]));
        self::assertSame(1, $vitals->record(1, 'catalog', 'desktop', ['LCP' => 1900, 'CLS' => -1, 'INP' => 'x', 'FID' => 5, 'TTFB' => 100000000]));
        self::assertSame(0, $vitals->record(1, '/etc/passwd', 'mobile', ['LCP' => 1000]), 'unknown page kind');
        self::assertSame(0, $vitals->record(1, 'home', 'watch', ['LCP' => 1000]), 'unknown device');
        self::assertSame(0, $vitals->record(1, 'home', 'mobile', ['LCP' => 99999999]), 'a value no browser can measure');
    }

    public function testTheReportRatesTheSeventyFifthPercentileAgainstGoogleThresholds(): void
    {
        $vitals = $this->vitals();
        foreach ([1800, 2000, 2200, 3900] as $lcp) {
            $vitals->record(1, 'product', 'mobile', ['LCP' => $lcp, 'CLS' => 0.02]);
        }
        $vitals->record(2, 'product', 'mobile', ['LCP' => 9000]);
        $report = array_column($vitals->report(1, 7), null, 'metric');

        self::assertSame(4, $report['LCP']['samples'], 'another store is not mixed in');
        self::assertSame(2200.0, $report['LCP']['p75']);
        self::assertSame('good', $report['LCP']['rating']);
        self::assertSame('good', $report['CLS']['rating']);
        $vitals->record(1, 'product', 'mobile', ['LCP' => 5000]);
        $vitals->record(1, 'product', 'mobile', ['LCP' => 5200]);
        self::assertSame('poor', array_column($vitals->report(1, 7), null, 'metric')['LCP']['rating']);
    }
}
