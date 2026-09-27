-- Nexora Commerce 0.8.0
-- Schema v3: normalized options, market/channel scope, advanced pricing, inventory items,
-- reservations, revisions, idempotency and extension metadata.

-- Development-era free-text brand is replaced by canonical brand_id from schema v2.
ALTER TABLE mc_product DROP INDEX idx_product_brand;
ALTER TABLE mc_product DROP COLUMN brand;
ALTER TABLE mc_product ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;

ALTER TABLE mc_product_variant
    ADD COLUMN manage_inventory TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN allow_backorder TINYINT(1) NOT NULL DEFAULT 0 AFTER manage_inventory,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER allow_backorder,
    ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;

-- Variant options are normalized instead of being embedded in arbitrary JSON.
CREATE TABLE mc_product_option (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(128) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_option_public_id (public_id),
    UNIQUE KEY uq_product_option_code (product_id, code),
    KEY idx_product_option_sort (product_id, sort_order, id),
    CONSTRAINT fk_product_option_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_option_translation (
    option_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(190) NOT NULL,
    PRIMARY KEY (option_id, locale),
    CONSTRAINT fk_product_option_translation_option FOREIGN KEY (option_id) REFERENCES mc_product_option(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_option_value (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(128) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_option_value_public_id (public_id),
    UNIQUE KEY uq_product_option_value_code (option_id, code),
    KEY idx_product_option_value_sort (option_id, sort_order, id),
    CONSTRAINT fk_product_option_value_option FOREIGN KEY (option_id) REFERENCES mc_product_option(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_option_value_translation (
    option_value_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(190) NOT NULL,
    PRIMARY KEY (option_value_id, locale),
    CONSTRAINT fk_product_option_value_translation_value FOREIGN KEY (option_value_id) REFERENCES mc_product_option_value(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_variant_option_value (
    variant_id BIGINT UNSIGNED NOT NULL,
    option_value_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (variant_id, option_value_id),
    KEY idx_variant_option_value_reverse (option_value_id, variant_id),
    CONSTRAINT fk_variant_option_value_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_variant_option_value_value FOREIGN KEY (option_value_id) REFERENCES mc_product_option_value(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE mc_product_variant v
JOIN mc_product p ON p.id = v.product_id
SET v.manage_inventory = 0
WHERE p.product_type = 'digital';

ALTER TABLE mc_product_variant DROP COLUMN option_data;

-- A store can serve multiple commercial markets without duplicating catalog entities.
CREATE TABLE mc_market (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    default_locale VARCHAR(16) NOT NULL,
    default_currency CHAR(3) NOT NULL,
    tax_display_mode VARCHAR(16) NOT NULL DEFAULT 'inclusive',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_market_public_id (public_id),
    UNIQUE KEY uq_market_store_code (store_id, code),
    KEY idx_market_store_status (store_id, status, id),
    CONSTRAINT fk_market_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_market_country (
    market_id BIGINT UNSIGNED NOT NULL,
    country_code CHAR(2) NOT NULL,
    PRIMARY KEY (market_id, country_code),
    KEY idx_market_country_lookup (country_code, market_id),
    CONSTRAINT fk_market_country_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_market_inventory_location (
    market_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (market_id, location_id),
    KEY idx_market_location_priority (market_id, priority, location_id),
    KEY idx_market_location_reverse (location_id, market_id),
    CONSTRAINT fk_market_inventory_location_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE,
    CONSTRAINT fk_market_inventory_location_location FOREIGN KEY (location_id) REFERENCES mc_inventory_location(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pricing remains variant-based but gets explicit lists/rules and market context.
CREATE TABLE mc_price_list (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    market_id BIGINT UNSIGNED NULL,
    code VARCHAR(128) NOT NULL,
    name VARCHAR(190) NOT NULL,
    list_type VARCHAR(32) NOT NULL DEFAULT 'sale',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    starts_at DATETIME(6) NULL,
    ends_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_list_public_id (public_id),
    UNIQUE KEY uq_price_list_store_code (store_id, code),
    KEY idx_price_list_active (store_id, market_id, status, starts_at, ends_at, priority),
    CONSTRAINT fk_price_list_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_list_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_price_rule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    price_list_id BIGINT UNSIGNED NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    operator VARCHAR(16) NOT NULL DEFAULT 'eq',
    value_json JSON NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    KEY idx_price_rule_list (price_list_id, sort_order, id),
    KEY idx_price_rule_attribute (attribute, price_list_id),
    CONSTRAINT fk_price_rule_list FOREIGN KEY (price_list_id) REFERENCES mc_price_list(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_price
    ADD COLUMN price_list_id BIGINT UNSIGNED NULL AFTER store_id,
    ADD COLUMN market_id BIGINT UNSIGNED NULL AFTER price_list_id,
    ADD COLUMN max_quantity INT UNSIGNED NULL AFTER min_quantity,
    ADD COLUMN tax_included TINYINT(1) NOT NULL DEFAULT 1 AFTER compare_at_minor;
ALTER TABLE mc_price ADD KEY idx_price_context (variant_id, store_id, market_id, currency, customer_group, min_quantity, max_quantity, starts_at, ends_at);
ALTER TABLE mc_price ADD KEY idx_price_list_lookup (price_list_id, variant_id, currency, min_quantity);
ALTER TABLE mc_price ADD CONSTRAINT fk_price_list FOREIGN KEY (price_list_id) REFERENCES mc_price_list(id) ON DELETE CASCADE;
ALTER TABLE mc_price ADD CONSTRAINT fk_price_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE;
ALTER TABLE mc_price DROP INDEX idx_price_lookup;

-- Inventory item is independent from a sellable variant. This supports kits/bundles and shared stock.
CREATE TABLE mc_inventory_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    sku VARCHAR(190) NOT NULL,
    unit_code VARCHAR(32) NOT NULL DEFAULT 'unit',
    requires_shipping TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_item_public_id (public_id),
    UNIQUE KEY uq_inventory_item_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_variant_inventory_item (
    variant_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NOT NULL,
    required_quantity DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
    PRIMARY KEY (variant_id, inventory_item_id),
    KEY idx_variant_inventory_item_reverse (inventory_item_id, variant_id),
    CONSTRAINT fk_variant_inventory_item_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_variant_inventory_item_item FOREIGN KEY (inventory_item_id) REFERENCES mc_inventory_item(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convert existing development stock model to one inventory item per variant.
INSERT INTO mc_inventory_item (public_id, sku, unit_code, requires_shipping, created_at, updated_at)
SELECT v.public_id, v.sku, 'unit', 1, NOW(6), NOW(6)
FROM mc_product_variant v
WHERE v.manage_inventory = 1;

INSERT INTO mc_variant_inventory_item (variant_id, inventory_item_id, required_quantity)
SELECT v.id, i.id, 1.000000
FROM mc_product_variant v
JOIN mc_inventory_item i ON i.sku = v.sku;

CREATE TABLE mc_stock_level (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_item_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    stocked_quantity DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
    reserved_quantity DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
    incoming_quantity DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
    safety_stock DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_level_item_location (inventory_item_id, location_id),
    KEY idx_stock_level_location (location_id, inventory_item_id),
    CONSTRAINT fk_stock_level_item FOREIGN KEY (inventory_item_id) REFERENCES mc_inventory_item(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_level_location FOREIGN KEY (location_id) REFERENCES mc_inventory_location(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_stock_level (inventory_item_id, location_id, stocked_quantity, reserved_quantity, safety_stock, updated_at)
SELECT vii.inventory_item_id, legacy.location_id, legacy.on_hand, legacy.reserved, legacy.safety_stock, legacy.updated_at
FROM mc_inventory legacy
JOIN mc_variant_inventory_item vii ON vii.variant_id = legacy.variant_id;

DROP TABLE mc_inventory;

CREATE TABLE mc_inventory_reservation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    inventory_item_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    cart_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(190) NULL,
    quantity DECIMAL(18,6) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    released_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_reservation_public_id (public_id),
    UNIQUE KEY uq_inventory_reservation_idempotency (idempotency_key),
    KEY idx_inventory_reservation_active (inventory_item_id, location_id, status, expires_at),
    KEY idx_inventory_reservation_cart (cart_id, status),
    KEY idx_inventory_reservation_order (order_id, status),
    CONSTRAINT fk_inventory_reservation_item FOREIGN KEY (inventory_item_id) REFERENCES mc_inventory_item(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_reservation_location FOREIGN KEY (location_id) REFERENCES mc_inventory_location(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_reservation_cart FOREIGN KEY (cart_id) REFERENCES mc_cart(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_reservation_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic draft/version snapshots are separated from hot storefront tables.
CREATE TABLE mc_entity_revision (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    entity_type VARCHAR(48) NOT NULL,
    entity_public_id BINARY(16) NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'draft',
    payload JSON NOT NULL,
    checksum BINARY(32) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_subject VARCHAR(190) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entity_revision_public_id (public_id),
    UNIQUE KEY uq_entity_revision_no (entity_type, entity_public_id, revision_no),
    KEY idx_entity_revision_state (entity_type, entity_public_id, state, revision_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable order event trail complements the order snapshot and audit log.
CREATE TABLE mc_order_event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload JSON NULL,
    actor_type VARCHAR(32) NULL,
    actor_subject VARCHAR(190) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_event_sequence (order_id, sequence_no),
    KEY idx_order_event_type_created (event_type, created_at),
    CONSTRAINT fk_order_event_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_sales_order ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;

-- API idempotency makes retrying checkout/payment/integration writes safe.
CREATE TABLE mc_idempotency_key (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(64) NOT NULL,
    key_hash BINARY(32) NOT NULL,
    request_hash BINARY(32) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'processing',
    response_status SMALLINT UNSIGNED NULL,
    response_payload JSON NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_scope_key (scope, key_hash),
    KEY idx_idempotency_expiry (expires_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extension metadata is for non-query-critical custom data. Search/filter fields stay in typed attributes.
CREATE TABLE mc_entity_metadata (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(48) NOT NULL,
    entity_public_id BINARY(16) NOT NULL,
    namespace VARCHAR(64) NOT NULL,
    meta_key VARCHAR(64) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entity_metadata_key (entity_type, entity_public_id, namespace, meta_key),
    KEY idx_entity_metadata_namespace (namespace, entity_type, entity_public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories are shared catalog entities. Store/market publication is a separate concern.
CREATE TABLE mc_store_category (
    store_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (store_id, category_id),
    KEY idx_store_category_status (store_id, status, sort_order, category_id),
    KEY idx_store_category_reverse (category_id, store_id),
    CONSTRAINT fk_store_category_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_category_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_store_category (store_id, category_id, status, sort_order)
SELECT store_id, id, status, sort_order FROM mc_category;

ALTER TABLE mc_category_translation ADD KEY idx_category_translation_fk_guard (category_id);
ALTER TABLE mc_category_translation DROP INDEX uq_category_translation_locale;
ALTER TABLE mc_category_translation ADD UNIQUE KEY uq_category_translation_locale (category_id, store_id, locale);
ALTER TABLE mc_category_translation DROP INDEX idx_category_translation_fk_guard;

ALTER TABLE mc_category DROP FOREIGN KEY fk_category_store;
ALTER TABLE mc_category DROP INDEX idx_category_store_parent_sort;
ALTER TABLE mc_category DROP INDEX idx_category_store_status;
ALTER TABLE mc_category DROP COLUMN store_id;
ALTER TABLE mc_category ADD KEY idx_category_parent_sort (parent_id, sort_order, id);
ALTER TABLE mc_category ADD KEY idx_category_status (status, id);

-- Market/channel availability is explicit. A product/category can be available in one EU/UA market and hidden in another.
CREATE TABLE mc_market_product (
    market_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    published_at DATETIME(6) NULL,
    PRIMARY KEY (market_id, product_id),
    KEY idx_market_product_publish (market_id, status, published_at, product_id),
    KEY idx_market_product_reverse (product_id, market_id),
    CONSTRAINT fk_market_product_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE,
    CONSTRAINT fk_market_product_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_market_category (
    market_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (market_id, category_id),
    KEY idx_market_category_status (market_id, status, sort_order, category_id),
    KEY idx_market_category_reverse (category_id, market_id),
    CONSTRAINT fk_market_category_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE,
    CONSTRAINT fk_market_category_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mc_price
    ADD COLUMN priority SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER tax_included,
    ADD CONSTRAINT chk_price_quantity_range CHECK (max_quantity IS NULL OR max_quantity >= min_quantity);

ALTER TABLE mc_variant_inventory_item
    ADD CONSTRAINT chk_variant_inventory_required_quantity CHECK (required_quantity > 0);

ALTER TABLE mc_inventory_reservation
    MODIFY idempotency_key VARCHAR(190) NOT NULL,
    ADD COLUMN committed_at DATETIME(6) NULL AFTER released_at,
    DROP INDEX uq_inventory_reservation_idempotency,
    ADD UNIQUE KEY uq_inventory_reservation_idempotency (inventory_item_id, location_id, idempotency_key),
    ADD CONSTRAINT chk_inventory_reservation_quantity CHECK (quantity > 0);
