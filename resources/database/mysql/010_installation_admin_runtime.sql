-- Nexora Commerce 1.4.0
-- Installation/runtime schema: admin identity, store legal/contact profile and installation state.

CREATE TABLE mc_installation (
    id TINYINT UNSIGNED NOT NULL,
    public_id BINARY(16) NOT NULL,
    install_id BINARY(16) NOT NULL,
    platform_version VARCHAR(32) NOT NULL,
    extension_api_version VARCHAR(32) NOT NULL,
    installed_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_installation_public_id (public_id),
    UNIQUE KEY uq_installation_install_id (install_id),
    CONSTRAINT chk_installation_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_admin_user (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    email VARCHAR(320) NOT NULL,
    email_normalized VARCHAR(320) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    roles JSON NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_user_public_id (public_id),
    UNIQUE KEY uq_admin_user_email (email_normalized),
    KEY idx_admin_user_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mc_store_profile (
    store_id BIGINT UNSIGNED NOT NULL,
    legal_name VARCHAR(255) NULL,
    registration_number VARCHAR(128) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'UA',
    registration_address VARCHAR(1000) NULL,
    email VARCHAR(320) NULL,
    phone VARCHAR(64) NULL,
    privacy_contact VARCHAR(320) NULL,
    return_contact VARCHAR(320) NULL,
    warranty_contact VARCHAR(320) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (store_id),
    CONSTRAINT fk_store_profile_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
