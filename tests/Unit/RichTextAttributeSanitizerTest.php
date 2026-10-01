<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Text\RichTextAttributeSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichTextAttributeSanitizerTest extends TestCase
{
    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())->allowSafeElements()->allowRelativeLinks()->allowRelativeMedias()
            ->allowElement('iframe', ['src', 'title', 'allowfullscreen', 'loading', 'class'])
            ->allowAttribute('style', ['span', 'p'])->allowAttribute('id', ['h2', 'p'])->allowAttribute('class', ['iframe'])
            ->withAttributeSanitizer(new RichTextAttributeSanitizer());

        return new HtmlSanitizer($config);
    }

    public function testColourAndSizeSurviveButDangerousStyleDoesNot(): void
    {
        $out = $this->sanitizer()->sanitize('<p><span style="color:#ff0000;font-size:20px;background:url(javascript:alert(1));position:fixed">x</span></p>');
        self::assertStringContainsString('color: #ff0000', $out);
        self::assertStringContainsString('font-size: 20px', $out);
        self::assertStringNotContainsString('url(', $out);
        self::assertStringNotContainsString('position', $out);
    }

    public function testAnchorsAndVideoEmbeds(): void
    {
        $out = $this->sanitizer()->sanitize('<h2 id="specs">T</h2><h2 id="bad id!">U</h2><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen></iframe><iframe src="https://evil.example/x"></iframe>');
        self::assertStringContainsString('id="specs"', $out);
        self::assertStringNotContainsString('bad id', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $out);
        self::assertStringNotContainsString('evil.example', $out);
    }
}
