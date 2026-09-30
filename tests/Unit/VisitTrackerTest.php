<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Analytics\Visit\VisitTracker;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VisitTrackerTest extends TestCase
{
    private const HUMAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

    private function tracker(): VisitTracker
    {
        return new VisitTracker(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), 'secret');
    }

    private function request(string $uri, string $ua = self::HUMAN, string $method = 'GET'): Request
    {
        return Request::create($uri, $method, [], [], [], ['HTTP_USER_AGENT' => $ua, 'HTTP_HOST' => 'shop.test']);
    }

    public function testOnlyHumanHtmlPagesAreCounted(): void
    {
        $t = $this->tracker();
        self::assertTrue($t->countable($this->request('/laptops'), 200, 'text/html; charset=UTF-8'));
        self::assertFalse($t->countable($this->request('/laptops', 'Googlebot/2.1'), 200, 'text/html'));
        self::assertFalse($t->countable($this->request('/laptops', 'HeadlessChrome'), 200, 'text/html'));
        self::assertFalse($t->countable($this->request('/laptops', ''), 200, 'text/html'));
        self::assertFalse($t->countable($this->request('/laptops'), 404, 'text/html'));
        self::assertFalse($t->countable($this->request('/laptops'), 302, 'text/html'));
        self::assertFalse($t->countable($this->request('/api/storefront/search/suggest'), 200, 'application/json'));
        self::assertFalse($t->countable($this->request('/admin'), 200, 'text/html'));
        self::assertFalse($t->countable($this->request('/account/orders'), 200, 'text/html'));
        self::assertFalse($t->countable($this->request('/cart/add', self::HUMAN, 'POST')->duplicate(), 500, 'text/html'));
    }

    public function testCartAddAndOrderSuccessAreEvents(): void
    {
        $t = $this->tracker();
        self::assertTrue($t->countable($this->request('/cart/add', self::HUMAN, 'POST'), 200, 'application/json'));
        self::assertTrue($t->countable($this->request('/checkout/success/abc'), 200, 'text/html'));
    }

    public function testPrefetchIsIgnored(): void
    {
        $r = $this->request('/laptops');
        $r->headers->set('Sec-Purpose', 'prefetch');
        self::assertFalse($this->tracker()->countable($r, 200, 'text/html'));
    }

    #[DataProvider('sources')]
    public function testAttribution(string $uri, ?string $referer, string $source, string $medium): void
    {
        $r = $this->request($uri);
        if ($referer !== null) {
            $r->headers->set('Referer', $referer);
        }
        $m = new \ReflectionMethod(VisitTracker::class, 'attribution');
        [$s, $med] = $m->invoke($this->tracker(), $r);
        self::assertSame([$source, $medium], [$s, $med]);
    }

    /** @return iterable<string,array{string,?string,string,string}> */
    public static function sources(): iterable
    {
        yield 'direct' => ['/', null, 'direct', 'none'];
        yield 'own site is direct' => ['/', 'https://shop.test/catalog', 'direct', 'none'];
        yield 'search' => ['/', 'https://www.google.com/', 'google', 'organic'];
        yield 'social' => ['/', 'https://l.facebook.com/', 'facebook', 'social'];
        yield 'referral' => ['/', 'https://blog.example.org/post', 'blog.example.org', 'referral'];
        yield 'utm' => ['/?utm_source=News&utm_medium=email&utm_campaign=sep', null, 'news', 'email'];
        yield 'gclid' => ['/?gclid=abc', null, 'google', 'cpc'];
    }
}
