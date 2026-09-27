# Domain events and Transactional Outbox

Nexora Commerce 2.9.0 persists business events in `mc_outbox_event` through the same Doctrine DBAL connection and transaction that commits the business change. Checkout, payment and catalog code never executes optional listeners inline.

Core event contracts live in `resources/event-contracts/v1/`. Every event has a stable name, numeric event version, immutable UUID, aggregate type/id, UTC timestamp, payload and metadata. Current v1 events include order placed/cancelled/completed, payment status changed, catalog product created/updated and customer registered.

`DomainEventOutboxWorker` claims a bounded batch, creates a per-subscriber delivery ledger in `mc_domain_event_delivery`, and retries each subscriber independently. A permanently failing subscriber becomes `dead` after a bounded retry count; other subscribers and the original order/payment remain valid.

The HTTP runtime also performs a small post-response drain only when the current request actually created deferred work. This keeps a default installation useful without requiring Redis or RabbitMQ. Production sites should additionally run `php bin/console commerce:work --limit=50` from a supervised worker or cron so delayed retries are processed even when no new write request occurs.

External transports remain optional. Redis/RabbitMQ may later be used behind adapters, but the durable database outbox is the correctness boundary because MySQL/MariaDB is already required by the platform.
