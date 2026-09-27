-- Nexora Commerce 1.0.0
-- Schema v5: measurement units, content/reviews, SEO landing policy and update checkpoints.

CREATE TABLE mc_measurement_unit (
    code VARCHAR(32) NOT NULL,
    dimension VARCHAR(32) NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    unece_code VARCHAR(8) NULL,
    google_unit_code VARCHAR(16) NULL,
    factor_to_si DECIMAL(30,12) NULL,
    decimal_scale SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (code),
    KEY idx_measurement_unit_dimension (dimension, enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_measurement_unit_translation (
    unit_code VARCHAR(32) NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(128) NOT NULL,
    short_name VARCHAR(32) NOT NULL,
    PRIMARY KEY (unit_code, locale),
    CONSTRAINT fk_measurement_unit_translation_unit FOREIGN KEY (unit_code) REFERENCES mc_measurement_unit(code) ON DELETE CASCADE,
    CONSTRAINT fk_measurement_unit_translation_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_unit_conversion (
    from_unit_code VARCHAR(32) NOT NULL,
    to_unit_code VARCHAR(32) NOT NULL,
    multiplier DECIMAL(30,12) NOT NULL,
    PRIMARY KEY (from_unit_code, to_unit_code),
    CONSTRAINT fk_unit_conversion_from FOREIGN KEY (from_unit_code) REFERENCES mc_measurement_unit(code) ON DELETE CASCADE,
    CONSTRAINT fk_unit_conversion_to FOREIGN KEY (to_unit_code) REFERENCES mc_measurement_unit(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_measurement_unit (code, dimension, symbol, unece_code, google_unit_code, factor_to_si, decimal_scale, sort_order) VALUES
('item','count','шт','H87','item',1,0,10),
('ct','count','од.','C62','ct',1,0,20),
('set','count','компл.','SET',NULL,1,0,30),
('pair','count','пара','PR',NULL,1,0,40),
('pack','count','упак.','XPK',NULL,1,0,50),
('sheet','count','лист','ST','sheet',1,0,60),
('roll','count','рулон','RO',NULL,1,0,70),
('kg','mass','кг','KGM','kg',1,3,100),
('g','mass','г','GRM','g',0.001,3,110),
('mg','mass','мг','MGM','mg',0.000001,3,120),
('t','mass','т','TNE',NULL,1000,3,130),
('l','volume','л','LTR','l',0.001,3,200),
('ml','volume','мл','MLT','ml',0.000001,3,210),
('cl','volume','cl','CLT','cl',0.00001,3,220),
('cbm','volume','м³','MTQ','cbm',1,6,230),
('m','length','м','MTR','m',1,3,300),
('cm','length','см','CMT','cm',0.01,3,310),
('mm','length','мм','MMT',NULL,0.001,3,320),
('sqm','area','м²','MTK','sqm',1,4,400),
('hour','time','год','HUR',NULL,3600,2,500);

INSERT INTO mc_measurement_unit_translation (unit_code, locale, name, short_name)
SELECT code, 'uk-UA', CASE code
    WHEN 'item' THEN 'Штука' WHEN 'ct' THEN 'Одиниця' WHEN 'set' THEN 'Комплект' WHEN 'pair' THEN 'Пара'
    WHEN 'pack' THEN 'Упаковка' WHEN 'sheet' THEN 'Лист' WHEN 'roll' THEN 'Рулон'
    WHEN 'kg' THEN 'Кілограм' WHEN 'g' THEN 'Грам' WHEN 'mg' THEN 'Міліграм' WHEN 't' THEN 'Тонна'
    WHEN 'l' THEN 'Літр' WHEN 'ml' THEN 'Мілілітр' WHEN 'cl' THEN 'Сантилітр' WHEN 'cbm' THEN 'Кубічний метр'
    WHEN 'm' THEN 'Метр' WHEN 'cm' THEN 'Сантиметр' WHEN 'mm' THEN 'Міліметр' WHEN 'sqm' THEN 'Квадратний метр'
    WHEN 'hour' THEN 'Година' ELSE code END,
    symbol
FROM mc_measurement_unit;

UPDATE mc_inventory_item SET unit_code='item' WHERE unit_code='unit';
ALTER TABLE mc_inventory_item
    MODIFY COLUMN unit_code VARCHAR(32) NOT NULL DEFAULT 'item',
    ADD CONSTRAINT fk_inventory_item_unit FOREIGN KEY (unit_code) REFERENCES mc_measurement_unit(code) ON DELETE RESTRICT;

ALTER TABLE mc_product_variant
    ADD COLUMN sale_unit_code VARCHAR(32) NOT NULL DEFAULT 'item' AFTER sort_order,
    ADD COLUMN quantity_step DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER sale_unit_code,
    ADD COLUMN min_order_quantity DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER quantity_step,
    ADD COLUMN max_order_quantity DECIMAL(18,6) NULL AFTER min_order_quantity,
    ADD COLUMN unit_pricing_measure_value DECIMAL(18,6) NULL AFTER max_order_quantity,
    ADD COLUMN unit_pricing_measure_code VARCHAR(32) NULL AFTER unit_pricing_measure_value,
    ADD COLUMN unit_pricing_base_value DECIMAL(18,6) NULL AFTER unit_pricing_measure_code,
    ADD COLUMN unit_pricing_base_code VARCHAR(32) NULL AFTER unit_pricing_base_value,
    ADD KEY idx_variant_sale_unit (sale_unit_code, status, product_id),
    ADD CONSTRAINT fk_variant_sale_unit FOREIGN KEY (sale_unit_code) REFERENCES mc_measurement_unit(code) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_variant_unit_pricing_measure FOREIGN KEY (unit_pricing_measure_code) REFERENCES mc_measurement_unit(code) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_variant_unit_pricing_base FOREIGN KEY (unit_pricing_base_code) REFERENCES mc_measurement_unit(code) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_variant_quantity_step CHECK (quantity_step > 0),
    ADD CONSTRAINT chk_variant_min_order CHECK (min_order_quantity > 0),
    ADD CONSTRAINT chk_variant_max_order CHECK (max_order_quantity IS NULL OR max_order_quantity >= min_order_quantity);

-- Fractional sale quantities must remain fractional through pricing, cart and immutable order snapshots.
ALTER TABLE mc_price
    MODIFY COLUMN min_quantity DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
    MODIFY COLUMN max_quantity DECIMAL(18,6) NULL;

ALTER TABLE mc_cart_item
    MODIFY COLUMN quantity DECIMAL(18,6) NOT NULL,
    ADD COLUMN unit_code VARCHAR(32) NOT NULL DEFAULT 'item' AFTER quantity,
    ADD CONSTRAINT fk_cart_item_unit FOREIGN KEY (unit_code) REFERENCES mc_measurement_unit(code) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_cart_item_quantity_positive CHECK (quantity > 0);

ALTER TABLE mc_sales_order_item
    MODIFY COLUMN quantity DECIMAL(18,6) NOT NULL,
    ADD COLUMN unit_code VARCHAR(32) NOT NULL DEFAULT 'item' AFTER quantity,
    ADD CONSTRAINT chk_order_item_quantity_positive CHECK (quantity > 0);

CREATE TABLE mc_content_entry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    author_subject VARCHAR(190) NULL,
    published_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_public_id (public_id),
    KEY idx_content_store_type_status (store_id, content_type, status, published_at),
    CONSTRAINT fk_content_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_content_translation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    title VARCHAR(255) NOT NULL,
    excerpt TEXT NULL,
    body_html MEDIUMTEXT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_translation_locale (content_id, locale),
    CONSTRAINT fk_content_translation_content FOREIGN KEY (content_id) REFERENCES mc_content_entry(id) ON DELETE CASCADE,
    CONSTRAINT fk_content_translation_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_review (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    locale VARCHAR(16) NOT NULL,
    author_name VARCHAR(190) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(255) NULL,
    body TEXT NOT NULL,
    verified_purchase TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_review_public_id (public_id),
    KEY idx_review_product_status_created (product_id, status, created_at),
    KEY idx_review_store_status_created (store_id, status, created_at),
    KEY idx_review_customer_product (customer_id, product_id),
    CONSTRAINT fk_review_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL,
    CONSTRAINT fk_review_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_review_media (
    review_id BIGINT UNSIGNED NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (review_id, media_id),
    KEY idx_review_media_sort (review_id, sort_order, media_id),
    CONSTRAINT fk_review_media_review FOREIGN KEY (review_id) REFERENCES mc_product_review(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_media_asset FOREIGN KEY (media_id) REFERENCES mc_media_asset(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valuable filter combinations can be promoted to deliberate SEO landing pages.
-- Ordinary runtime filters never create persistent SEO routes.
CREATE TABLE mc_seo_facet_landing (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    filter_signature BINARY(32) NOT NULL,
    route_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_facet_landing_public_id (public_id),
    UNIQUE KEY uq_seo_facet_signature (store_id, locale, category_id, filter_signature),
    KEY idx_seo_facet_route (route_id),
    CONSTRAINT fk_seo_facet_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_facet_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT,
    CONSTRAINT fk_seo_facet_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_facet_route FOREIGN KEY (route_id) REFERENCES mc_seo_route(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_update_checkpoint (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    from_version VARCHAR(64) NOT NULL,
    to_version VARCHAR(64) NOT NULL,
    release_path VARCHAR(1024) NOT NULL,
    database_backup_ref VARCHAR(1024) NOT NULL,
    files_backup_ref VARCHAR(1024) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'prepared',
    created_at DATETIME(6) NOT NULL,
    activated_at DATETIME(6) NULL,
    rolled_back_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_update_checkpoint_public_id (public_id),
    KEY idx_update_checkpoint_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
