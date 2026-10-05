# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.65.0 — 2026-10-05

Audit of tax, SEO and import: standard VAT rates, SEO templates, weight and brand in the CSV import.

- Tax: "Standard rate of a country" adds the standard VAT rate of a country (30 countries) for the Standard class in one click.
- SEO: Admin → System → SEO templates — default page title and description per language for products, categories, articles and pages without their own; variables {name} {store} {price} {brand} {category} {sku}.
- Import: CSV columns weight_kg, length_mm, width_mm, height_mm and brand (by name) are understood.
- Audit result without changes needed: anti-fraud (score, velocity, blocklist, device confirmation, auto-block on a fraud decision), blog and page forms (schedule, tags, canonical, product links), form builder (10 field types, files, e-mail notice, export).
