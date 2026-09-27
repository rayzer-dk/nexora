<?php

declare(strict_types=1);

namespace Commerce\Core\Site;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Doctrine\DBAL\Connection;

final readonly class SiteCapabilitySettings
{
    public const MODE_SHOP = 'shop';
    public const MODE_CATALOG = 'catalog';
    public const MODE_CONTENT = 'content';
    public const MODE_LANDING = 'landing';
    public const MODE_FORUM = 'forum';
    public const MODE_HYBRID = 'hybrid';

    public function __construct(
        private Connection $connection,
        private ConfigurationRevisionStore $revisions,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(int $storeId): array
    {
        $revision = $this->revisions->latestValidPayload($storeId, 'site', 'capabilities');
        if (is_array($revision)) {
            return $this->normalize($revision);
        }

        // Legacy metadata is best-effort only. Any corruption falls back to the safe shop profile.
        try {
            $publicId = $this->connection->fetchOne('SELECT public_id FROM mc_store WHERE id=?', [$storeId]);
            if (!is_string($publicId) || $publicId === '') {
                return self::profile(self::MODE_SHOP);
            }
            $json = $this->connection->fetchOne(
                "SELECT value_json FROM mc_entity_metadata WHERE entity_type='store' AND entity_public_id=? AND namespace='site' AND meta_key='capabilities' LIMIT 1",
                [$publicId],
            );
            if (!is_string($json) || trim($json) === '') {
                return self::profile(self::MODE_SHOP);
            }
            $saved = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            return is_array($saved) ? $this->normalize($saved) : self::profile(self::MODE_SHOP);
        } catch (\Throwable) {
            return self::profile(self::MODE_SHOP);
        }
    }

    /** @param array<string,mixed> $settings */
    public function save(int $storeId, array $settings, ?string $actorSubject = null): int
    {
        return $this->revisions->activateStoreJson(
            $storeId,
            'site',
            'capabilities',
            $this->normalize($settings),
            $actorSubject,
        );
    }

    public function applyMode(int $storeId, string $mode, ?string $actorSubject = null): int
    {
        return $this->save($storeId, self::profile($mode), $actorSubject);
    }

    public function enabled(int $storeId, string $feature): bool
    {
        $settings = $this->get($storeId);
        return (bool) ($settings['features'][$feature] ?? false);
    }

    /** @return list<array{id:int,public_id:string,revision_number:int,status:string,actor_subject:?string,created_at:string,activated_at:?string}> */
    public function history(int $storeId, int $limit = 20): array
    {
        return $this->revisions->history($storeId, 'site', 'capabilities', $limit);
    }

    public function rollback(int $storeId, int $revisionId, ?string $actorSubject = null): int
    {
        return $this->revisions->rollback(
            $storeId,
            $revisionId,
            'site',
            'capabilities',
            $actorSubject,
            fn (array $payload): array => $this->normalize($payload),
        );
    }

    /** @return array<string,mixed> */
    public static function profile(string $mode): array
    {
        $mode = in_array($mode, self::modes(), true) ? $mode : self::MODE_SHOP;
        $features = match ($mode) {
            self::MODE_CATALOG => [
                'catalog' => true, 'search' => true, 'cart' => false, 'checkout' => false,
                'content' => true, 'blog' => true, 'forum' => false, 'reviews' => true, 'customer_accounts' => false,
            ],
            self::MODE_CONTENT => [
                'catalog' => false, 'search' => false, 'cart' => false, 'checkout' => false,
                'content' => true, 'blog' => true, 'forum' => false, 'reviews' => false, 'customer_accounts' => false,
            ],
            self::MODE_LANDING => [
                'catalog' => false, 'search' => false, 'cart' => false, 'checkout' => false,
                'content' => true, 'blog' => false, 'forum' => false, 'reviews' => false, 'customer_accounts' => false,
            ],
            self::MODE_FORUM => [
                'catalog' => false, 'search' => false, 'cart' => false, 'checkout' => false,
                'content' => true, 'blog' => true, 'forum' => true, 'reviews' => false, 'customer_accounts' => true,
            ],
            self::MODE_HYBRID => [
                'catalog' => true, 'search' => true, 'cart' => true, 'checkout' => true,
                'content' => true, 'blog' => true, 'forum' => true, 'reviews' => true, 'customer_accounts' => true,
            ],
            default => [
                'catalog' => true, 'search' => true, 'cart' => true, 'checkout' => true,
                'content' => true, 'blog' => true, 'forum' => false, 'reviews' => true, 'customer_accounts' => true,
            ],
        };

        return ['mode' => $mode, 'features' => $features];
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_SHOP, self::MODE_CATALOG, self::MODE_CONTENT, self::MODE_LANDING, self::MODE_FORUM, self::MODE_HYBRID];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $mode = (string) ($input['mode'] ?? self::MODE_SHOP);
        if (!in_array($mode, self::modes(), true)) {
            $mode = self::MODE_SHOP;
        }
        $base = self::profile($mode);
        $features = is_array($input['features'] ?? null) ? $input['features'] : [];
        foreach (array_keys($base['features']) as $feature) {
            if (array_key_exists($feature, $features)) {
                $base['features'][$feature] = (bool) $features[$feature];
            }
        }
        if (!$base['features']['catalog']) {
            $base['features']['search'] = false;
            $base['features']['cart'] = false;
            $base['features']['checkout'] = false;
            $base['features']['reviews'] = false;
        }
        if (!$base['features']['cart']) {
            $base['features']['checkout'] = false;
        }
        if (!$base['features']['content']) {
            $base['features']['blog'] = false;
        }
        if (!array_key_exists('forum', $base['features'])) {
            $base['features']['forum'] = false;
        }
        return $base;
    }
}
