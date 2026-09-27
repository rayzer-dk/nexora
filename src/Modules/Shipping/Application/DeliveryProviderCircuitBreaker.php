<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DeliveryProviderCircuitBreaker
{
    public function __construct(
        private CacheInterface $cache,
        private int $failureThreshold = 3,
        private int $openSeconds = 60,
    ) {
    }

    public function allows(string $providerCode): bool
    {
        $state = $this->state($providerCode);
        return (int) ($state['open_until'] ?? 0) <= time();
    }

    public function recordSuccess(string $providerCode): void
    {
        $this->cache->delete($this->key($providerCode));
    }

    public function recordFailure(string $providerCode): void
    {
        $key = $this->key($providerCode);
        $state = $this->state($providerCode);
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $openUntil = $failures >= $this->failureThreshold ? time() + $this->openSeconds : 0;

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($failures, $openUntil): array {
            $item->expiresAfter(max(300, $this->openSeconds * 2));
            return ['failures' => $failures, 'open_until' => $openUntil];
        });
    }

    /** @return array{failures?:int,open_until?:int} */
    private function state(string $providerCode): array
    {
        $value = $this->cache->get($this->key($providerCode), static function (ItemInterface $item): array {
            $item->expiresAfter(300);
            return ['failures' => 0, 'open_until' => 0];
        });

        return is_array($value) ? $value : ['failures' => 0, 'open_until' => 0];
    }

    private function key(string $providerCode): string
    {
        return 'commerce.shipping.circuit.' . hash('sha256', $providerCode);
    }
}
