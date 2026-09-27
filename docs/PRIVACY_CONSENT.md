# Privacy & Consent

The platform uses an EU/EEA-first consent model.

Strictly necessary storage is limited to functions explicitly requested by the shopper, such as session security, authentication, cart state, checkout and storing the shopper's privacy choice. Optional preferences, analytics and marketing are disabled by default until the shopper makes a choice.

The storefront consent surface must provide Accept all, Reject optional and granular settings without using dark patterns. Withdrawal must remain available from the storefront after the initial choice. Optional categories are never preselected.

Google Consent Mode v2 is supported through the platform consent state. The default EEA state is denied for analytics_storage, ad_storage, ad_user_data and ad_personalization, while security_storage remains granted. Google/third-party scripts that require optional consent must be kept inert until the corresponding category is granted.

Consent receipts are versioned against the active policy and store a pseudonymous subject hash rather than a raw IP address. Policy text itself is versioned through Legal Document Center so an audit can identify what the shopper was shown at the time of consent.

A cookie registry stores provider, purpose, category, duration and first/third-party status. Cookie-policy pages should be generated from this registry to prevent documentation drifting away from actual configuration.

GDPR data rights and retention

The privacy subsystem also contains first-class records for data-subject requests, retention policies and marketing consent. Access/export/rectification/erasure/restriction/objection requests are tracked independently from newsletter consent. Marketing consent is purpose- and channel-specific and withdrawal does not delete an order or other records that must be retained under another legal basis.

Retention is configured per data domain. Disposal is explicit: anonymize, delete, or retain while legally required. Core code must never use a blanket "delete customer row" operation as a substitute for a GDPR workflow.
