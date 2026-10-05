<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Forum;

use Commerce\Modules\Forum\Application\ForumFormatter;
use PHPUnit\Framework\TestCase;

final class ForumFormatterTest extends TestCase
{
    public function testMarksBecomeSafeHtml(): void
    {
        $html = (new ForumFormatter())->toHtml("**bold** and *it* and ~~old~~ and `x < y`\n\n> quoted\n\n- one\n- two\n\n1. first\n2. second");
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<em>it</em>', $html);
        self::assertStringContainsString('<s>old</s>', $html);
        self::assertStringContainsString('<code>x &lt; y</code>', $html);
        self::assertStringContainsString('<blockquote class="forum-quote"><p>quoted</p></blockquote>', $html);
        self::assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        self::assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
    }

    public function testNoMarkupOrScriptSurvives(): void
    {
        $html = (new ForumFormatter())->toHtml('<script>alert(1)</script> <img src=x onerror=alert(1)> [a](javascript:alert(1)) [b](https://example.com/"onmouseover="x)');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('" onmouseover', $html);
        self::assertStringNotContainsString('"onmouseover="', $html);
    }

    public function testLinksCodeBlocksAndMentions(): void
    {
        $html = (new ForumFormatter())->toHtml("see https://example.com/a?b=1&c=2, ok\n```\n<b>raw</b>\n```\nhi @anna and @ghost", ['anna' => '/forum/member/7']);
        self::assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2" rel="nofollow ugc noopener" target="_blank">https://example.com/a?b=1&amp;c=2</a>', $html);
        self::assertStringContainsString('<pre class="forum-code"><code>&lt;b&gt;raw&lt;/b&gt;</code></pre>', $html);
        self::assertStringContainsString('<a class="forum-mention" href="/forum/member/7">@anna</a>', $html);
        self::assertStringContainsString('@ghost', $html);
        self::assertStringNotContainsString('forum/member/7">@ghost', $html);
    }
}
