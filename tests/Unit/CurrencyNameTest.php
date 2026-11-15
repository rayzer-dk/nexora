<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\CurrencyName;
use PHPUnit\Framework\TestCase;

final class CurrencyNameTest extends TestCase
{
    public function testACurrencyIsNamedInTheLanguageOfTheReader(): void
    {
        self::assertSame('Долар США', CurrencyName::localized('USD', 'uk-UA'));
        self::assertSame('Украинская гривна', CurrencyName::localized('uah', 'ru-RU'));
        self::assertSame('Euro', CurrencyName::localized('EUR', 'en-US'));
    }

    public function testAnUnknownCurrencyKeepsItsStoredNameOrItsCode(): void
    {
        self::assertSame('Own coin', CurrencyName::localized('ZZZ', 'uk-UA', 'Own coin'));
        self::assertSame('ZZZ', CurrencyName::localized('ZZZ', 'uk-UA'));
    }
}
