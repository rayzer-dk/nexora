# Tax / VAT architecture

Tax is a protected system module. Prices, tax classes, jurisdiction rules and storefront presentation are separate concerns.

## Default EU/UA storefront policy

For a consumer storefront in Ukraine or the EU, the prominent product price is the final payable gross price. A store may additionally show the included VAT amount/rate. Merchant/Google projections use the same gross amount so landing page, checkout and product data do not diverge.

Supported presentation modes:
- `gross`: final price only;
- `gross_with_breakdown`: final price plus an `including VAT` line;
- `net_with_gross`: B2B-oriented net price plus the final gross price;
- `net`: available only for explicitly configured legal/business contexts and must not silently become the B2C EU/UA default.

Tax rates are effective-dated data, not constants in application code. The schema seeds tax classes but intentionally does not hard-code mutable country rates. A deployment can import/maintain current rates and validate them against its accountant/tax integration.

## Calculation

Money remains integer minor units. Tax uses integer basis points (1 basis point = 0.01 percentage point) and integer rounding. PHP float is not used for monetary tax calculations.

## Historical integrity

Orders snapshot net, tax, gross, tax class and tax rate. Later rate or product-class changes never rewrite historical orders.

## EU B2B

Customer tax profiles can carry company/VAT identifiers and validation state. Reverse-charge or other exemptions must be resolved by a dedicated jurisdiction policy, not by changing the displayed product price globally.
