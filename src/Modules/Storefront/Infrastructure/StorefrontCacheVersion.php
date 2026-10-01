<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * One token that is part of every stored storefront catalogue answer. Changing it makes all of them unreachable at once
 * (the old entries simply expire), so an edit in the admin area shows on the shop immediately instead of after the short
 * lifetime of the stored data.
 */
final class StorefrontCacheVersion
{
    private const KEY = 'storefront.catalog.version';

    private ?string $token = null;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function current(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        try {
            return $this->token = (string) $this->cache->get(self::KEY, static function (ItemInterface $item): string {
                $item->expiresAfter(86400 * 30);

                return bin2hex(random_bytes(4));
            });
        } catch (\Throwable) {
            return $this->token = '0';
        }
    }

    public function bump(): void
    {
        try {
            $this->cache->delete(self::KEY);
        } catch (\Throwable) {
        }
        $this->token = null;
    }
}
