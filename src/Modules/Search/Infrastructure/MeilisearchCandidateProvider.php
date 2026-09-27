<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Infrastructure;

use Commerce\Modules\Search\Contract\SearchCandidateProviderInterface;
use Commerce\Modules\Search\Domain\SearchCandidateResult;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class MeilisearchCandidateProvider implements SearchCandidateProviderInterface
{
    public function __construct(
        private MeilisearchClient $client,
        private CacheInterface $cache,
    ) {
    }

    public function candidates(StorefrontContext $context, string $query, int $limit = 5000): ?SearchCandidateResult
    {
        $query = trim(mb_substr($query, 0, 120, 'UTF-8'));
        if ($query === '' || !$this->client->isEnabled()) {
            return null;
        }
        $limit = min(5000, max(24, $limit));
        if ($this->isCircuitOpen()) {
            return null;
        }

        try {
            $filters = [
                'store_id = ' . $context->storeId,
                'market_id = ' . $context->marketId,
                'locale = ' . json_encode($context->locale, JSON_THROW_ON_ERROR),
                'currency = ' . json_encode($context->currency, JSON_THROW_ON_ERROR),
                'status = "published"',
            ];
            $result = $this->client->search([
                'q' => $query,
                'limit' => $limit,
                'attributesToRetrieve' => ['product_id'],
                'filter' => implode(' AND ', $filters),
                'showRankingScore' => false,
            ]);
            $ids = [];
            foreach (($result['hits'] ?? []) as $hit) {
                if (is_array($hit) && isset($hit['product_id']) && (int) $hit['product_id'] > 0) {
                    $ids[(int) $hit['product_id']] = true;
                }
            }
            return new SearchCandidateResult(array_keys($ids), (int) ($result['estimatedTotalHits'] ?? count($ids)), 'meilisearch');
        } catch (\Throwable) {
            $this->openCircuit();
            return null;
        }
    }

    private function isCircuitOpen(): bool
    {
        return (bool) $this->cache->get('search.meilisearch.circuit', static function (ItemInterface $item): bool {
            $item->expiresAfter(1);
            return false;
        });
    }

    private function openCircuit(): void
    {
        $this->cache->delete('search.meilisearch.circuit');
        $this->cache->get('search.meilisearch.circuit', static function (ItemInterface $item): bool {
            $item->expiresAfter(20);
            return true;
        });
    }
}
