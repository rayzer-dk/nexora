<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\AdminInterfaceLocale;
use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Core\I18n\StorefrontUiTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class UiTextLocaleTest extends TestCase
{
    private function extension(Request $request): StorefrontUiTwigExtension
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new StorefrontUiTwigExtension(new StorefrontUiTranslator(dirname(__DIR__, 2)), $stack);
    }

    public function testAdminLabelsIgnoreTheContentLanguageVariable(): void
    {
        $request = Request::create('/admin/catalog/units');
        $request->attributes->set(AdminInterfaceLocale::REQUEST_ATTRIBUTE, 'uk-UA');
        $ui = $this->extension($request);

        self::assertSame('Назва', $ui->text(['locale' => 'en-US'], 'admin.units.name'));
        // an e-mail previewed in the admin names its language explicitly
        self::assertSame('Name', $ui->text(['locale' => 'en-US', 'ui_locale' => 'en-US'], 'admin.units.name'));
    }

    public function testOutsideTheAdminTheTemplateLocaleIsUsed(): void
    {
        $request = Request::create('/');
        $request->setLocale('uk-UA');

        self::assertSame('Name', $this->extension($request)->text(['locale' => 'en-US'], 'admin.units.name'));
    }
}
