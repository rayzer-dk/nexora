<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Commerce\Core\Configuration\SystemSettingStore;

/** Cron settings: pseudo-cron switch (default ON, for hosting without a server cron) and the web-cron secret token. */
final class CronSettings
{
    private const KEY = 'cron.settings';
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    public function pseudoEnabled(): bool
    {
        return (bool) ($this->load()['pseudo'] ?? true);
    }

    public function setPseudoEnabled(bool $enabled): void
    {
        $this->save(['pseudo' => $enabled]);
    }

    /** The token is created lazily on first use and never changes until regenerated. */
    public function token(): string
    {
        $token = $this->load()['token'] ?? '';
        if (is_string($token) && preg_match('/^[a-f0-9]{40}$/', $token) === 1) {
            return $token;
        }

        return $this->regenerateToken();
    }

    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(20));
        $this->save(['token' => $token]);

        return $token;
    }

    public function tokenMatches(string $candidate): bool
    {
        $token = $this->load()['token'] ?? '';

        return is_string($token) && $token !== '' && preg_match('/^[a-f0-9]{40}$/', $candidate) === 1 && hash_equals($token, $candidate);
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        return $this->cache ??= ($this->store->getArray(self::KEY) ?? []);
    }

    /** @param array<string,mixed> $patch */
    private function save(array $patch): void
    {
        $data = array_replace($this->load(), $patch);
        $this->store->setArray(self::KEY, $data);
        $this->cache = $data;
    }
}
