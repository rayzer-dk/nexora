<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Scheduler\CronCommandHint;
use Commerce\Core\Scheduler\CronSettings;
use Commerce\Tests\Unit\Support\ArraySystemSettingStore;
use PHPUnit\Framework\TestCase;

final class CronSchedulerTest extends TestCase
{
    public function testCrontabLineUsesAbsolutePathsAndSilencesOutput(): void
    {
        $hint = new CronCommandHint('/var/www/my shop');
        $line = $hint->line();
        self::assertStringStartsWith('*/5 * * * * ', $line);
        self::assertStringEndsWith(' >/dev/null 2>&1', $line);
        self::assertStringContainsString("'/var/www/my shop/bin/console' commerce:cron:run", $line);
        self::assertMatchesRegularExpression('#^\*/5 \* \* \* \* /\S+/php\S* #', $line);
        self::assertStringNotContainsString('fpm', $hint->phpBinary());
    }

    public function testPlainPathsStayUnquotedAndPanelCommandHasNoSchedule(): void
    {
        $hint = new CronCommandHint('/home/site');
        self::assertStringEndsWith('/home/site/bin/console commerce:cron:run', $hint->command());
        self::assertStringNotContainsString('*/5', $hint->command());
        self::assertSame('/home/site/bin/console', $hint->consolePath());
    }

    public function testWebLineQuotesUrlOnlyWhenNeeded(): void
    {
        $hint = new CronCommandHint('/x');
        self::assertSame('*/5 * * * * curl -fsS --max-time 60 https://shop.example/cron/abc >/dev/null 2>&1', $hint->webLine('https://shop.example/cron/abc'));
        self::assertStringContainsString("'https://shop.example/cron/a b&c'", $hint->webLine('https://shop.example/cron/a b&c'));
    }

    public function testTokenIsCreatedOnceCheckedInConstantTimeAndRegenerated(): void
    {
        $settings = new CronSettings($store = new ArraySystemSettingStore());
        $token = $settings->token();
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $token);
        self::assertSame($token, $settings->token());
        self::assertTrue($settings->tokenMatches($token));
        self::assertFalse($settings->tokenMatches(str_repeat('0', 40)));
        self::assertFalse($settings->tokenMatches('short'));
        $new = (new CronSettings($store))->regenerateToken();
        self::assertNotSame($token, $new);
        self::assertFalse((new CronSettings($store))->tokenMatches($token));
    }

    public function testPseudoCronIsOnByDefaultAndCanBeSwitchedOff(): void
    {
        $settings = new CronSettings($store = new ArraySystemSettingStore());
        self::assertTrue($settings->pseudoEnabled());
        $settings->setPseudoEnabled(false);
        self::assertFalse((new CronSettings($store))->pseudoEnabled());
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (new CronSettings($store))->token(), 'saving a flag keeps the token lazy and valid');
    }
}
