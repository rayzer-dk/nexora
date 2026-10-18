<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Seo\Application\CustomRedirectService;
use Commerce\Modules\Seo\Application\SeoTemplateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomRedirectBotAgentTest extends TestCase
{
    /** @return iterable<string,array{string,bool}> */
    public static function agents(): iterable
    {
        yield 'empty' => ['', true];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0)', true];
        yield 'scanner from BotProtection list' => ['sqlmap/1.7', true];
        yield 'ai crawler from BotProtection list' => ['Mozilla/5.0 (compatible; GPTBot/1.0)', true];
        yield 'facebook preview' => ['facebookexternalhit/1.1', true];
        yield 'desktop chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', false];
        yield 'iphone safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1', false];
    }

    #[DataProvider('agents')]
    public function testBotFlagFollowsAgentWords(string $userAgent, bool $isBot): void
    {
        self::assertSame($isBot, CustomRedirectService::isBotUserAgent($userAgent));
    }

    public function testArticleAndPageTemplatesOfferOnlyNameAndStore(): void
    {
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('article'));
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('page'));
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('category'));
        self::assertSame(['name', 'store', 'price', 'brand', 'category', 'sku'], SeoTemplateService::variablesFor('product'));
    }
}
