<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Tax\Application\TaxSettingsService;
use PHPUnit\Framework\TestCase;

final class TaxClassNameTest extends TestCase
{
    public function testSeededNamesFollowTheInterfaceLanguage(): void
    {
        CanonicalUiText::useLocale('uk-UA');
        self::assertSame('Стандартний ПДВ', TaxSettingsService::className('standard', 'Standard VAT'));
        CanonicalUiText::useLocale('en-US');
        self::assertSame('Reduced VAT', TaxSettingsService::className('reduced', 'Reduced VAT'));
    }

    public function testMerchantRenamedClassKeepsItsName(): void
    {
        CanonicalUiText::useLocale('uk-UA');
        self::assertSame('Мій пільговий', TaxSettingsService::className('reduced', 'Мій пільговий'));
        self::assertSame('Custom class', TaxSettingsService::className('custom', 'Custom class'));
    }
}
