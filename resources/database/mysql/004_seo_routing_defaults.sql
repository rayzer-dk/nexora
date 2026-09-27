-- Nexora Commerce 1.0.0
-- Schema v4: central SEO routing/history and Ukraine-first installation defaults.

ALTER TABLE mc_store
    ADD COLUMN default_country CHAR(2) NOT NULL DEFAULT 'UA' AFTER name,
    MODIFY COLUMN default_locale VARCHAR(16) NOT NULL DEFAULT 'uk-UA',
    MODIFY COLUMN default_currency CHAR(3) NOT NULL DEFAULT 'UAH',
    MODIFY COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Kyiv';

INSERT IGNORE INTO mc_locale (code, language_code, region_code, name, native_name, direction, enabled, created_at, updated_at)
VALUES ('uk-UA', 'uk', 'UA', 'Ukrainian (Ukraine)', 'Українська', 'ltr', 1, NOW(6), NOW(6));

INSERT IGNORE INTO mc_currency (code, numeric_code, name, symbol, minor_units, enabled, created_at, updated_at)
VALUES ('UAH', '980', 'Ukrainian hryvnia', '₴', 2, 1, NOW(6), NOW(6));

-- Translation-table slugs become migration/import compatibility fields only. Central SEO routing is authoritative.
ALTER TABLE mc_product_translation ADD KEY idx_product_translation_store_fk (store_id);
ALTER TABLE mc_product_translation DROP INDEX uq_product_translation_slug, MODIFY COLUMN slug VARCHAR(255) NULL;
ALTER TABLE mc_category_translation ADD KEY idx_category_translation_store_fk (store_id);
ALTER TABLE mc_category_translation DROP INDEX uq_category_translation_slug, MODIFY COLUMN slug VARCHAR(255) NULL;
ALTER TABLE mc_brand_translation ADD KEY idx_brand_translation_store_fk (store_id);
ALTER TABLE mc_brand_translation DROP INDEX uq_brand_translation_slug, MODIFY COLUMN slug VARCHAR(255) NULL;

CREATE TABLE mc_seo_route (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_public_id BINARY(16) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    path_hash BINARY(32) NOT NULL,
    indexable TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_route_public_id (public_id),
    UNIQUE KEY uq_seo_route_entity (store_id, locale, entity_type, entity_public_id),
    UNIQUE KEY uq_seo_route_path_hash (store_id, locale, path_hash),
    KEY idx_seo_route_entity_reverse (entity_type, entity_public_id, store_id, locale),
    CONSTRAINT fk_seo_route_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_route_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_seo_redirect (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    source_path VARCHAR(1024) NOT NULL,
    source_path_hash BINARY(32) NOT NULL,
    route_id BIGINT UNSIGNED NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    reason VARCHAR(32) NOT NULL DEFAULT 'canonical_changed',
    created_at DATETIME(6) NOT NULL,
    last_hit_at DATETIME(6) NULL,
    hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_redirect_source (store_id, locale, source_path_hash),
    KEY idx_seo_redirect_route (route_id, id),
    KEY idx_seo_redirect_hits (last_hit_at, hit_count),
    CONSTRAINT fk_seo_redirect_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_redirect_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT,
    CONSTRAINT fk_seo_redirect_route FOREIGN KEY (route_id) REFERENCES mc_seo_route(id) ON DELETE CASCADE,
    CONSTRAINT chk_seo_redirect_status CHECK (status_code IN (301, 308))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fix schema-v3 reservation history: commit timestamp is part of the runtime reservation state machine.
