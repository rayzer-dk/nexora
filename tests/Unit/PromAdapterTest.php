<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Marketplace\Adapter\PromAdapter;
use Commerce\Modules\Marketplace\Application\MarketplaceSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PromAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['PROM_ENABLED'] = '1';
        $_ENV['PROM_API_TOKEN'] = 'unit-token';
        $_ENV['PROM_API_BASE'] = 'https://prom.test/api/v1';
    }

    protected function tearDown(): void
    {
        unset($_ENV['PROM_ENABLED'], $_ENV['PROM_API_TOKEN'], $_ENV['PROM_API_BASE']);
    }

    public function testOrdersAreReadWithTheBearerTokenAndOnlyOpenOnesKept(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['headers']];

            return new MockResponse(json_encode(['orders' => [
                ['id' => 5, 'status' => 'pending', 'client_first_name' => 'Ola', 'client_last_name' => 'K', 'phone' => '+380501234567', 'delivery_option' => ['name' => 'Nova Post'], 'delivery_address' => 'Kyiv 1',
                    'products' => [['sku' => 'A-1', 'external_id' => 'x', 'quantity' => 3, 'price' => '1 250,50 UAH']]],
                ['id' => 6, 'status' => 'canceled', 'products' => []],
            ]]));
        });
        $orders = (new PromAdapter($client))->newOrders('2026-10-10T00:00:00');

        self::assertSame('GET', $seen[0]);
        self::assertStringStartsWith('https://prom.test/api/v1/orders/list?', $seen[1]);
        self::assertContains('Authorization: Bearer unit-token', $seen[2]);
        self::assertCount(1, $orders);
        self::assertSame('5', $orders[0]->externalId);
        self::assertSame('Ola K', $orders[0]->name);
        self::assertSame('Nova Post, Kyiv 1', $orders[0]->delivery);
        self::assertSame(['sku' => 'A-1', 'external_id' => 'x', 'quantity' => '3', 'price' => '1250.50'], $orders[0]->lines[0]);
    }

    public function testStockGoesOutInChunksOfHundredWithPresence(): void
    {
        $bodies = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode($options['body'], true);

            return new MockResponse('{}');
        });
        $items = [];
        for ($i = 0; $i < 150; ++$i) {
            $items[] = ['external_id' => 'id' . $i, 'sku' => 's' . $i, 'quantity' => $i === 0 ? 0 : 2, 'price' => $i === 1 ? '9.90' : null];
        }
        (new PromAdapter($client))->pushStock($items);

        self::assertCount(2, $bodies);
        self::assertCount(100, $bodies[0]);
        self::assertSame('not_available', $bodies[0][0]['presence']);
        self::assertSame(9.9, $bodies[0][1]['price']);
        self::assertArrayNotHasKey('price', $bodies[0][2]);
    }

    public function testAnErrorFromPromIsRaised(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"bad token"}', ['http_code' => 401]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('401');
        (new PromAdapter($client))->pushStatus('5', 'canceled');
    }

    public function testShopStatusesAsMarketplacesUnderstandThem(): void
    {
        self::assertNull(MarketplaceSyncService::neutralStatus('placed', 'unfulfilled'));
        self::assertSame('confirmed', MarketplaceSyncService::neutralStatus('confirmed', 'pending'));
        self::assertSame('shipped', MarketplaceSyncService::neutralStatus('processing', 'shipped'));
        self::assertSame('delivered', MarketplaceSyncService::neutralStatus('completed', 'delivered'));
        self::assertSame('canceled', MarketplaceSyncService::neutralStatus('cancelled', 'cancelled'));
    }
}
