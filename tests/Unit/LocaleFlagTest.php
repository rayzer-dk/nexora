<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\LocaleFlag;
use PHPUnit\Framework\TestCase;

final class LocaleFlagTest extends TestCase
{
    public function testRegionFollowsTheLocale(): void
    {
        self::assertSame('UA', LocaleFlag::region('uk-UA'));
        self::assertSame('US', LocaleFlag::region('en-GB'));
        self::assertSame('DE', LocaleFlag::region('de'));
        self::assertSame('BR', LocaleFlag::region('pt_BR'));
        self::assertSame('PL', LocaleFlag::region('PL'));
        self::assertSame('', LocaleFlag::region('xx'));
    }

    public function testEveryFlagIsAnImageWithoutMarkupOfTheSource(): void
    {
        $img = LocaleFlag::img('en-US');
        self::assertMatchesRegularExpression('#^<img class="flag" src="data:image/svg\+xml;base64,[A-Za-z0-9+/=]+" width="20" height="15" alt="" decoding="async">$#', $img);
        self::assertStringContainsString('viewBox="0 0 640 480"', base64_decode(explode(',', explode('"', $img)[3])[1], true) ?: '');
        self::assertSame('', LocaleFlag::img('xx'));
    }

    public function testFlagsWithCoatsOfArmsUseTheSimplifiedVersion(): void
    {
        foreach (['es-ES', 'pt-PT', 'sr-RS', 'hr-HR', 'kk-KZ', 'ro-MD'] as $locale) {
            $svg = base64_decode(explode(',', explode('"', LocaleFlag::img($locale))[3])[1], true);
            self::assertIsString($svg, $locale);
            self::assertLessThan(5000, strlen($svg), $locale);
        }
    }
}
