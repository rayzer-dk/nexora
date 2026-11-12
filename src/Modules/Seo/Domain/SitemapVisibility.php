<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Domain;

final class SitemapVisibility
{
    /** SQL predicate on an mc_seo_route alias `sr`: blog articles are listed only while published. */
    public const VISIBLE_ARTICLE = "(sr.entity_type<>'blog_article' OR EXISTS (SELECT 1 FROM mc_content_entry pe JOIN mc_content_translation pt ON pt.content_id=pe.id AND pt.locale=sr.locale WHERE pe.public_id=sr.entity_public_id AND pe.store_id=sr.store_id AND pe.status='published' AND (pe.published_at IS NULL OR pe.published_at<=UTC_TIMESTAMP(6))))";

    private function __construct()
    {
    }
}
