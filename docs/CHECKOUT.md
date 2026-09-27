# Checkout architecture v0.2

The checkout is fulfillment-driven, not address-driven.

## Core rule

A customer does not have a mandatory checkout address. The checkout asks only for data required by the actual order.

- Digital-only cart: no shipping block and no postal address.
- Physical cart: a fulfillment method is required.
- Mixed physical + digital cart: fulfillment is required only for physical lines; email may additionally be required for digital delivery.
- Pickup branch / parcel locker: store the selected provider point, not a duplicate street address.
- Courier delivery: request an address only because this selected service requires one.
- Store pickup: request only the selected store/pickup location.

A postal address is a fulfillment destination, not a mandatory customer-profile property.

## Identity

Identity requirements are context-driven:

- Guest checkout remains available.
- Google/OpenID Connect can identify and prefill trusted profile fields.
- Passkeys/WebAuthn support passwordless return visits.
- Phone is requested only when shipping/payment/business rules require it.
- Email is requested only when digital delivery, payment, notification or legal rules require it.
- A returning authenticated customer should reuse verified contact data without re-entry.

## Fulfillment provider model

Every carrier implements the same DeliveryProviderInterface and declares capabilities:

- pickup points
- parcel lockers
- courier delivery
- store pickup
- freight
- live rates
- shipment creation
- tracking
- returns
- webhooks

Provider-specific branch/locker data is normalized to DeliveryPoint so the storefront can render one consistent picker.

Example providers that can be implemented as adapters:

- Nova Post / Nova Poshta
- InPost
- DHL
- DPD
- GLS
- UPS
- FedEx
- PostNord
- Bring
- local courier or store pickup provider

The platform must not scrape branch lists when an official API exists. API credentials and provider-specific behavior remain isolated inside the provider adapter.

## Minimal checkout flow

Digital product:

Identity/email -> payment -> completed

Physical product to pickup point:

Identity -> carrier/service -> branch or locker -> payment -> completed

Returning customer with remembered identity and fulfillment:

Confirm saved fulfillment -> payment -> completed

## UX rules

- Never ask for the same information twice.
- Never render an address form before the selected service proves it is needed.
- No page reload for rate calculation, point search, promo codes or payment changes.
- Search delivery points by city/name/postcode and, where supported, geolocation.
- Remember recent fulfillment destinations per authenticated customer.
- Do not silently select a paid shipping service if several materially different options exist.
- Show final shipping cost and ETA before payment confirmation.
- Keep checkout viable with JavaScript failures where practical; payment-provider constraints may require JS.
