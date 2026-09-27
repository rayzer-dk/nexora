<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Infrastructure;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\Domain\SeoRoute;
use Commerce\Modules\Seo\Domain\SeoRouteResolution;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSeoUrlRepository implements SeoUrlRepositoryInterface
{
    public function __construct(private Connection $connection, private PublicIdFactory $publicIds)
    {
    }

    public function pathIsReserved(int $storeId, string $locale, string $path, ?int $exceptRouteId = null): bool
    {
        $hash = hash('sha256', $path, true);
        $params = [$storeId, $locale, $hash];
        $routeSql = 'SELECT id FROM mc_seo_route WHERE store_id = ? AND locale = ? AND path_hash = ?';
        if ($exceptRouteId !== null) {
            $routeSql .= ' AND id <> ?';
            $params[] = $exceptRouteId;
        }

        if ($this->connection->fetchOne($routeSql . ' LIMIT 1', $params) !== false) {
            return true;
        }

        $redirectSql = 'SELECT id FROM mc_seo_redirect WHERE store_id = ? AND locale = ? AND source_path_hash = ?';
        $redirectParams = [$storeId, $locale, $hash];
        if ($exceptRouteId !== null) {
            $redirectSql .= ' AND route_id <> ?';
            $redirectParams[] = $exceptRouteId;
        }

        return $this->connection->fetchOne($redirectSql . ' LIMIT 1', $redirectParams) !== false;
    }

    public function findByEntity(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId): ?SeoRoute
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM mc_seo_route WHERE store_id = ? AND locale = ? AND entity_type = ? AND entity_public_id = ? LIMIT 1',
            [$storeId, $locale, $entityType->value, Uuid::fromString($entityPublicId)->toBinary()],
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId, string $slug, string $path): SeoRoute
    {
        return $this->connection->transactional(function (Connection $connection) use ($storeId, $locale, $entityType, $entityPublicId, $slug, $path): SeoRoute {
            if ($this->pathIsReserved($storeId, $locale, $path)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.977bf6dc8578'));
            }

            $publicId = $this->publicIds->generate();
            $now = $this->now();
            $connection->insert('mc_seo_route', [
                'public_id' => $publicId->toBinary(),
                'store_id' => $storeId,
                'locale' => $locale,
                'entity_type' => $entityType->value,
                'entity_public_id' => Uuid::fromString($entityPublicId)->toBinary(),
                'slug' => $slug,
                'path' => $path,
                'path_hash' => hash('sha256', $path, true),
                'indexable' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new SeoRoute((int) $connection->lastInsertId(), $storeId, $locale, $entityType, $entityPublicId, $slug, $path, true);
        });
    }

    public function changeCanonical(SeoRoute $route, string $slug, string $path): SeoRoute
    {
        if ($path === $route->path && $slug === $route->slug) {
            return $route;
        }

        return $this->connection->transactional(function (Connection $connection) use ($route, $slug, $path): SeoRoute {
            $locked = $connection->fetchAssociative('SELECT * FROM mc_seo_route WHERE id = ? FOR UPDATE', [$route->id]);
            if (!is_array($locked)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.932031b6b90e'));
            }
            $current = $this->hydrate($locked);
            if ($this->pathIsReserved($current->storeId, $current->locale, $path, $current->id)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.977bf6dc8578'));
            }

            // Reclaiming one of this entity's own historical paths is allowed and must not leave a self-redirect.
            $connection->executeStatement(
                'DELETE FROM mc_seo_redirect WHERE store_id = ? AND locale = ? AND source_path_hash = ? AND route_id = ?',
                [$current->storeId, $current->locale, hash('sha256', $path, true), $current->id],
            );

            $redirectId = $connection->fetchOne(
                'SELECT id FROM mc_seo_redirect WHERE store_id = ? AND locale = ? AND source_path_hash = ? LIMIT 1',
                [$current->storeId, $current->locale, hash('sha256', $current->path, true)],
            );
            if ($redirectId === false) {
                $connection->insert('mc_seo_redirect', [
                    'store_id' => $current->storeId,
                    'locale' => $current->locale,
                    'source_path' => $current->path,
                    'source_path_hash' => hash('sha256', $current->path, true),
                    'route_id' => $current->id,
                    'status_code' => 301,
                    'reason' => 'canonical_changed',
                    'created_at' => $this->now(),
                    'last_hit_at' => null,
                    'hit_count' => 0,
                ]);
            } else {
                $connection->update('mc_seo_redirect', ['route_id' => $current->id, 'status_code' => 301], ['id' => $redirectId]);
            }

            $connection->update('mc_seo_route', [
                'slug' => $slug,
                'path' => $path,
                'path_hash' => hash('sha256', $path, true),
                'updated_at' => $this->now(),
            ], ['id' => $current->id]);

            return new SeoRoute($current->id, $current->storeId, $current->locale, $current->entityType, $current->entityPublicId, $slug, $path, $current->indexable);
        });
    }


    public function addRedirectAlias(SeoRoute $route, string $sourcePath, string $reason = 'legacy_import'): void
    {
        $sourcePath = trim($sourcePath, '/');
        if ($sourcePath === '' || $sourcePath === $route->path) {
            return;
        }
        if ($this->pathIsReserved($route->storeId, $route->locale, $sourcePath, $route->id)) {
            return;
        }
        $hash = hash('sha256', $sourcePath, true);
        $existing = $this->connection->fetchOne(
            'SELECT id FROM mc_seo_redirect WHERE store_id=? AND locale=? AND source_path_hash=? LIMIT 1',
            [$route->storeId, $route->locale, $hash],
        );
        if ($existing !== false) {
            return;
        }
        $this->connection->insert('mc_seo_redirect', [
            'store_id' => $route->storeId,
            'locale' => $route->locale,
            'source_path' => $sourcePath,
            'source_path_hash' => $hash,
            'route_id' => $route->id,
            'status_code' => 301,
            'reason' => mb_substr($reason, 0, 32),
            'created_at' => $this->now(),
            'last_hit_at' => null,
            'hit_count' => 0,
        ]);
    }

    public function resolve(int $storeId, string $locale, string $path): SeoRouteResolution
    {
        $hash = hash('sha256', $path, true);
        $route = $this->connection->fetchAssociative(
            'SELECT * FROM mc_seo_route WHERE store_id = ? AND locale = ? AND path_hash = ? AND path = ? LIMIT 1',
            [$storeId, $locale, $hash, $path],
        );
        if (is_array($route)) {
            return SeoRouteResolution::canonical($this->hydrate($route));
        }

        $redirect = $this->connection->fetchAssociative(
            'SELECT route_id, status_code FROM mc_seo_redirect WHERE store_id = ? AND locale = ? AND source_path_hash = ? AND source_path = ? LIMIT 1',
            [$storeId, $locale, $hash, $path],
        );
        if (!is_array($redirect)) {
            return SeoRouteResolution::notFound();
        }

        $target = $this->connection->fetchAssociative('SELECT * FROM mc_seo_route WHERE id = ? LIMIT 1', [$redirect['route_id']]);
        if (!is_array($target)) {
            return SeoRouteResolution::notFound();
        }

        // Redirect analytics are deliberately best-effort: SEO resolution must never fail
        // because a counter could not be written.
        try {
            $this->connection->executeStatement(
                'UPDATE mc_seo_redirect SET hit_count = hit_count + 1, last_hit_at = ? WHERE store_id = ? AND locale = ? AND source_path_hash = ? AND source_path = ?',
                [$this->now(), $storeId, $locale, $hash, $path],
            );
        } catch (\Throwable) {
        }

        return SeoRouteResolution::redirect($this->hydrate($target), (int) $redirect['status_code']);
    }

    private function hydrate(array $row): SeoRoute
    {
        return new SeoRoute(
            id: (int) $row['id'],
            storeId: (int) $row['store_id'],
            locale: (string) $row['locale'],
            entityType: SeoEntityType::from((string) $row['entity_type']),
            entityPublicId: Uuid::fromBinary((string) $row['entity_public_id'])->toRfc4122(),
            slug: (string) $row['slug'],
            path: (string) $row['path'],
            indexable: (bool) $row['indexable'],
        );
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
