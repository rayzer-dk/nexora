<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Admin\Help\HelpCatalog;
use Commerce\Modules\Admin\Help\MarkdownLite;
use PHPUnit\Framework\TestCase;

final class MarkdownLiteTest extends TestCase
{
    public function testMarkupInTheSourceIsEscaped(): void
    {
        $html = (new MarkdownLite())->render("# Title <script>alert(1)</script>\n\nText <img src=x onerror=alert(1)> and [bad](javascript:alert(1)) and [ok](https://example.com/a)");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<a href="https://example.com/a"', $html);
        self::assertStringContainsString('<h2>Title &lt;script&gt;', $html);
    }

    public function testListsTablesAndCodeAreRendered(): void
    {
        $html = (new MarkdownLite())->render("1. one\n2. two\n\n- a\n- b\n\n| A | B |\n| --- | --- |\n| 1 | `x<y` |\n\n    php bin/console list\n\n```\n<b>raw</b>\n```");

        self::assertStringContainsString('<ol><li>one</li><li>two</li></ol>', $html);
        self::assertStringContainsString('<ul><li>a</li><li>b</li></ul>', $html);
        self::assertStringContainsString('<th>A</th>', $html);
        self::assertStringContainsString('<code>x&lt;y</code>', $html);
        self::assertStringContainsString('<pre><code>php bin/console list</code></pre>', $html);
        self::assertStringContainsString('&lt;b&gt;raw&lt;/b&gt;', $html);
    }

    public function testCyrillicTextSurvivesIntact(): void
    {
        // \R and \s without the u modifier split UTF-8 letters such as «х» (D1 85) at the byte 0x85.
        $text = "Ця сторінка без технічних знань.\nДруга хвиля: **ключ** і `код`.\n\n1. Хід\n2. Їжа";
        $html = (new MarkdownLite())->render($text);

        self::assertStringNotContainsString("\u{FFFD}", $html);
        self::assertStringContainsString('Ця сторінка без технічних знань. Друга хвиля: <strong>ключ</strong> і <code>код</code>.', $html);
        self::assertStringContainsString('<li>Хід</li><li>Їжа</li>', $html);
    }

    public function testLinksBetweenDocumentsUseTheHelpCatalogue(): void
    {
        $catalog = new HelpCatalog(dirname(__DIR__, 2));
        $html = (new MarkdownLite($catalog->urlForFile(...)))->render('See [slots](EXTENSION_SLOTS.md) and [secret](../.env.md).');

        self::assertStringContainsString('<a href="/admin/help/slots">slots</a>', $html);
        self::assertStringNotContainsString('<a href="../.env.md"', $html);
    }

    public function testEveryCatalogueDocumentExistsInTheDistribution(): void
    {
        $catalog = new HelpCatalog(dirname(__DIR__, 2));

        self::assertGreaterThanOrEqual(12, count($catalog->all()));
        self::assertNull($catalog->find('..%2F..%2Fetc'));
    }
}
