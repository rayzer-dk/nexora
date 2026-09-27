ALTER TABLE mc_sales_order
    ADD COLUMN checkout_idempotency_key VARCHAR(190) NULL AFTER order_number,
    ADD UNIQUE KEY uq_sales_order_checkout_idempotency (checkout_idempotency_key);
