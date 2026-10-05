<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Seo;

use Commerce\Modules\Seo\Http\CanonicalHostSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CanonicalHostSubscriberTest extends TestCase
{
    private function target(string $public, string $uri, array $server = [], string $method = 'GET'): ?string
    {
        return (new CanonicalHostSubscriber($public))->target(Request::create($uri, $method, [], [], [], $server));
    }

    public function testWwwGoesToTheBareHost(): void
    {
        self::assertSame('https://example.com.ua/catalog?page=2', $this->target('https://example.com.ua', 'https://www.example.com.ua/catalog?page=2'));
    }

    public function testBareHostGoesToWwwWhenWwwIsCanonical(): void
    {
        self::assertSame('https://www.example.com.ua/', $this->target('https://www.example.com.ua', 'https://example.com.ua/'));
    }

    public function testPlainHttpGoesToHttps(): void
    {
        self::assertSame('https://example.com.ua/blog', $this->target('https://example.com.ua', 'http://example.com.ua/blog'));
    }

    public function testCanonicalAddressAndProxiedHttpsAreLeftAlone(): void
    {
        self::assertNull($this->target('https://example.com.ua', 'https://example.com.ua/blog'));
        self::assertNull($this->target('https://example.com.ua', 'http://example.com.ua/blog', ['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testLocalHostsPostsAndMachineEndpointsAreNeverRedirected(): void
    {
        self::assertNull($this->target('http://127.0.0.1:8000', 'http://127.0.0.1:8000/'));
        self::assertNull($this->target('https://shop.test', 'http://shop.test/'));
        self::assertNull($this->target('https://example.com.ua', 'http://www.example.com.ua/checkout', [], 'POST'));
        self::assertNull($this->target('https://example.com.ua', 'http://www.example.com.ua/webhooks/payment'));
        self::assertNull($this->target('https://example.com.ua', 'https://other-domain.com/'));
    }
}
