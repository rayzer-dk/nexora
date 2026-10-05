<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Catalog\Application\ProductAddonService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** A cancelled order gives back the limited stock of the shopper's choices (engraving slots, gift boxes...). Done once per order line. */
final readonly class AddonStockSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private Connection $db, private ProductAddonService $addons)
    {
    }

    public function subscriberId(): string
    {
        return 'core.order.addon_stock.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::ORDER_CANCELLED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        try {
            $orderId = $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=?', [Uuid::fromString($event->aggregateId)->toBinary()]);
        } catch (\Throwable) {
            return;
        }
        if ($orderId === false) {
            return;
        }
        foreach ($this->db->fetchAllAssociative('SELECT id,snapshot FROM mc_sales_order_item WHERE order_id=?', [(int) $orderId]) as $item) {
            $snapshot = json_decode((string) $item['snapshot'], true);
            if (!is_array($snapshot) || !is_array($snapshot['addons'] ?? null) || !empty($snapshot['addons_restored'])) {
                continue;
            }
            $this->addons->restoreStock($snapshot['addons'], (int) ($snapshot['addon_qty'] ?? 1));
            $snapshot['addons_restored'] = true;
            $this->db->update('mc_sales_order_item', ['snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)], ['id' => (int) $item['id']]);
        }
    }
}
