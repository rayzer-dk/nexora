<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Event\DomainEvent;
use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Api\Webhook\ApiWebhookEventSubscriber;
use Commerce\Modules\Order\Application\OrderManagementService;
use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Order cancellation, completion and payment status changes are published by the order and payment services and must
 * reach the Public API webhook subscriptions of the order's store. Nothing is sent over the network here: the
 * subscriber only queues deliveries in mc_webhook_delivery.
 */
final class ApiWebhookOrderEventsTest extends TestCase
{
    private const ORDER_ID = '7d3b8a52-1c4e-4f6a-9b2d-3e5f6a7b8c9d';
    private const STORE_ID = 3;

    /** @var list<DomainEvent> */
    private array $published = [];

    /** @return iterable<string,array{string,\Closure(self):void}> */
    public static function publishers(): iterable
    {
        yield 'order completed by admin' => ['commerce.order.completed', static fn (self $t) => $t->orders()->markCompleted(self::ORDER_ID, 'admin@test')];
        yield 'order cancelled by admin' => ['commerce.order.cancelled', static fn (self $t) => $t->payments(['status' => 'pending', 'payment_status' => 'pending'])->cancelOrder(self::ORDER_ID, 'admin@test')];
        yield 'unpaid order expired' => ['commerce.order.cancelled', static fn (self $t) => $t->payments(['status' => 'awaiting_payment', 'payment_status' => 'pending'])->expireUnpaidReservations(10)];
        yield 'payment confirmed by admin' => ['commerce.payment.status_changed', static fn (self $t) => $t->payments(['status' => 'pending', 'payment_status' => 'pending'])->markManualPaid(self::ORDER_ID, 'admin@test')];
        yield 'payment provider callback' => ['commerce.payment.status_changed', static fn (self $t) => $t->payments(['status' => 'awaiting_payment', 'payment_status' => 'pending'])->applyProviderStatus('liqpay', 'ref-1', 'success', null, 12345, 'UAH', null, [])];
    }

    /** @param \Closure(self):void $publish */
    #[DataProvider('publishers')]
    public function testEventAsPublishedByTheCoreIsQueuedForTheStoreSubscription(string $eventName, \Closure $publish): void
    {
        $publish($this);

        self::assertCount(1, $this->published);
        $event = $this->published[0];
        self::assertSame($eventName, $event->eventName());
        self::assertSame(self::STORE_ID, $event->payload()['store_id'] ?? null, 'The event names the store of the order.');

        $db = $this->webhookDb(withOrders: false);
        (new ApiWebhookEventSubscriber($db, new PublicIdFactory()))->handle($this->stored($event));

        $delivery = $db->fetchAssociative('SELECT store_id,event_type,payload FROM mc_webhook_delivery');
        self::assertIsArray($delivery, 'The subscription of the order store gets a delivery.');
        self::assertSame(self::STORE_ID, (int) $delivery['store_id']);
        self::assertSame($eventName, $delivery['event_type']);
        $body = json_decode((string) $delivery['payload'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(self::ORDER_ID, $body['aggregate']['id']);
        self::assertSame(self::STORE_ID, $body['data']['store_id']);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_webhook_delivery WHERE store_id<>?', [self::STORE_ID]), 'Another store never receives the event.');
    }

    public function testOrderEventQueuedBeforeTheUpgradeWithoutStoreIsResolvedFromTheOrder(): void
    {
        $db = $this->webhookDb(withOrders: true);
        $event = new StoredDomainEvent(1, Uuid::v4()->toRfc4122(), 'commerce.order.cancelled', 1, 'order', self::ORDER_ID, ['reason' => 'admin_cancel'], ['actor' => 'admin'], new \DateTimeImmutable());

        (new ApiWebhookEventSubscriber($db, new PublicIdFactory()))->handle($event);

        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_webhook_delivery WHERE store_id=? AND event_type=?', [self::STORE_ID, 'commerce.order.cancelled']));
    }

    public function testEventOfAnUnknownOrderIsStillSkipped(): void
    {
        $db = $this->webhookDb(withOrders: true);
        $event = new StoredDomainEvent(1, Uuid::v4()->toRfc4122(), 'commerce.order.completed', 1, 'order', Uuid::v4()->toRfc4122(), [], [], new \DateTimeImmutable());

        (new ApiWebhookEventSubscriber($db, new PublicIdFactory()))->handle($event);

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_webhook_delivery'));
    }

    public function orders(): OrderManagementService
    {
        return new OrderManagementService($this->orderDb(['status' => 'confirmed', 'payment_status' => 'paid', 'fulfillment_status' => 'delivered']), $this->bus(), new DomainEventFactory(new PublicIdFactory()));
    }

    /** @param array<string,mixed> $order */
    public function payments(array $order): PaymentLifecycleService
    {
        $db = $this->orderDb($order);

        return new PaymentLifecycleService($db, $this->bus(), new DomainEventFactory(new PublicIdFactory()), new GiftCardService($db, new PublicIdFactory()), new LoyaltyService($db));
    }

    /**
     * The services lock rows with MySQL `FOR UPDATE`, so they run against a scripted connection that returns one order
     * of store 3 (and its payment) and accepts every write.
     *
     * @param array<string,mixed> $order
     */
    private function orderDb(array $order): Connection
    {
        $row = $order + ['id' => 41, 'public_id' => Uuid::fromString(self::ORDER_ID)->toBinary(), 'store_id' => self::STORE_ID, 'market_id' => 1, 'customer_id' => null, 'order_number' => 'NX-41', 'currency' => 'UAH', 'total_minor' => 12345, 'fulfillment_status' => 'unfulfilled'];
        $payment = ['id' => 9, 'order_id' => 41, 'status' => 'pending', 'provider_modified_at' => null, 'amount_minor' => 12345,
            'order_public_id' => $row['public_id'], 'order_status' => $row['status'], 'order_payment_status' => $row['payment_status'], 'order_total' => 12345, 'order_currency' => 'UAH', 'order_store_id' => self::STORE_ID];
        $db = $this->createStub(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work($db));
        $db->method('fetchAssociative')->willReturnCallback(static function (string $sql) use ($row, $payment): array|false {
            if (str_contains($sql, 'FROM mc_payment p JOIN mc_sales_order')) {
                return $payment;
            }
            if (str_contains($sql, 'FROM mc_payment WHERE order_id')) {
                return $payment;
            }
            if (str_contains($sql, 'FROM mc_sales_order WHERE public_id') || str_contains($sql, 'FROM mc_sales_order WHERE id')) {
                return $row;
            }
            return false;
        });
        $db->method('fetchAllAssociative')->willReturn([]);
        $db->method('fetchFirstColumn')->willReturn([41]);
        $db->method('fetchOne')->willReturn(0);
        $db->method('update')->willReturn(1);
        $db->method('insert')->willReturn(1);
        $db->method('executeStatement')->willReturn(1);

        return $db;
    }

    private function bus(): EventBusInterface
    {
        $test = $this;

        return new class ($test) implements EventBusInterface {
            public function __construct(private ApiWebhookOrderEventsTest $test)
            {
            }

            public function publish(DomainEvent ...$events): void
            {
                foreach ($events as $event) {
                    $this->test->record($event);
                }
            }
        };
    }

    public function record(DomainEvent $event): void
    {
        $this->published[] = $event;
    }

    private function stored(DomainEvent $event): StoredDomainEvent
    {
        // The outbox stores payload and metadata as JSON; the subscriber receives the decoded copy.
        $roundTrip = static fn (array $data): array => json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);

        return new StoredDomainEvent(1, $event->eventId(), $event->eventName(), $event->eventVersion(), $event->aggregateType(), $event->aggregateId(), $roundTrip($event->payload()), $roundTrip($event->metadata()), $event->occurredAt());
    }

    private function webhookDb(bool $withOrders): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_webhook_subscription (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id BLOB, store_id INTEGER, target_url TEXT, target_url_hash BLOB, events TEXT, status TEXT)');
        $db->executeStatement('CREATE TABLE mc_webhook_delivery (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id BLOB, store_id INTEGER, subscription_key TEXT, event_id BLOB, event_type TEXT, payload TEXT, target_url_hash BLOB, status TEXT, attempts INTEGER, next_attempt_at TEXT, locked_at TEXT NULL, lock_token TEXT NULL, response_status INTEGER NULL, last_error TEXT NULL, created_at TEXT, completed_at TEXT NULL, UNIQUE (subscription_key, event_id))');
        $events = json_encode(['commerce.order.placed', 'commerce.order.cancelled', 'commerce.order.completed', 'commerce.payment.status_changed'], JSON_THROW_ON_ERROR);
        foreach ([self::STORE_ID, 4] as $store) {
            $db->insert('mc_webhook_subscription', ['public_id' => Uuid::v4()->toBinary(), 'store_id' => $store, 'target_url' => 'https://crm.example.test/hook', 'target_url_hash' => hash('sha256', 'https://crm.example.test/hook', true), 'events' => $events, 'status' => 'active']);
        }
        if ($withOrders) {
            $db->executeStatement('CREATE TABLE mc_sales_order (id INTEGER PRIMARY KEY, public_id BLOB, store_id INTEGER)');
            $db->insert('mc_sales_order', ['id' => 41, 'public_id' => Uuid::fromString(self::ORDER_ID)->toBinary(), 'store_id' => self::STORE_ID]);
        }

        return $db;
    }
}
