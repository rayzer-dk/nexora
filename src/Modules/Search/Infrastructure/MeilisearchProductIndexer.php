<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Infrastructure;

use Commerce\Modules\Storefront\Projection\StorefrontProductProjectionService;

final readonly class MeilisearchProductIndexer
{
    public function __construct(
        private MeilisearchClient $client,
        private StorefrontProductProjectionService $projection,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function rebuildProduct(int $productId): void
    {
        if (!$this->client->isEnabled() || $productId < 1) {
            return;
        }
        try {
            $this->client->deleteProductDocuments($productId);
        } catch (\Throwable) {
            $this->client->ensureIndexSettings();
            $this->client->deleteProductDocuments($productId);
        }
        $documents = $this->projection->rebuildProduct($productId);
        $active = array_values(array_filter($documents, static fn(array $row): bool => ($row['status'] ?? '') === 'published'));
        $this->client->upsertDocuments($active);
    }

    /** @return array{processed:int,last_product_id:int} */
    public function rebuildAll(int $batchSize = 250): array
    {
        if (!$this->client->isEnabled()) {
            return ['processed'=>0,'last_product_id'=>0];
        }
        $this->client->ensureIndexSettings();
        $this->client->clearDocuments();
        $max = $this->projection->maxProductId();
        $after = 0; $processed = 0;
        while ($after < $max) {
            $batch = $this->projection->rebuildBatch($after, $batchSize);
            if ($batch['product_count'] === 0) {
                break;
            }
            $documents = $batch['documents'];
            $active = array_values(array_filter($documents, static fn(array $row): bool => ($row['status'] ?? '') === 'published'));
            $this->client->upsertDocuments($active);
            $after = $batch['last_product_id'];
            $processed += $batch['product_count'];
        }
        return ['processed'=>$processed,'last_product_id'=>$after];
    }
}
