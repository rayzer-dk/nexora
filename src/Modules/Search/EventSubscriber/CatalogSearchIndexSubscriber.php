<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Search\Infrastructure\MeilisearchProductIndexer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogSearchIndexSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(
        private Connection $db,
        private MeilisearchProductIndexer $indexer,
        private \Commerce\Modules\Search\Application\SqlSearchIndex $sqlIndex,
    ) {
    }

    public function subscriberId(): string
    {
        return 'search.catalog_index.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::PRODUCT_CREATED, EventNames::PRODUCT_UPDATED, EventNames::ORDER_PLACED, EventNames::ORDER_CANCELLED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if (in_array($event->eventName, [EventNames::PRODUCT_CREATED, EventNames::PRODUCT_UPDATED], true)) {
            $productId = $this->internalId('mc_product', $event->aggregateId);
            if ($productId > 0) {
                // The built-in index is always kept current; Meilisearch only when configured.
                $this->sqlIndex->rebuildProduct($productId);
                if ($this->indexer->isEnabled()) {
                    $this->indexer->rebuildProduct($productId);
                }
            }
            return;
        }
        if (!$this->indexer->isEnabled()) {
            return;
        }
        $orderId = $this->internalId('mc_sales_order', $event->aggregateId);
        if ($orderId < 1) {
            return;
        }
        $productIds = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT product_id FROM mc_sales_order_item WHERE order_id=? AND product_id IS NOT NULL', [$orderId]));
        foreach ($productIds as $productId) {
            if ($productId > 0) {
                $this->indexer->rebuildProduct($productId);
            }
        }
    }

    private function internalId(string $table, string $publicId): int
    {
        if (!Uuid::isValid($publicId)) {
            return 0;
        }
        $safeTable = match ($table) {
            'mc_product' => 'mc_product',
            'mc_sales_order' => 'mc_sales_order',
            default => throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5caa01613796')),
        };
        return (int) $this->db->fetchOne('SELECT id FROM ' . $safeTable . ' WHERE public_id=? LIMIT 1', [Uuid::fromString($publicId)->toBinary()]);
    }
}
