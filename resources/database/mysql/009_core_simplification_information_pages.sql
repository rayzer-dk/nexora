-- Nexora Commerce 1.3.0
-- Schema v9: keep Core universal and compact. Remove niche traceability/recall/energy/DPP tables
-- introduced during development. Safe Update must create a checkpoint before this migration.

DROP TABLE IF EXISTS mc_product_recall_lot;
DROP TABLE IF EXISTS mc_product_recall_translation;
DROP TABLE IF EXISTS mc_product_recall;
DROP TABLE IF EXISTS mc_sales_order_item_trace;
DROP TABLE IF EXISTS mc_inventory_lot;
DROP TABLE IF EXISTS mc_product_traceability_policy;
DROP TABLE IF EXISTS mc_product_digital_passport;
DROP TABLE IF EXISTS mc_product_energy_profile;

DELETE FROM mc_compliance_scheme
WHERE code IN ('eprel','energy_label','digital_product_passport','epr_packaging','weee','battery');

DROP TABLE IF EXISTS mc_product_customs_profile;
DROP TABLE IF EXISTS mc_market_cross_border_policy;

