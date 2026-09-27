# Text, Unicode and database encoding

The platform is UTF-8 only.

Application:
- all source files: UTF-8 without BOM;
- all HTTP JSON: UTF-8;
- all database text: utf8mb4;
- user-facing strings are validated as UTF-8 and normalized to Unicode NFC at input boundaries;
- PHP ext-intl is mandatory for normalization, locale and international text handling;
- identifiers and security tokens are not locale-collated.

Database collation is selected by the installer instead of being hard-coded globally:
- modern MySQL: prefer utf8mb4_0900_ai_ci where suitable;
- modern MariaDB: prefer an available UCA 14 collation where suitable;
- safe cross-engine fallback: utf8mb4_unicode_520_ci.

Indexes requiring exact byte semantics use binary/case-sensitive columns or hashes instead of language collation.

utf8mb4 remains the correct representation for full Unicode. NFC normalization prevents visually identical strings from being stored in multiple canonical forms.
