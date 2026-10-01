<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\ProductVideoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductVideoServiceTest extends TestCase
{
    /** @return iterable<string,array{string,string|null,string|null}> */
    public static function links(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'youtube', 'dQw4w9WgXcQ'];
        yield 'youtube short' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ'];
        yield 'youtube shorts' => ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'youtube embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'bare id' => ['dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'no scheme' => ['youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871'];
        yield 'vimeo private hash' => ['https://vimeo.com/76979871/abcdef1234', 'vimeo', '76979871:abcdef1234'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=abcdef1234', 'vimeo', '76979871:abcdef1234'];
        yield 'mp4 file' => ['https://cdn.example.com/files/demo.mp4', 'file', null];
        yield 'webm with query' => ['https://cdn.example.com/a/b.webm?token=1', 'file', null];
        yield 'http file refused' => ['http://cdn.example.com/demo.mp4', null, null];
        yield 'credentials refused' => ['https://user:pass@cdn.example.com/demo.mp4', null, null];
        yield 'plain page' => ['https://example.com/page', null, null];
        yield 'bad youtube id' => ['https://www.youtube.com/watch?v=short', null, null];
        yield 'script' => ['javascript:alert(1)', null, null];
        yield 'spaces' => ['https://youtu.be/dQw4w9WgXcQ x', null, null];
        yield 'empty' => ['', null, null];
        yield 'lookalike host' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', null, null];
    }

    #[DataProvider('links')]
    public function testParse(string $input, ?string $provider, ?string $ref): void
    {
        $parsed = ProductVideoService::parse($input);
        if ($provider === null) {
            self::assertNull($parsed);

            return;
        }
        self::assertNotNull($parsed);
        self::assertSame($provider, $parsed['provider']);
        if ($ref !== null) {
            self::assertSame($ref, $parsed['ref']);
        }
    }

    public function testEmbedUrls(): void
    {
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1', ProductVideoService::embedUrl('youtube', 'dQw4w9WgXcQ', ''));
        self::assertSame('https://player.vimeo.com/video/76979871?h=abcdef1234', ProductVideoService::embedUrl('vimeo', '76979871:abcdef1234', ''));
        self::assertSame('https://player.vimeo.com/video/76979871', ProductVideoService::embedUrl('vimeo', '76979871', ''));
        self::assertSame('https://cdn.example.com/a.mp4', ProductVideoService::embedUrl('file', 'x', 'https://cdn.example.com/a.mp4'));
    }
}
