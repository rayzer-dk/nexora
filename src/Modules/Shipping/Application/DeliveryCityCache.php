<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DeliveryCityCache
{
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * Cache only settlement results. Branches and parcel lockers are deliberately not persisted here.
     * @param callable():list<DeliveryCity> $loader
     * @return list<DeliveryCity>
     */
    public function remember(string $providerCode, DeliveryCitySearch $search, int $ttlSeconds, callable $loader): array
    {
        $key = 'commerce.shipping.cities.' . hash('sha256', implode('|', [
            $providerCode,
            $search->countryCode,
            mb_strtolower(trim($search->query), 'UTF-8'),
            (string) $search->limit,
        ]));

        $value = $this->cache->get($key, static function (ItemInterface $item) use ($ttlSeconds, $loader): array {
            $item->expiresAfter(max(60, $ttlSeconds));
            return $loader();
        });

        return is_array($value) ? array_values(array_filter($value, static fn (mixed $city): bool => $city instanceof DeliveryCity)) : [];
    }
}
