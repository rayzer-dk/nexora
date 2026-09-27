-- Nexora Commerce 0.7.0
-- Localization, currencies, brands, Google taxonomy mapping and resumable migration jobs.

CREATE TABLE mc_locale (
    code VARCHAR(16) NOT NULL,
    language_code VARCHAR(8) NOT NULL,
    region_code CHAR(2) NULL,
    name VARCHAR(190) NOT NULL,
    native_name VARCHAR(190) NOT NULL,
    direction VARCHAR(3) NOT NULL DEFAULT 'ltr',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (code),
    KEY idx_locale_enabled (enabled, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bootstrap locale required by later measurement/content migrations on a clean install.
INSERT INTO mc_locale (code, language_code, region_code, name, native_name, direction, enabled, created_at, updated_at) VALUES
('uk-UA', 'uk', 'UA', 'Ukrainian (Ukraine)', 'Українська', 'ltr', 1, NOW(6), NOW(6));

CREATE TABLE mc_store_locale (
    store_id BIGINT UNSIGNED NOT NULL,
    locale_code VARCHAR(16) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    url_prefix VARCHAR(32) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (store_id, locale_code),
    KEY idx_store_locale_active (store_id, enabled, sort_order),
    CONSTRAINT fk_store_locale_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_locale_locale FOREIGN KEY (locale_code) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_currency (
    code CHAR(3) NOT NULL,
    numeric_code CHAR(3) NULL,
    name VARCHAR(190) NOT NULL,
    symbol VARCHAR(16) NULL,
    minor_units TINYINT UNSIGNED NOT NULL DEFAULT 2,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (code),
    KEY idx_currency_enabled (enabled, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bootstrap currency required by the default Ukraine store profile.
INSERT INTO mc_currency (code, numeric_code, name, symbol, minor_units, enabled, created_at, updated_at) VALUES
('UAH', '980', 'Ukrainian hryvnia', '₴', 2, 1, NOW(6), NOW(6));

CREATE TABLE mc_store_currency (
    store_id BIGINT UNSIGNED NOT NULL,
    currency_code CHAR(3) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    auto_convert TINYINT(1) NOT NULL DEFAULT 0,
    rounding_increment_minor INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (store_id, currency_code),
    KEY idx_store_currency_active (store_id, enabled, sort_order),
    CONSTRAINT fk_store_currency_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_currency_currency FOREIGN KEY (currency_code) REFERENCES mc_currency(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_exchange_rate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base_currency CHAR(3) NOT NULL,
    quote_currency CHAR(3) NOT NULL,
    rate DECIMAL(30,12) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exchange_rate_observation (base_currency, quote_currency, provider, observed_at),
    KEY idx_exchange_rate_latest (base_currency, quote_currency, observed_at),
    CONSTRAINT fk_exchange_rate_base FOREIGN KEY (base_currency) REFERENCES mc_currency(code) ON DELETE RESTRICT,
    CONSTRAINT fk_exchange_rate_quote FOREIGN KEY (quote_currency) REFERENCES mc_currency(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_brand (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    website_url VARCHAR(2048) NULL,
    logo_media_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brand_public_id (public_id),
    UNIQUE KEY uq_brand_normalized_name (normalized_name),
    KEY idx_brand_name (name),
    CONSTRAINT fk_brand_logo_media FOREIGN KEY (logo_media_id) REFERENCES mc_media_asset(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_store_brand (
    store_id BIGINT UNSIGNED NOT NULL,
    brand_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (store_id, brand_id),
    KEY idx_store_brand_status (store_id, status, sort_order),
    CONSTRAINT fk_store_brand_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_brand_brand FOREIGN KEY (brand_id) REFERENCES mc_brand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_brand_translation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    brand_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brand_translation_locale (brand_id, store_id, locale),
    UNIQUE KEY uq_brand_translation_slug (store_id, locale, slug),
    CONSTRAINT fk_brand_translation_brand FOREIGN KEY (brand_id) REFERENCES mc_brand(id) ON DELETE CASCADE,
    CONSTRAINT fk_brand_translation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_product
    ADD COLUMN brand_id BIGINT UNSIGNED NULL AFTER status,
    ADD KEY idx_product_brand_id (brand_id),
    ADD CONSTRAINT fk_product_brand FOREIGN KEY (brand_id) REFERENCES mc_brand(id) ON DELETE SET NULL;

CREATE TABLE mc_google_category_mapping (
    category_id BIGINT UNSIGNED NOT NULL,
    google_category_id BIGINT UNSIGNED NOT NULL,
    google_category_path VARCHAR(1000) NULL,
    taxonomy_version VARCHAR(64) NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (category_id),
    KEY idx_google_category_id (google_category_id, category_id),
    CONSTRAINT fk_google_category_mapping_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_google_product_override (
    product_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    google_category_id BIGINT UNSIGNED NULL,
    google_category_path VARCHAR(1000) NULL,
    product_type_path VARCHAR(1000) NULL,
    custom_labels JSON NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (product_id, store_id),
    KEY idx_google_override_category (google_category_id, product_id),
    CONSTRAINT fk_google_product_override_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_google_product_override_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_import_job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    source_type VARCHAR(64) NOT NULL,
    source_label VARCHAR(190) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    mode VARCHAR(32) NOT NULL DEFAULT 'dry_run',
    options JSON NULL,
    statistics JSON NULL,
    cursor_state JSON NULL,
    started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_job_public_id (public_id),
    KEY idx_import_job_status_updated (status, updated_at),
    KEY idx_import_job_source_created (source_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_import_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    source_key VARCHAR(190) NOT NULL,
    target_public_id BINARY(16) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    source_checksum BINARY(32) NULL,
    error_code VARCHAR(64) NULL,
    error_message VARCHAR(1000) NULL,
    processed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_item_source (job_id, entity_type, source_key),
    KEY idx_import_item_worker (job_id, status, id),
    CONSTRAINT fk_import_item_job FOREIGN KEY (job_id) REFERENCES mc_import_job(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_import_id_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system VARCHAR(64) NOT NULL,
    source_instance_hash BINARY(32) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    source_key VARCHAR(190) NOT NULL,
    target_public_id BINARY(16) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_id_map_source (source_system, source_instance_hash, entity_type, source_key),
    KEY idx_import_id_map_target (entity_type, target_public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_import_issue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    severity VARCHAR(16) NOT NULL,
    entity_type VARCHAR(32) NULL,
    source_key VARCHAR(190) NULL,
    code VARCHAR(64) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    context JSON NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_import_issue_job (job_id, severity, id),
    CONSTRAINT fk_import_issue_job FOREIGN KEY (job_id) REFERENCES mc_import_job(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
