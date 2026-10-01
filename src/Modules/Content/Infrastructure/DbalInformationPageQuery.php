<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Infrastructure;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;

final readonly class DbalInformationPageQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string,mixed>|null */
    public function bySystemKey(StorefrontContext $context, string $systemKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT ce.id,ce.system_key,ce.status,ce.published_at,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key=? LIMIT 1",
            [$context->locale, $context->storeId, $systemKey],
        );
        if (!is_array($row)) {
            return null;
        }
        return $row;
    }

    /**
     * Published system pages of the store in this locale, keyed by system key (title only) — feeds the "more information" side panel.
     *
     * @return array<string,string>
     */
    public function publishedTitles(StorefrontContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ce.system_key,ct.title
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             WHERE ce.store_id=? AND ce.content_type='page' AND ce.status='published' AND ce.system_key IS NOT NULL AND ct.title<>''",
            [$context->locale, $context->storeId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['system_key']] = (string) $row['title'];
        }

        return $out;
    }

    /**
     * A merchant-added page by its public id, with the placement and robots settings of mc_content_page_meta.
     *
     * @return array<string,mixed>|null
     */
    public function byPublicId(StorefrontContext $context, string $publicId): ?array
    {
        try {
            $binary = \Symfony\Component\Uid\Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT ce.id,ce.status,ce.published_at,ce.system_key,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description,
                    pm.noindex,pm.canonical_url,ma.storage_key AS og_key
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             LEFT JOIN mc_content_page_meta pm ON pm.content_id=ce.id
             LEFT JOIN mc_media_asset ma ON ma.id=pm.og_asset_id
             WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key IS NULL AND ce.public_id=? LIMIT 1",
            [$context->locale, $context->storeId, $binary],
        );

        return is_array($row) ? $row : null;
    }
}
