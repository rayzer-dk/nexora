<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DeliveryPointCache
{
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * Stores only short-lived query results for one selected city/settlement.
     * It never persists or synchronizes a complete carrier directory.
     *
     * @param callable():list<DeliveryPoint> $loader
     * @return list<DeliveryPoint>
     */
    public function remember(string $providerCode, DeliveryPointSearch $search, int $ttlSeconds, callable $loader): array
    {
        $types = array_map(static fn (\BackedEnum $type): string => (string) $type->value, $search->types);
        sort($types);
        $key = 'commerce.shipping.points.' . hash('sha256', implode('|', [
            $providerCode,
            $search->countryCode,
            $search->cityId,
            mb_strtolower(trim($search->query), 'UTF-8'),
            implode(',', $types) ?: '*',
            (string) $search->limit,
        ]));

        $value = $this->cache->get($key, static function (ItemInterface $item) use ($ttlSeconds, $loader): array {
            $item->expiresAfter(max(60, min($ttlSeconds, 3600)));
            return $loader();
        });

        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $point): bool => $point instanceof DeliveryPoint))
            : [];
    }
}
