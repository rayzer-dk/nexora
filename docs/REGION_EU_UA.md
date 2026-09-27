# Europe + Ukraine regional profile

The platform distribution is intentionally scoped to Europe and Ukraine.

Core principles:

- Do not load US-, China-, Japan- or other unrelated country business modules by default.
- Country-specific functionality lives in regional packages/adapters and never pollutes the core domain model.
- EU/EEA and Ukraine are first-class targets for languages, currencies, taxes, privacy, payments, carriers, Merchant Center and marketplace connectors.
- A shop may enable only the countries it actually sells to.
- Unsupported countries can be added later as isolated regional packs without changing Catalog, Order or Checkout internals.

The profile currently includes EU member states, Ukraine, EEA countries and Switzerland as the intended European operating area. The exact country list used by a merchant remains configurable.
