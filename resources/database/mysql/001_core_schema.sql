-- Nexora Commerce 0.6.0
-- Certified targets: MySQL 8.4 / MariaDB 11.4, InnoDB, utf8mb4.
-- Internal joins use BIGINT UNSIGNED. External/public IDs use BINARY(16) UUIDv7 values.

CREATE TABLE mc_store (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    default_locale VARCHAR(16) NOT NULL,
    default_currency CHAR(3) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_store_public_id (public_id),
    UNIQUE KEY uq_store_code (code),
    KEY idx_store_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_customer (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    email VARCHAR(320) NULL,
    email_normalized VARCHAR(320) NULL,
    phone_e164 VARCHAR(32) NULL,
    display_name VARCHAR(190) NULL,
    locale VARCHAR(16) NULL,
    password_hash VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_public_id (public_id),
    UNIQUE KEY uq_customer_email_normalized (email_normalized),
    KEY idx_customer_phone (phone_e164),
    KEY idx_customer_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_customer_identity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(64) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    email_at_provider VARCHAR(320) NULL,
    created_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_identity_provider_subject (provider, subject),
    KEY idx_customer_identity_customer (customer_id),
    CONSTRAINT fk_customer_identity_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    product_type VARCHAR(32) NOT NULL DEFAULT 'physical',
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    brand VARCHAR(190) NULL,
    manufacturer_part_number VARCHAR(190) NULL,
    tax_class VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_public_id (public_id),
    KEY idx_product_status_updated (status, updated_at),
    KEY idx_product_brand (brand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_store_product (
    store_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    published_at DATETIME(6) NULL,
    PRIMARY KEY (store_id, product_id),
    KEY idx_store_product_publish (store_id, status, published_at, product_id),
    KEY idx_store_product_product (product_id, store_id),
    CONSTRAINT fk_store_product_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_product_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_translation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    short_description TEXT NULL,
    description MEDIUMTEXT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_translation_locale (product_id, store_id, locale),
    UNIQUE KEY uq_product_translation_slug (store_id, locale, slug),
    KEY idx_product_translation_product (product_id),
    CONSTRAINT fk_product_translation_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_translation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_category (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_public_id (public_id),
    KEY idx_category_store_parent_sort (store_id, parent_id, sort_order),
    KEY idx_category_store_status (store_id, status),
    CONSTRAINT fk_category_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES mc_category(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_category_translation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_translation_locale (category_id, locale),
    UNIQUE KEY uq_category_translation_slug (store_id, locale, slug),
    CONSTRAINT fk_category_translation_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_translation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_category (
    product_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, category_id),
    KEY idx_product_category_category_sort (category_id, sort_order, product_id),
    KEY idx_product_category_primary (product_id, is_primary),
    CONSTRAINT fk_product_category_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_category_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_variant (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(190) NOT NULL,
    gtin VARCHAR(32) NULL,
    mpn VARCHAR(190) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    weight_kg DECIMAL(12,3) NULL,
    option_data JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variant_public_id (public_id),
    UNIQUE KEY uq_variant_sku (sku),
    KEY idx_variant_product_status (product_id, status),
    KEY idx_variant_gtin (gtin),
    KEY idx_variant_mpn (mpn),
    CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_attribute_definition (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    code VARCHAR(128) NOT NULL,
    data_type VARCHAR(32) NOT NULL,
    filterable TINYINT(1) NOT NULL DEFAULT 0,
    comparable TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attribute_public_id (public_id),
    UNIQUE KEY uq_attribute_code (code),
    KEY idx_attribute_filterable_sort (filterable, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_attribute_translation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attribute_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    name VARCHAR(190) NOT NULL,
    unit_label VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attribute_translation_locale (attribute_id, locale),
    CONSTRAINT fk_attribute_translation_attribute FOREIGN KEY (attribute_id) REFERENCES mc_attribute_definition(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_attribute_value (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    attribute_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NULL,
    value_text VARCHAR(1000) NULL,
    value_text_hash BINARY(32) NULL,
    value_decimal DECIMAL(30,10) NULL,
    value_boolean TINYINT(1) NULL,
    value_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_product_attribute_product (product_id, attribute_id, sort_order),
    KEY idx_product_attribute_variant (variant_id, attribute_id),
    KEY idx_product_attribute_decimal (attribute_id, value_decimal, product_id),
    KEY idx_product_attribute_boolean (attribute_id, value_boolean, product_id),
    KEY idx_product_attribute_text_hash (attribute_id, value_text_hash, product_id),
    CONSTRAINT fk_product_attribute_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_attribute_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_attribute_definition FOREIGN KEY (attribute_id) REFERENCES mc_attribute_definition(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_price (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    customer_group VARCHAR(64) NOT NULL DEFAULT 'default',
    min_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    amount_minor BIGINT UNSIGNED NOT NULL,
    compare_at_minor BIGINT UNSIGNED NULL,
    starts_at DATETIME(6) NULL,
    ends_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_price_lookup (variant_id, store_id, currency, customer_group, starts_at, ends_at),
    KEY idx_price_store_currency (store_id, currency),
    CONSTRAINT fk_price_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_inventory_location (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_location_public_id (public_id),
    UNIQUE KEY uq_inventory_location_store_code (store_id, code),
    KEY idx_inventory_location_status (status),
    CONSTRAINT fk_inventory_location_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    on_hand INT NOT NULL DEFAULT 0,
    reserved INT UNSIGNED NOT NULL DEFAULT 0,
    safety_stock INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_variant_location (variant_id, location_id),
    KEY idx_inventory_location (location_id, variant_id),
    CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_location FOREIGN KEY (location_id) REFERENCES mc_inventory_location(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_cart (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    token_hash BINARY(32) NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_public_id (public_id),
    UNIQUE KEY uq_cart_token_hash (token_hash),
    KEY idx_cart_customer_updated (customer_id, updated_at),
    KEY idx_cart_status_expires (status, expires_at),
    CONSTRAINT fk_cart_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_cart_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_item_variant (cart_id, variant_id),
    KEY idx_cart_item_variant (variant_id),
    CONSTRAINT fk_cart_item_cart FOREIGN KEY (cart_id) REFERENCES mc_cart(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_item_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_sales_order (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    order_number VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    payment_status VARCHAR(32) NOT NULL,
    fulfillment_status VARCHAR(32) NOT NULL,
    currency CHAR(3) NOT NULL,
    subtotal_minor BIGINT UNSIGNED NOT NULL,
    discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shipping_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tax_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_minor BIGINT UNSIGNED NOT NULL,
    customer_email VARCHAR(320) NULL,
    customer_email_normalized VARCHAR(320) NULL,
    customer_phone VARCHAR(32) NULL,
    customer_name VARCHAR(190) NULL,
    locale VARCHAR(16) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_order_public_id (public_id),
    UNIQUE KEY uq_sales_order_number (store_id, order_number),
    KEY idx_sales_order_store_created (store_id, created_at),
    KEY idx_sales_order_customer_created (customer_id, created_at),
    KEY idx_sales_order_status_created (status, created_at),
    KEY idx_sales_order_payment_created (payment_status, created_at),
    KEY idx_sales_order_fulfillment_created (fulfillment_status, created_at),
    KEY idx_sales_order_email (customer_email_normalized),
    CONSTRAINT fk_sales_order_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_order_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_sales_order_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    variant_id BIGINT UNSIGNED NULL,
    sku VARCHAR(190) NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    line_total_minor BIGINT UNSIGNED NOT NULL,
    tax_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    snapshot JSON NULL,
    PRIMARY KEY (id),
    KEY idx_order_item_order (order_id),
    KEY idx_order_item_variant (variant_id),
    KEY idx_order_item_sku (sku),
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_fulfillment (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    provider_code VARCHAR(64) NOT NULL,
    service_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    tracking_number VARCHAR(190) NULL,
    destination_snapshot JSON NOT NULL,
    provider_snapshot JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fulfillment_public_id (public_id),
    KEY idx_fulfillment_order (order_id),
    KEY idx_fulfillment_tracking (provider_code, tracking_number),
    KEY idx_fulfillment_status_updated (status, updated_at),
    CONSTRAINT fk_fulfillment_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_payment (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    provider_code VARCHAR(64) NOT NULL,
    provider_reference VARCHAR(190) NULL,
    status VARCHAR(32) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_public_id (public_id),
    UNIQUE KEY uq_payment_idempotency (idempotency_key),
    KEY idx_payment_order (order_id),
    KEY idx_payment_provider_reference (provider_code, provider_reference),
    KEY idx_payment_status_created (status, created_at),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_media_asset (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    storage_key VARCHAR(768) NOT NULL,
    storage_key_hash BINARY(32) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    bytes BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    checksum_sha256 BINARY(32) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_asset_public_id (public_id),
    UNIQUE KEY uq_media_asset_storage_key_hash (storage_key_hash),
    KEY idx_media_asset_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_media (
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    media_asset_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'gallery',
    sort_order INT NOT NULL DEFAULT 0,
    alt_text VARCHAR(500) NULL,
    PRIMARY KEY (product_id, media_asset_id, role),
    KEY idx_product_media_order (product_id, role, sort_order),
    KEY idx_variant_media_order (variant_id, role, sort_order),
    CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_media_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_media_asset FOREIGN KEY (media_asset_id) REFERENCES mc_media_asset(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_outbox_event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BINARY(16) NOT NULL,
    event_type VARCHAR(190) NOT NULL,
    aggregate_type VARCHAR(128) NOT NULL,
    aggregate_id VARCHAR(190) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    available_at DATETIME(6) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    processed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_outbox_event_id (event_id),
    KEY idx_outbox_dispatch (status, available_at, id),
    KEY idx_outbox_aggregate (aggregate_type, aggregate_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    notification_type VARCHAR(128) NOT NULL,
    recipient VARCHAR(512) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    available_at DATETIME(6) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME(6) NULL,
    lock_token BINARY(16) NULL,
    created_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NULL,
    last_error VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_public_id (public_id),
    KEY idx_notification_dispatch (status, available_at, id),
    KEY idx_notification_stale_lock (status, locked_at, id),
    KEY idx_notification_type_created (notification_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_webhook_delivery (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    subscription_key VARCHAR(190) NOT NULL,
    event_id BINARY(16) NOT NULL,
    target_url_hash BINARY(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_public_id (public_id),
    KEY idx_webhook_dispatch (status, next_attempt_at, id),
    KEY idx_webhook_event (event_id),
    KEY idx_webhook_subscription_created (subscription_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id VARCHAR(190) NULL,
    action VARCHAR(190) NOT NULL,
    entity_type VARCHAR(128) NULL,
    entity_id VARCHAR(190) NULL,
    request_id BINARY(16) NULL,
    ip_hash BINARY(32) NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_audit_public_id (public_id),
    KEY idx_audit_actor_created (actor_type, actor_id, created_at),
    KEY idx_audit_entity_created (entity_type, entity_id, created_at),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_security_event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    event_type VARCHAR(128) NOT NULL,
    severity VARCHAR(32) NOT NULL,
    ip_hash BINARY(32) NULL,
    account_ref_hash BINARY(32) NULL,
    request_id BINARY(16) NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_security_event_public_id (public_id),
    KEY idx_security_event_type_created (event_type, created_at),
    KEY idx_security_event_severity_created (severity, created_at),
    KEY idx_security_event_ip_created (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_component_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_name VARCHAR(190) NOT NULL,
    installed_version VARCHAR(64) NOT NULL,
    package_type VARCHAR(32) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    extension_api VARCHAR(32) NULL,
    installed_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_component_package_name (package_name),
    KEY idx_component_type_enabled (package_type, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_update_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    component VARCHAR(190) NOT NULL,
    from_version VARCHAR(64) NULL,
    to_version VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    package_sha256 CHAR(64) NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    error_summary VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_update_history_public_id (public_id),
    KEY idx_update_component_started (component, started_at),
    KEY idx_update_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
