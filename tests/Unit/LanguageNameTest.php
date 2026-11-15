<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\LanguageName;
use PHPUnit\Framework\TestCase;

final class LanguageNameTest extends TestCase
{
    public function testALanguageIsNamedInItsOwnWordsNotByItsCode(): void
    {
        self::assertSame('Українська', LanguageName::native('uk-UA'));
        self::assertSame('Русский', LanguageName::native('ru-RU'));
        self::assertSame('English', LanguageName::native('en-GB'));
        self::assertSame('Deutsch', LanguageName::native('de'));
        self::assertSame('xx-YY', LanguageName::native('xx-YY'), 'an unknown language keeps its code');
    }

    public function testAStoredNameLosesTheCountryAndAnEmptyOneFallsBackToIntl(): void
    {
        self::assertSame('English', LanguageName::plain('English (United States)', 'en-US'));
        self::assertSame('Polski', LanguageName::plain('', 'pl-PL'));
        self::assertSame('English, Deutsch', LanguageName::list(['en-US', 'de-DE']));
    }
}
