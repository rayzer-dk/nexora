<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Admin\Application\SystemLogDigest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SystemLogDigestTest extends TestCase
{
    private string $dir;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexora-log-digest-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/var/log', 0777, true);
        $this->pool = new ArrayAdapter();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/var/log/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/var/log');
        rmdir($this->dir . '/var');
        rmdir($this->dir);
    }

    public function testRepeatedErrorsAreGroupedWithTheirCountAndTheOriginalFileStaysIntact(): void
    {
        $path = $this->write('prod.log', [
            '[2026-11-20T10:00:00.100000+00:00] request.ERROR: Order 101 failed for customer 7 {"order":101} []',
            '[2026-11-20T10:00:01.200000+00:00] app.WARNING: Slow query 842 ms {"ms":842} []',
            '[2026-11-20T10:00:02.300000+00:00] request.ERROR: Order 102 failed for customer 9 {"order":102} []',
            '[2026-11-20T10:00:03.400000+00:00] request.ERROR: Payment gateway timeout {} []',
            '[2026-11-20T10:00:04.500000+00:00] request.ERROR: Order 103 failed for customer 7 {"order":103} []',
        ]);
        $before = sha1_file($path);

        $digest = $this->digest()->group($path);

        self::assertSame(5, $digest['entries']);
        self::assertSame(
            ['Order 103 failed for customer 7', 'Payment gateway timeout', 'Slow query 842 ms'],
            array_column($digest['groups'], 'message'),
            'Newest group first: a group is placed by its latest occurrence.',
        );
        [$order, $payment] = $digest['groups'];
        self::assertSame('request.ERROR', $order['type']);
        self::assertSame(3, $order['count']);
        self::assertSame('2026-11-20T10:00:04.500000+00:00', $order['last']);
        self::assertSame('2026-11-20T10:00:00.100000+00:00', $order['first']);
        self::assertSame(1, $payment['count']);
        self::assertSame($before, sha1_file($path));
    }

    public function testTheLevelAndSearchFiltersMatchThePlainJournal(): void
    {
        $path = $this->write('prod.log', [
            '[2026-11-20T10:00:00+00:00] app.ERROR: Mail failed {} []',
            '[2026-11-20T10:00:01+00:00] app.WARNING: Cache is cold {} []',
            '[2026-11-20T10:00:02+00:00] app.INFO: Cron started {} []',
        ]);

        self::assertCount(1, $this->digest()->group($path, 'error')['groups']);
        self::assertCount(2, $this->digest()->group($path, 'warning')['groups']);
        self::assertCount(3, $this->digest()->group($path, 'all')['groups']);
        self::assertSame(['Cache is cold'], array_column($this->digest()->group($path, 'all', 'cold')['groups'], 'message'));
    }

    public function testAPhpFatalErrorKeepsItsFileAndLineAndStackTraceLinesAreNotEntries(): void
    {
        $path = $this->write('php.log', [
            '[20-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined method Foo::bar() in /var/www/src/Foo.php:42',
            'Stack trace:',
            '#0 /var/www/src/Bar.php(7): Foo->run()',
            '#1 {main}',
            '  thrown in /var/www/src/Foo.php on line 42',
            '[20-Nov-2026 10:05:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined method Foo::bar() in /var/www/src/Foo.php:42',
        ]);

        $digest = $this->digest()->group($path);

        self::assertSame(2, $digest['entries']);
        self::assertCount(1, $digest['groups']);
        self::assertSame('PHP Fatal error', $digest['groups'][0]['type']);
        self::assertSame('/var/www/src/Foo.php', $digest['groups'][0]['file']);
        self::assertSame(42, $digest['groups'][0]['line']);
        self::assertSame(2, $digest['groups'][0]['count']);
    }

    public function testTheSameMessageFromAnotherLineOfCodeIsAnotherError(): void
    {
        $path = $this->write('php.log', [
            '[20-Nov-2026 10:00:00 UTC] PHP Warning:  Undefined variable $a in /var/www/src/Foo.php on line 10',
            '[20-Nov-2026 10:00:01 UTC] PHP Warning:  Undefined variable $a in /var/www/src/Foo.php on line 11',
        ]);

        self::assertCount(2, $this->digest()->group($path)['groups']);
    }

    public function testOnlyTheLastWindowOfALargeFileIsScanned(): void
    {
        $path = $this->dir . '/var/log/big.log';
        $old = "[2026-01-01T00:00:00+00:00] app.ERROR: Ancient failure {} []\n";
        $filler = "[2026-06-01T00:00:00+00:00] app.ERROR: Repeating failure {} []\n";
        file_put_contents($path, $old . str_repeat($filler, (int) ceil(SystemLogDigest::SCAN_BYTES / strlen($filler)) + 10));

        $digest = $this->digest()->group($path, 'error');

        self::assertTrue($digest['partial']);
        self::assertSame(SystemLogDigest::SCAN_BYTES, $digest['scanned']);
        self::assertSame(['Repeating failure'], array_column($digest['groups'], 'message'));
        self::assertGreaterThan(100_000, $digest['groups'][0]['count']);
    }

    public function testTheHeaderCounterCountsUniqueErrorsOfEveryFileAndStartsOverAfterTheLogIsCleared(): void
    {
        $digest = $this->digest();
        $prod = $this->write('prod.log', [
            '[2026-11-20T10:00:00+00:00] app.ERROR: Mail failed {} []',
            '[2026-11-20T10:00:01+00:00] app.ERROR: Mail failed {} []',
            '[2026-11-20T10:00:02+00:00] app.WARNING: Cache is cold {} []',
        ]);
        $this->write('update.log', ['[2026-11-20T10:00:03+00:00] app.CRITICAL: Migration failed {} []']);
        self::assertSame(2, $digest->uniqueErrorCount());

        file_put_contents($prod, '', LOCK_EX);
        $digest->forget('prod.log');
        self::assertSame(1, $digest->uniqueErrorCount());

        file_put_contents($prod, "[2026-11-21T08:00:00+00:00] app.ERROR: Mail failed {} []\n", LOCK_EX);
        self::assertSame(2, $digest->uniqueErrorCount(), 'An error that happens again after the cleanup is shown again.');
    }

    public function testTheCounterIsNotRecomputedWhileTheLogGrowsWithinTheCacheLifetime(): void
    {
        $digest = $this->digest();
        $path = $this->write('prod.log', ['[2026-11-20T10:00:00+00:00] app.ERROR: First {} []']);
        self::assertSame(1, $digest->uniqueErrorCount());

        file_put_contents($path, "[2026-11-20T10:00:01+00:00] app.ERROR: Second {} []\n", FILE_APPEND);

        self::assertSame(1, $digest->uniqueErrorCount());
    }

    public function testTheCounterNoticesATruncatedFileWithoutWaitingForTheCacheToExpire(): void
    {
        $digest = $this->digest();
        $path = $this->write('prod.log', [
            '[2026-11-20T10:00:00+00:00] app.ERROR: First {} []',
            '[2026-11-20T10:00:01+00:00] app.ERROR: Second {} []',
        ]);
        self::assertSame(2, $digest->uniqueErrorCount());

        file_put_contents($path, "[2026-11-21T10:00:00+00:00] app.ERROR: Third {} []\n", LOCK_EX);

        self::assertSame(1, $digest->uniqueErrorCount());
    }

    public function testAHostileLongLineIsParsedInBoundedTimeAndControlCharactersAreNeutralised(): void
    {
        $path = $this->write('prod.log', [
            '[2026-11-20T10:00:00+00:00] request.ERROR: ' . str_repeat('/a', 4000) . " bad\x1b[2J\rpaste {} []",
        ]);

        $started = microtime(true);
        $digest = $this->digest()->group($path);

        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertCount(1, $digest['groups']);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $digest['groups'][0]['message']);
        self::assertSame('', $digest['groups'][0]['file']);
    }

    private function digest(): SystemLogDigest
    {
        return new SystemLogDigest($this->pool, $this->dir);
    }

    /** @param list<string> $lines */
    private function write(string $name, array $lines): string
    {
        $path = $this->dir . '/var/log/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }
}
