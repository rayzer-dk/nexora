<?php

declare(strict_types=1);

namespace Commerce\Modules\Automation\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Automation\Application\AutomationCatalog;
use Commerce\Modules\Automation\Application\AutomationEngine;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Bridges the transactional domain-event outbox to the rule engine. */
final readonly class AutomationDomainEventSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private Connection $db, private AutomationEngine $engine) {}

    public function subscriberId(): string
    {
        return 'automation.rules.v1';
    }

    public function subscribedEvents(): array
    {
        return array_keys(AutomationCatalog::DOMAIN_MAP);
    }

    public function handle(StoredDomainEvent $event): void
    {
        $trigger = AutomationCatalog::DOMAIN_MAP[$event->eventName] ?? null;
        if ($trigger === null) {
            return;
        }
        $binary = Uuid::fromString($event->aggregateId)->toBinary();
        if ($event->eventName === EventNames::CUSTOMER_REGISTERED) {
            $row = $this->db->fetchAssociative('SELECT id,display_name,email FROM mc_customer WHERE public_id=? LIMIT 1', [$binary]);
            $storeId = isset($event->payload['store_id']) ? (int) $event->payload['store_id'] : (int) $this->db->fetchOne('SELECT store_id FROM mc_store_customer WHERE customer_id=? ORDER BY store_id LIMIT 1', [(int) ($row['id'] ?? 0)]);
            if (!is_array($row) || $storeId <= 0) {
                return;
            }
            $this->engine->fire($storeId, $trigger, $event->eventId, ['name' => (string) ($row['display_name'] ?: $row['email']), 'text' => (string) ($row['display_name'] ?: $row['email']), 'url' => '/admin/customers']);

            return;
        }
        $order = $this->db->fetchAssociative('SELECT id,store_id,order_number,total_minor,subtotal_minor,shipping_minor,discount_minor,currency,customer_name,customer_phone,customer_email,customer_comment,status,payment_status,created_at FROM mc_sales_order WHERE public_id=? LIMIT 1', [$binary]);
        if (!is_array($order)) {
            return;
        }
        $currency = (string) $order['currency'];
        $money = static fn (mixed $minor): string => number_format(((int) $minor) / 100, 2, '.', ' ') . ' ' . $currency;
        $lines = $this->db->fetchAllAssociative('SELECT name,quantity,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id LIMIT 11', [(int) $order['id']]);
        $items = [];
        foreach (array_slice($lines, 0, 10) as $line) {
            $items[] = '• ' . $line['name'] . ' × ' . (int) $line['quantity'] . ' — ' . $money($line['line_total_minor']);
        }
        if (count($lines) > 10) {
            $items[] = '…';
        }
        $payment = (string) $this->db->fetchOne('SELECT provider_code FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $order['id']]);
        $this->engine->fire((int) $order['store_id'], $trigger, $event->eventId, [
            'number' => (string) $order['order_number'], 'total_minor' => (int) $order['total_minor'], 'currency' => $currency,
            'name' => (string) $order['customer_name'], 'text' => '#' . $order['order_number'] . ' · ' . $money($order['total_minor']),
            'phone' => (string) $order['customer_phone'], 'email' => (string) $order['customer_email'], 'comment' => (string) $order['customer_comment'],
            'subtotal' => $money($order['subtotal_minor']), 'shipping' => $money($order['shipping_minor']), 'discount' => $money($order['discount_minor']),
            'items' => implode("\n", $items), 'items_count' => (string) count($lines),
            'status' => (string) $order['status'], 'payment_status' => (string) $order['payment_status'], 'payment' => $payment,
            'date' => substr((string) $order['created_at'], 0, 16),
            'url' => '/admin/orders/' . $event->aggregateId,
        ]);
    }
}
