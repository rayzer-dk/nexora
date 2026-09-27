# Fulfillment architecture

Fulfillment is a first-class domain separate from Customer and Address.

## Why

Traditional commerce systems attach billing/shipping address fields directly to the checkout form. That creates duplicated data and forces irrelevant fields on pickup-point and digital orders.

Nexora Commerce instead models:

Customer identity + Cart requirements + Fulfillment selection + Payment.

## Product fulfillment types

Physical: requires physical fulfillment.
Digital: no physical fulfillment; usually requires a delivery identity such as email/account.
Service: does not require shipping by default; a future booking/scheduling module may add its own requirements.

A cart resolver derives checkout requirements server-side from the actual line items. The browser cannot turn physical shipping requirements off.

## Provider boundary

Carrier integrations are adapters. Core checkout only knows the normalized contract.

Provider adapter responsibilities:

- authenticate to carrier API
- list/search service points and parcel lockers
- calculate rates
- normalize carrier identifiers
- create/cancel shipment where supported
- retrieve labels where supported
- track shipment
- process provider webhooks
- translate carrier errors into normalized domain errors

## Data retention

Provider point IDs are saved with a snapshot of the human-readable label used for the order. Current branch catalogs should be queried from the provider rather than permanently copied unless the carrier terms and operational requirements allow caching.

Secrets are never exposed to the storefront. All carrier API calls requiring credentials run server-side.

## Location catalog policy

Location data is not treated the same for every carrier.

Each provider declares a LocationCatalogMode:

LiveQuery: query the carrier at selection time and do not persist the catalog.
CachedQuery: short-lived caching is allowed.
SyncedDirectory: maintain a local provider-approved directory and refresh it on schedule.

Nova Post is modeled as SyncedDirectory because its integration guidance recommends keeping the branch/locker directory up to date with regular full refreshes. Other providers may impose different storage rules; their adapters must enforce those rules.

This separation gives fast checkout where local synchronization is allowed without violating a carrier's API/data terms where it is not.
