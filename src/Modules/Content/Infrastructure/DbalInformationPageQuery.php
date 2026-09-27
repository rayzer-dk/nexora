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
            "SELECT ce.id,ce.status,ce.published_at,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description
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
}
