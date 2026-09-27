-- Nexora Commerce 1.0.0
-- Schema v6: VAT/tax policy, product compliance, documents/relations and shipping dimensions.

CREATE TABLE mc_tax_class (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    kind VARCHAR(24) NOT NULL DEFAULT 'standard',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tax_class_code (code),
    KEY idx_tax_class_enabled (enabled, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_tax_class (code, name, kind, enabled, created_at, updated_at) VALUES
('standard', 'Standard VAT', 'standard', 1, NOW(6), NOW(6)),
('reduced', 'Reduced VAT', 'reduced', 1, NOW(6), NOW(6)),
('zero', 'Zero rate', 'zero', 1, NOW(6), NOW(6)),
('exempt', 'Tax exempt', 'exempt', 1, NOW(6), NOW(6));

CREATE TABLE mc_tax_rate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tax_class_id BIGINT UNSIGNED NOT NULL,
    country_code CHAR(2) NOT NULL,
    region_code VARCHAR(64) NULL,
    name VARCHAR(190) NOT NULL,
    rate_bps INT UNSIGNED NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    valid_from DATETIME(6) NOT NULL,
    valid_to DATETIME(6) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tax_rate_resolution (country_code, region_code, tax_class_id, enabled, valid_from, valid_to, priority),
    KEY idx_tax_rate_class (tax_class_id, enabled, country_code),
    CONSTRAINT fk_tax_rate_class FOREIGN KEY (tax_class_id) REFERENCES mc_tax_class(id) ON DELETE RESTRICT,
    CONSTRAINT chk_tax_rate_bps CHECK (rate_bps <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_product
    CHANGE COLUMN tax_class legacy_tax_class_code VARCHAR(64) NULL,
    ADD COLUMN tax_class_id BIGINT UNSIGNED NULL AFTER manufacturer_part_number,
    ADD COLUMN condition_code VARCHAR(24) NOT NULL DEFAULT 'new' AFTER tax_class_id,
    ADD COLUMN country_of_origin CHAR(2) NULL AFTER condition_code,
    ADD KEY idx_product_tax_class (tax_class_id, status),
    ADD KEY idx_product_origin_condition (country_of_origin, condition_code, status),
    ADD CONSTRAINT fk_product_tax_class FOREIGN KEY (tax_class_id) REFERENCES mc_tax_class(id) ON DELETE RESTRICT;

UPDATE mc_product p
JOIN mc_tax_class tc ON tc.code = CASE
    WHEN p.legacy_tax_class_code IN ('reduced','zero','exempt','standard') THEN p.legacy_tax_class_code
    ELSE 'standard'
END
SET p.tax_class_id = tc.id
WHERE p.tax_class_id IS NULL;

ALTER TABLE mc_product MODIFY COLUMN tax_class_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE mc_market
    CHANGE COLUMN tax_display_mode legacy_tax_display_mode VARCHAR(16) NOT NULL DEFAULT 'inclusive';

CREATE TABLE mc_market_tax_policy (
    market_id BIGINT UNSIGNED NOT NULL,
    consumer_display_mode VARCHAR(32) NOT NULL DEFAULT 'gross_with_breakdown',
    business_display_mode VARCHAR(32) NOT NULL DEFAULT 'net_with_gross',
    prices_entered_including_tax TINYINT(1) NOT NULL DEFAULT 1,
    calculation_basis VARCHAR(24) NOT NULL DEFAULT 'destination',
    merchant_feed_gross_price TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (market_id),
    CONSTRAINT fk_market_tax_policy_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_market_tax_policy (
    market_id, consumer_display_mode, business_display_mode, prices_entered_including_tax,
    calculation_basis, merchant_feed_gross_price, updated_at
)
SELECT id,
       CASE WHEN legacy_tax_display_mode IN ('inclusive','gross','gross_with_breakdown') THEN 'gross_with_breakdown' ELSE 'gross_with_breakdown' END,
       'net_with_gross', 1, 'destination', 1, NOW(6)
FROM mc_market;

CREATE TABLE mc_store_tax_registration (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id BIGINT UNSIGNED NOT NULL,
    country_code CHAR(2) NOT NULL,
    registration_type VARCHAR(32) NOT NULL DEFAULT 'vat',
    registration_number VARCHAR(64) NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_store_tax_registration (store_id, country_code, registration_type, registration_number),
    KEY idx_store_tax_registration_country (store_id, country_code, registration_type),
    CONSTRAINT fk_store_tax_registration_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_customer_tax_profile (
    customer_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(255) NULL,
    country_code CHAR(2) NULL,
    vat_number VARCHAR(64) NULL,
    vat_validation_status VARCHAR(24) NOT NULL DEFAULT 'unchecked',
    vat_validated_at DATETIME(6) NULL,
    tax_exemption_code VARCHAR(64) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (customer_id),
    KEY idx_customer_tax_vat (country_code, vat_number),
    CONSTRAINT fk_customer_tax_profile_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_sales_order
    ADD COLUMN prices_include_tax TINYINT(1) NOT NULL DEFAULT 1 AFTER currency,
    ADD COLUMN tax_country_code CHAR(2) NULL AFTER tax_minor,
    ADD COLUMN tax_calculation_mode VARCHAR(24) NULL AFTER tax_country_code;

ALTER TABLE mc_sales_order_item
    ADD COLUMN unit_price_net_minor BIGINT UNSIGNED NULL AFTER unit_price_minor,
    ADD COLUMN unit_price_gross_minor BIGINT UNSIGNED NULL AFTER unit_price_net_minor,
    ADD COLUMN tax_rate_bps INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_minor,
    ADD COLUMN tax_class_code VARCHAR(64) NULL AFTER tax_rate_bps;

UPDATE mc_sales_order_item
SET unit_price_gross_minor = unit_price_minor,
    unit_price_net_minor = CASE WHEN tax_minor = 0 THEN unit_price_minor ELSE NULL END
WHERE unit_price_gross_minor IS NULL;

CREATE TABLE mc_economic_operator (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    operator_type VARCHAR(32) NOT NULL,
    legal_name VARCHAR(255) NOT NULL,
    trade_name VARCHAR(255) NULL,
    country_code CHAR(2) NOT NULL,
    postal_address VARCHAR(1000) NOT NULL,
    email VARCHAR(320) NOT NULL,
    phone VARCHAR(64) NULL,
    website_url VARCHAR(2048) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_economic_operator_public_id (public_id),
    KEY idx_economic_operator_type_country (operator_type, country_code, legal_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_compliance (
    product_id BIGINT UNSIGNED NOT NULL,
    manufacturer_operator_id BIGINT UNSIGNED NULL,
    eu_responsible_person_operator_id BIGINT UNSIGNED NULL,
    warranty_months SMALLINT UNSIGNED NULL,
    regulatory_identifiers JSON NULL,
    safety_information_required TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (product_id),
    KEY idx_product_compliance_manufacturer (manufacturer_operator_id, product_id),
    KEY idx_product_compliance_responsible (eu_responsible_person_operator_id, product_id),
    CONSTRAINT fk_product_compliance_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_compliance_manufacturer FOREIGN KEY (manufacturer_operator_id) REFERENCES mc_economic_operator(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_compliance_responsible FOREIGN KEY (eu_responsible_person_operator_id) REFERENCES mc_economic_operator(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_compliance_translation (
    product_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    warnings TEXT NULL,
    safety_information MEDIUMTEXT NULL,
    usage_instructions MEDIUMTEXT NULL,
    PRIMARY KEY (product_id, locale),
    CONSTRAINT fk_product_compliance_translation_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_compliance_translation_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_document (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NULL,
    document_type VARCHAR(32) NOT NULL DEFAULT 'document',
    title VARCHAR(255) NOT NULL,
    document_version VARCHAR(64) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_document_public_id (public_id),
    UNIQUE KEY uq_product_document_asset (product_id, media_id, document_type, locale),
    KEY idx_product_document_display (product_id, locale, visible, sort_order),
    CONSTRAINT fk_product_document_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_document_media FOREIGN KEY (media_id) REFERENCES mc_media_asset(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_document_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_relation (
    product_id BIGINT UNSIGNED NOT NULL,
    related_product_id BIGINT UNSIGNED NOT NULL,
    relation_type VARCHAR(32) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (product_id, related_product_id, relation_type),
    KEY idx_product_relation_reverse (related_product_id, relation_type, product_id),
    KEY idx_product_relation_display (product_id, relation_type, sort_order, related_product_id),
    CONSTRAINT fk_product_relation_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_relation_related FOREIGN KEY (related_product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT chk_product_relation_not_self CHECK (product_id <> related_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_product_variant
    ADD COLUMN length_mm INT UNSIGNED NULL AFTER weight_kg,
    ADD COLUMN width_mm INT UNSIGNED NULL AFTER length_mm,
    ADD COLUMN height_mm INT UNSIGNED NULL AFTER width_mm,
    ADD COLUMN shipping_class VARCHAR(64) NULL AFTER height_mm,
    ADD KEY idx_variant_shipping_class (shipping_class, status);
