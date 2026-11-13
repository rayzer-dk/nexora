# Nexora Commerce — Schema.org

Built-in JSON-LD provides structured descriptions of the online store, canonical website, catalog products, categories, information pages, blog articles, breadcrumbs and opt-in merchant shipping/returns policies.

## Settings

Open **System → Store data → Schema.org settings**, or directly open `/admin/system/merchant-schema` as an authorized administrator. The route requires the existing `system.settings` permission and a valid CSRF token.

The **Built-in JSON-LD** switch controls Nexora's core store, product, category and content markup only. It does not disable checkout shipping methods, payment calculations, redirects, SEO URLs, canonical, hreflang, sitemaps or third-party markup.

Merchant profiles reuse the store identity settings (legal name, VAT ID, email, telephone) and the storefront contact/social settings. The web-facing brand and profiles should be real and consistent.

## ShippingService

Shipping policy is **off** until configured and enabled per store. Enter the actual destination countries, ISO 4217 currency, *unconditional* fixed delivery charge, handling and transit day ranges.

Separate **OfferShippingDetails** switch is off by default. Only enable if **all physical products** share the same fixed rate and delivery terms. It is omitted when currencies differ or the product is digital. Product schema requires genuine product photography and does not include a generic placeholder image. Prices in Merchant Center and the visible storefront must remain consistent.

Delivery-policy links are added only when that store has an accessible, published, non-noindex **Delivery** page in the current locale.

For variable rates, markets, pickup methods, carriers, order thresholds, discounts or weight-based shipping use the actual checkout integration and [Google Merchant Center](https://merchants.google.com/) shipping settings rather than claiming a universal flat rate.

## MerchantReturnPolicy

Return policy is also **off** until enabled. Configure applicable countries, a finite/unlimited/prohibited policy (when lawful), days, return channel and fees. A fixed return shipping charge needs both an amount and currency.

The generated OnlineStore node includes the policy once. A return-policy link appears only when the matching **Returns** information page is published in the current locale. Exceptions for individual products need separate truthful product-level rules.

## CMS pages and blog articles

Admin content forms offer a Schema.org type under SEO. Information pages can be WebPage, AboutPage, ContactPage, FAQPage, CollectionPage, Article or no page-specific markup. Articles can be BlogPosting, Article, NewsArticle or no article-specific markup. **Automatic** remains the default for existing content. Selection is persistent across translations and retained during content duplication.

No specific schema toggle is required for products, categories or the home page. They are handled automatically. Sitemap and canonical rules remain separate from Schema.org.

## Reliability

Policies are stored in `mc_configuration_revision`, scoped by store, with a revision history. Missing, disabled or corrupt policies do not produce fictitious shipping or return claims. The service caches validated policies for the duration of the request. The graph generator removes nodes with identical identities. It cannot prevent other independent extensions from outputting duplicate JSON-LD later; disable Nexora's built-in schema if another full-service generator is in use.

Code verification: `php bin/schema-org-regression.php`; CI workflow: `.github/workflows/schema-org.yml`. After deployment run DB migrations, verify public HTML and inspect representative URLs in [Rich Results Test](https://search.google.com/test/rich-results) and [Schema.org Validator](https://validator.schema.org/).

**Back up the site and database before deploying or updating.**
