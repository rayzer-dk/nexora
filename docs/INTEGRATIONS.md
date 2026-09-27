# Integration Hub

External platforms connect through CommerceConnectorInterface and capability declarations.

Planned first-party connector families:
- OpenCart / ocStore migration and bridge;
- Shopify;
- WooCommerce;
- Shopware;
- ERP/CRM connectors;
- marketplaces;
- accounting/fiscal systems;
- shipping/payment providers.

A connector does not receive unrestricted database access.

Each connector declares capabilities such as products.read, inventory.write or orders.read. Permissions, credentials, rate limits, retry policy, idempotency and webhook signatures are managed by the Integration Hub.
