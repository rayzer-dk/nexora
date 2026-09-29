<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Runtime\IncidentWebhookNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IncidentWebhookNotifierTest extends TestCase
{
    public function testDisabledWithoutHttpsUrl(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) { ++$calls; return new MockResponse(''); });
        foreach (['', 'http://insecure.test/hook'] as $url) {
            $notifier = new IncidentWebhookNotifier($client, $url);
            self::assertFalse($notifier->enabled());
            $notifier->notify('r1', 'storefront', null, \RuntimeException::class, 'boom', 'static-safe-error');
        }
        self::assertSame(0, $calls);
    }

    public function testPostsMinimalPayloadAndSwallowsFailures(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, json_decode($options['body'], true)];
            return new MockResponse('', ['http_code' => 500]);
        });
        $notifier = new IncidentWebhookNotifier($client, 'https://hooks.example.test/x');
        $notifier->notify('abc', 'admin', 'admin_dashboard', \RuntimeException::class, "line1\nline2", 'json-safe-error');

        self::assertSame('POST', $captured[0]);
        self::assertSame('abc', $captured[2]['request_id']);
        self::assertSame('admin', $captured[2]['area']);
        self::assertArrayNotHasKey('trace', $captured[2]);
    }
}
