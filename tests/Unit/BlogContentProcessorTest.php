<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Content\Application\BlogContentProcessor;
use PHPUnit\Framework\TestCase;

final class BlogContentProcessorTest extends TestCase
{
    public function testReadingTimeRoundsUpAndHasFloorOfOneMinute(): void
    {
        self::assertSame(1, BlogContentProcessor::readingMinutes('<p>Short.</p>'));
        self::assertSame(1, BlogContentProcessor::readingMinutes(''));
        self::assertSame(2, BlogContentProcessor::readingMinutes('<p>' . str_repeat('слово ', 201) . '</p>'));
    }

    public function testExcerptCutsOnWordBoundaryWithEllipsis(): void
    {
        $excerpt = BlogContentProcessor::excerpt('<p>' . str_repeat('alpha ', 60) . '</p>', 50);
        self::assertLessThanOrEqual(51, mb_strlen($excerpt, 'UTF-8'));
        self::assertStringEndsWith('…', $excerpt);
        self::assertStringNotContainsString('alph…', $excerpt);
        self::assertSame('Short text', BlogContentProcessor::excerpt('<p>Short <b>text</b></p>'));
    }

    public function testHeadingsGetUniqueAnchorsAndTableOfContents(): void
    {
        $result = BlogContentProcessor::withAnchors('<h2>Вступ</h2><p>a</p><h2>Вступ</h2><h3>Деталі &amp; ціни</h3><p>b</p>');
        self::assertSame(['вступ', 'вступ-2', 'деталі-ціни'], array_column($result['toc'], 'id'));
        self::assertSame([2, 2, 3], array_column($result['toc'], 'level'));
        self::assertStringContainsString('<h2 id="вступ">Вступ</h2>', $result['html']);
        self::assertStringContainsString('<h3 id="деталі-ціни">', $result['html']);
        self::assertSame(['html' => '', 'toc' => []], BlogContentProcessor::withAnchors('  '));
    }

    public function testHtmlIsPreservedWithoutWrapperOrEncodingDamage(): void
    {
        $html = '<p>Привіт, <a href="/x?a=1&amp;b=2">світ</a></p><ul><li>one</li></ul>';
        $result = BlogContentProcessor::withAnchors($html);
        self::assertSame($html, $result['html']);
        self::assertSame([], $result['toc']);
    }
}
