-- Payment lifecycle hardening for online acquiring and manual settlement.
ALTER TABLE mc_payment
    ADD COLUMN provider_modified_at DATETIME(6) NULL AFTER provider_reference,
    ADD COLUMN paid_at DATETIME(6) NULL AFTER provider_modified_at,
    ADD COLUMN failed_at DATETIME(6) NULL AFTER paid_at,
    ADD COLUMN cancelled_at DATETIME(6) NULL AFTER failed_at,
    ADD COLUMN refunded_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_minor,
    ADD COLUMN failure_reason VARCHAR(500) NULL AFTER status,
    ADD COLUMN provider_payload JSON NULL AFTER metadata,
    ADD KEY idx_payment_provider_modified (provider_code, provider_reference, provider_modified_at);

CREATE TABLE mc_payment_refund (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    provider_reference VARCHAR(190) NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    provider_payload JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_refund_public (public_id),
    UNIQUE KEY uq_payment_refund_idempotency (idempotency_key),
    KEY idx_payment_refund_payment_created (payment_id, created_at),
    CONSTRAINT fk_payment_refund_payment FOREIGN KEY (payment_id) REFERENCES mc_payment(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
