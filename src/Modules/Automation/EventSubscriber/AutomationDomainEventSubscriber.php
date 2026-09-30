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
        $order = $this->db->fetchAssociative('SELECT store_id,order_number,total_minor,currency,customer_name FROM mc_sales_order WHERE public_id=? LIMIT 1', [$binary]);
        if (!is_array($order)) {
            return;
        }
        $this->engine->fire((int) $order['store_id'], $trigger, $event->eventId, [
            'number' => (string) $order['order_number'], 'total_minor' => (int) $order['total_minor'], 'currency' => (string) $order['currency'],
            'name' => (string) $order['customer_name'], 'text' => '#' . $order['order_number'] . ' · ' . number_format(((int) $order['total_minor']) / 100, 2, '.', ' ') . ' ' . $order['currency'],
            'url' => '/admin/orders/' . $event->aggregateId,
        ]);
    }
}
