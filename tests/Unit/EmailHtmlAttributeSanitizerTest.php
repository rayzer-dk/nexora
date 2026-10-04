<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Text\EmailHtmlAttributeSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class EmailHtmlAttributeSanitizerTest extends TestCase
{
    public function testLayoutAndTypographyStyleIsKept(): void
    {
        $style = EmailHtmlAttributeSanitizer::style('color: #112233; font-size: 14px; padding: 8px 16px; text-align: center; font-family: Arial, sans-serif');

        self::assertSame('color: #112233; font-size: 14px; padding: 8px 16px; text-align: center; font-family: Arial, sans-serif;', $style);
    }

    /** @return iterable<string,array{string}> */
    public static function dangerousStyles(): iterable
    {
        yield 'url()' => ['background-color: url(https://evil.example/x.png)'];
        yield 'expression' => ['width: expression(alert(1))'];
        yield 'import' => ['@import url(x)'];
        yield 'javascript in a value' => ['color: javascript:alert(1)'];
        yield 'position hijack' => ['position: fixed; top: 0'];
        yield 'unknown property' => ['behavior: url(x.htc)'];
    }

    #[DataProvider('dangerousStyles')]
    public function testDangerousStyleIsDropped(string $style): void
    {
        self::assertNull(EmailHtmlAttributeSanitizer::style($style));
    }

    public function testOnlyTheKnownTableAttributesPassWithValidValues(): void
    {
        $sanitizer = new EmailHtmlAttributeSanitizer();
        $config = new HtmlSanitizerConfig();

        self::assertSame('600', $sanitizer->sanitizeAttribute('table', 'width', '600', $config));
        self::assertNull($sanitizer->sanitizeAttribute('table', 'width', '600px; x', $config));
        self::assertSame('center', $sanitizer->sanitizeAttribute('td', 'align', 'center', $config));
        self::assertNull($sanitizer->sanitizeAttribute('td', 'align', 'javascript:1', $config));
        self::assertSame('#ffffff', $sanitizer->sanitizeAttribute('td', 'bgcolor', '#ffffff', $config));
        self::assertNull($sanitizer->sanitizeAttribute('a', 'onclick', 'alert(1)', $config));
        self::assertSame('presentation', $sanitizer->sanitizeAttribute('table', 'role', 'presentation', $config));
        self::assertNull($sanitizer->sanitizeAttribute('table', 'role', 'button', $config));
    }
}
