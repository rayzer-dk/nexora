# Multilingual product import/export — 3.5.0

Nexora supports one-file multilingual product exchange in the Universal Import Wizard and multilingual CSV export.

Base columns remain normal commerce fields such as `sku`, `price`, `currency`, stock, GTIN, MPN, brand and categories.

Translated fields use locale-qualified headings:

`name[uk-UA]`
`slug[uk-UA]`
`short_description[uk-UA]`
`description[uk-UA]`
`meta_title[uk-UA]`
`meta_description[uk-UA]`

The same fields may be repeated for every enabled store locale, for example `name[en-US]`, `name[de-DE]` or `description[pl-PL]`.

When the normal `name` column is absent, the Universal Import Wizard may use the current store locale's `name[locale]` as the base product name. Other detected locale columns are applied to their matching product translations after the base row is created/updated.

The multilingual export emits all enabled store locales in one CSV. The existing single-locale CSV export remains available for simple integrations.

OpenCart/ocStore migration remains the preferred path when full relational data must be transferred. The Universal Import Wizard is intended for CSV/XLSX product files and can map common OpenCart-style headings.
