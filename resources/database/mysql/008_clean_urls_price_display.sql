-- Nexora Commerce 1.2.0
-- Schema v8: explicit price-only storefront display and clean system-page URL policy marker.

ALTER TABLE mc_market_tax_policy
    MODIFY COLUMN consumer_display_mode VARCHAR(32) NOT NULL DEFAULT 'price_only';

-- This platform is still pre-production; migrate the previous platform default to the new cleaner default.
-- Explicit B2B/net policies remain unchanged.
UPDATE mc_market_tax_policy
SET consumer_display_mode = 'price_only', updated_at = NOW(6)
WHERE consumer_display_mode = 'gross_with_breakdown';

CREATE TABLE mc_system_route_policy (
    store_id BIGINT UNSIGNED NOT NULL,
    ascii_only TINYINT(1) NOT NULL DEFAULT 1,
    automatic_entity_slugs TINYINT(1) NOT NULL DEFAULT 1,
    preserve_slug_on_title_change TINYINT(1) NOT NULL DEFAULT 1,
    redirect_old_slug TINYINT(1) NOT NULL DEFAULT 1,
    redirect_status SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (store_id),
    CONSTRAINT fk_system_route_policy_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_system_route_policy (
    store_id, ascii_only, automatic_entity_slugs, preserve_slug_on_title_change, redirect_old_slug, redirect_status, updated_at
)
SELECT id, 1, 1, 1, 1, 301, NOW(6)
FROM mc_store;
