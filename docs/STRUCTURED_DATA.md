# Structured data

Structured data is emitted as server-rendered JSON-LD and is built from the same canonical product/content model used by storefront, Merchant and AI projections.

Core builders cover Organization, WebSite, BreadcrumbList, Product/Offer merchant listings, ProductGroup variants, Review/AggregateRating, ShippingService/MerchantReturnPolicy data and BlogPosting. ImageObject/VideoObject data can be nested when the actual media exists. LocalBusiness is emitted only when a real physical business location is configured.

Do not output schema simply because a type exists. Markup must describe visible/real page data. FAQ markup can remain semantic for actual FAQ content but is not treated as a general ecommerce rich-result strategy.
