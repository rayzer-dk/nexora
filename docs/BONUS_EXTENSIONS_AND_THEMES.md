# Bonus extensions and themes

The `bonus/` directory is optional and is never auto-installed.

Extension API examples:

- Product Trust Badge: declarative extension, no executable code.
- Remote CRM Connector Starter: remote_app connector skeleton. It demonstrates local installation plus HTTPS/API credentials. It does not download PHP from the developer server.
- Store Health Endpoint: Nexora-signed trusted_release example with executable PHP and a minimal safe route.

Optional themes:

- Nexora Market: dense commerce/catalog presentation.
- Nexora Atelier: premium, spacious presentation.
- Nexora Soft: rounded, softer presentation.

Built-in presets Modern, Marketplace, Premium, Minimal and Soft require no extension installation and can be further customized in Appearance.

Commercial extensions use `commercial.model`: `free`, `one_time`, `subscription` or `external`. Commercial entitlement belongs to that extension/vendor; Core must remain operable if the vendor licensing service is unavailable. A remote_app package is installed locally and communicates with the vendor service over authenticated HTTPS. It is not a mechanism for remotely executing downloaded code.
