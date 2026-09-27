-- Nexora Commerce 1.1.0
-- Schema v7: EU privacy/consent, consumer rights, pricing history, universal product compliance records
-- and accessibility metadata. Niche traceability/recall/energy/DPP features are extensions, not Core.

CREATE TABLE mc_legal_document (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(48) NOT NULL,
    version VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    effective_from DATETIME(6) NULL,
    effective_to DATETIME(6) NULL,
    requires_reacceptance TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_legal_document_public_id (public_id),
    UNIQUE KEY uq_legal_document_version (store_id, document_type, version),
    KEY idx_legal_document_active (store_id, document_type, status, effective_from, effective_to),
    CONSTRAINT fk_legal_document_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_legal_document_translation (
    legal_document_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content_html MEDIUMTEXT NOT NULL,
    PRIMARY KEY (legal_document_id, locale),
    CONSTRAINT fk_legal_document_translation_document FOREIGN KEY (legal_document_id) REFERENCES mc_legal_document(id) ON DELETE CASCADE,
    CONSTRAINT fk_legal_document_translation_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_consent_policy (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    policy_version VARCHAR(32) NOT NULL,
    legal_document_id BIGINT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    default_region_mode VARCHAR(24) NOT NULL DEFAULT 'eu_strict',
    effective_from DATETIME(6) NULL,
    effective_to DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consent_policy_public_id (public_id),
    UNIQUE KEY uq_consent_policy_version (store_id, policy_version),
    KEY idx_consent_policy_active (store_id, status, effective_from, effective_to),
    CONSTRAINT fk_consent_policy_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_policy_legal_document FOREIGN KEY (legal_document_id) REFERENCES mc_legal_document(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_cookie_definition (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(128) NOT NULL,
    provider VARCHAR(190) NOT NULL,
    category VARCHAR(24) NOT NULL,
    purpose VARCHAR(1000) NOT NULL,
    cookie_domain VARCHAR(255) NULL,
    cookie_path VARCHAR(255) NOT NULL DEFAULT '/',
    duration_seconds BIGINT UNSIGNED NULL,
    first_party TINYINT(1) NOT NULL DEFAULT 1,
    essential TINYINT(1) NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cookie_definition (store_id, code),
    KEY idx_cookie_definition_category (store_id, category, enabled, code),
    CONSTRAINT fk_cookie_definition_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_consent_receipt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    consent_policy_id BIGINT UNSIGNED NOT NULL,
    subject_hash BINARY(32) NULL,
    proof_hash BINARY(32) NOT NULL,
    locale VARCHAR(16) NOT NULL,
    country_code CHAR(2) NULL,
    necessary TINYINT(1) NOT NULL DEFAULT 1,
    preferences TINYINT(1) NOT NULL DEFAULT 0,
    analytics TINYINT(1) NOT NULL DEFAULT 0,
    marketing TINYINT(1) NOT NULL DEFAULT 0,
    analytics_storage TINYINT(1) NOT NULL DEFAULT 0,
    ad_storage TINYINT(1) NOT NULL DEFAULT 0,
    ad_user_data TINYINT(1) NOT NULL DEFAULT 0,
    ad_personalization TINYINT(1) NOT NULL DEFAULT 0,
    personalization_storage TINYINT(1) NOT NULL DEFAULT 0,
    functionality_storage TINYINT(1) NOT NULL DEFAULT 0,
    security_storage TINYINT(1) NOT NULL DEFAULT 1,
    choice_source VARCHAR(32) NOT NULL DEFAULT 'banner',
    granted_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consent_receipt_public_id (public_id),
    KEY idx_consent_receipt_subject (store_id, subject_hash, granted_at),
    KEY idx_consent_receipt_policy (consent_policy_id, granted_at),
    KEY idx_consent_receipt_expiry (expires_at, revoked_at),
    CONSTRAINT fk_consent_receipt_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_receipt_policy FOREIGN KEY (consent_policy_id) REFERENCES mc_consent_policy(id) ON DELETE RESTRICT,
    CONSTRAINT fk_consent_receipt_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_market_consumer_policy (
    market_id BIGINT UNSIGNED NOT NULL,
    withdrawal_days SMALLINT UNSIGNED NULL,
    legal_guarantee_months SMALLINT UNSIGNED NULL,
    default_delivery_days SMALLINT UNSIGNED NULL,
    lowest_price_lookback_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    show_lowest_price_on_reduction TINYINT(1) NOT NULL DEFAULT 1,
    require_explicit_optional_extras TINYINT(1) NOT NULL DEFAULT 1,
    payment_obligation_label_required TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (market_id),
    CONSTRAINT fk_market_consumer_policy_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_product_consumer_policy (
    product_id BIGINT UNSIGNED NOT NULL,
    withdrawal_eligible TINYINT(1) NOT NULL DEFAULT 1,
    withdrawal_exception_code VARCHAR(64) NULL,
    explicit_performance_consent_required TINYINT(1) NOT NULL DEFAULT 0,
    guarantee_months_override SMALLINT UNSIGNED NULL,
    return_days_override SMALLINT UNSIGNED NULL,
    age_restriction SMALLINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (product_id),
    CONSTRAINT fk_product_consumer_policy_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_price_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    price_id BIGINT UNSIGNED NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    market_id BIGINT UNSIGNED NULL,
    price_list_id BIGINT UNSIGNED NULL,
    currency CHAR(3) NOT NULL,
    customer_group VARCHAR(64) NOT NULL DEFAULT 'default',
    amount_minor BIGINT UNSIGNED NOT NULL,
    tax_included TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATETIME(6) NOT NULL,
    valid_to DATETIME(6) NULL,
    change_source VARCHAR(32) NOT NULL DEFAULT 'admin',
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_price_history_lowest (variant_id, store_id, market_id, currency, customer_group, valid_from, valid_to, amount_minor),
    KEY idx_price_history_price (price_id, valid_from),
    KEY idx_price_history_market_date (store_id, market_id, currency, valid_from),
    CONSTRAINT fk_price_history_price FOREIGN KEY (price_id) REFERENCES mc_price(id) ON DELETE SET NULL,
    CONSTRAINT fk_price_history_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_history_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_history_market FOREIGN KEY (market_id) REFERENCES mc_market(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_history_price_list FOREIGN KEY (price_list_id) REFERENCES mc_price_list(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_compliance_scheme (
    code VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    record_kind VARCHAR(32) NOT NULL,
    region_scope VARCHAR(32) NOT NULL DEFAULT 'EU',
    expiry_supported TINYINT(1) NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (code),
    KEY idx_compliance_scheme_kind (record_kind, enabled, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mc_compliance_scheme (code, name, record_kind, region_scope, expiry_supported, enabled) VALUES
('ce_marking', 'CE marking', 'marking', 'EU', 0, 1),
('eu_declaration_conformity', 'EU Declaration of Conformity', 'declaration', 'EU', 1, 1),
('certificate', 'Certificate', 'certificate', 'EU_UA', 1, 1),
('test_report', 'Test report', 'test_report', 'EU_UA', 1, 1),
('safety_data_sheet', 'Safety Data Sheet', 'safety_document', 'EU_UA', 1, 1);

CREATE TABLE mc_product_compliance_record (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    scheme_code VARCHAR(64) NOT NULL,
    identifier VARCHAR(255) NULL,
    issuer VARCHAR(255) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'valid',
    issued_at DATE NULL,
    expires_at DATE NULL,
    verification_url VARCHAR(2048) NULL,
    media_id BIGINT UNSIGNED NULL,
    markets_json JSON NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_compliance_record_public_id (public_id),
    KEY idx_product_compliance_record_product (product_id, scheme_code, status, expires_at),
    KEY idx_product_compliance_record_variant (variant_id, scheme_code, status),
    KEY idx_product_compliance_record_identifier (scheme_code, identifier),
    CONSTRAINT fk_product_compliance_record_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_compliance_record_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_compliance_record_scheme FOREIGN KEY (scheme_code) REFERENCES mc_compliance_scheme(code) ON DELETE RESTRICT,
    CONSTRAINT fk_product_compliance_record_media FOREIGN KEY (media_id) REFERENCES mc_media_asset(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_accessibility_profile (
    store_id BIGINT UNSIGNED NOT NULL,
    target_standard VARCHAR(64) NOT NULL DEFAULT 'EN 301 549 / WCAG 2.2 AA',
    conformance_status VARCHAR(24) NOT NULL DEFAULT 'in_progress',
    accessibility_statement_document_id BIGINT UNSIGNED NULL,
    last_audit_at DATETIME(6) NULL,
    next_audit_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (store_id),
    CONSTRAINT fk_accessibility_profile_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_accessibility_profile_statement FOREIGN KEY (accessibility_statement_document_id) REFERENCES mc_legal_document(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_sales_order_checkout_ack (
    order_id BIGINT UNSIGNED NOT NULL,
    legal_document_versions JSON NOT NULL,
    consumer_policy_snapshot JSON NULL,
    payment_obligation_ack_at DATETIME(6) NOT NULL,
    digital_content_performance_consent_at DATETIME(6) NULL,
    digital_content_withdrawal_ack_at DATETIME(6) NULL,
    optional_extras_snapshot JSON NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_id),
    CONSTRAINT fk_sales_order_checkout_ack_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mc_data_retention_policy (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id BIGINT UNSIGNED NOT NULL,
    data_domain VARCHAR(64) NOT NULL,
    retention_days INT UNSIGNED NULL,
    legal_basis VARCHAR(32) NOT NULL,
    disposal_action ENUM('anonymize','delete','retain_while_required') NOT NULL DEFAULT 'anonymize',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_data_retention_policy_scope (store_id, data_domain),
    CONSTRAINT fk_data_retention_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mc_data_subject_request (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    request_type ENUM('access','export','rectification','erasure','restriction','objection') NOT NULL,
    status ENUM('received','identity_verification','in_progress','completed','rejected','cancelled') NOT NULL DEFAULT 'received',
    contact_hash BINARY(32) NULL,
    verification_hash BINARY(32) NULL,
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    due_at DATETIME(6) NULL,
    verified_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_data_subject_request_public_id (public_id),
    KEY idx_data_subject_request_queue (store_id, status, requested_at),
    KEY idx_data_subject_request_customer (customer_id, requested_at),
    CONSTRAINT fk_data_subject_request_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_data_subject_request_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mc_marketing_consent (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    contact_hash BINARY(32) NOT NULL,
    channel ENUM('email','sms','push','messenger') NOT NULL,
    purpose VARCHAR(64) NOT NULL DEFAULT 'marketing',
    status ENUM('granted','withdrawn') NOT NULL,
    policy_version VARCHAR(32) NULL,
    source VARCHAR(64) NULL,
    granted_at DATETIME(6) NULL,
    withdrawn_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_marketing_consent_public_id (public_id),
    UNIQUE KEY uq_marketing_consent_scope (store_id, contact_hash, channel, purpose),
    KEY idx_marketing_consent_customer (customer_id, status),
    CONSTRAINT fk_marketing_consent_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_consent_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


