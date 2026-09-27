<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Projection;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class StorefrontFacetProjectionStore
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function get(StorefrontContext $context, ?int $categoryId): ?array
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT payload FROM mc_storefront_facet_projection WHERE store_id=? AND market_id=? AND locale=? AND currency=? AND category_id=? AND expires_at>UTC_TIMESTAMP(6) LIMIT 1',
                [$context->storeId,$context->marketId,$context->locale,$context->currency,$categoryId ?? 0],
            );
            if (!is_array($row)) {
                return null;
            }
            $payload = json_decode((string)$row['payload'], true, 128, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    public function put(StorefrontContext $context, ?int $categoryId, array $payload, int $ttlSeconds = 300): void
    {
        $ttlSeconds = min(3600, max(30, $ttlSeconds));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $generated = $now->format('Y-m-d H:i:s.u');
        $expires = $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s.u');
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->executeStatement(
            "INSERT INTO mc_storefront_facet_projection (store_id,market_id,locale,currency,category_id,payload,generated_at,expires_at)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE payload=VALUES(payload),generated_at=VALUES(generated_at),expires_at=VALUES(expires_at)",
            [$context->storeId,$context->marketId,$context->locale,$context->currency,$categoryId ?? 0,$json,$generated,$expires],
        );
    }
}
