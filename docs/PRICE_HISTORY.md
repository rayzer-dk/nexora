# Price History

Price history is not inferred from logs. Every effective sell-price interval can be persisted in `mc_price_history` with variant, store, market, price list, currency, customer group, tax mode and validity period.

This enables a deterministic lowest-prior-price calculation for EU price-reduction announcements and also supports analytics, audit and migration validation. Historical price data is separate from current hot-price resolution so storefront reads remain fast.
