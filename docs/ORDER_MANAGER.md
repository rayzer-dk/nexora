# Order Manager

Order Manager is an operational surface over immutable order snapshots. Product names, SKU, quantities and prices already captured by an order are never rewritten when the catalog changes.

## State rules

Order state, payment state and fulfillment state are deliberately separate.

A shipment may progress from `pending` / `unfulfilled` to `preparing`, `ready_for_pickup`, `shipped` and `delivered`. Invalid backwards transitions are rejected server-side. `shipped` and `delivered` require a tracking number. Cancelled or fully refunded orders cannot have delivery edited.

An order can be marked `completed` only when payment is `paid` and fulfillment is either `delivered` or `not_required` for a digital order.

Manual payment confirmation is limited in the administration controller to bank transfer and cash on delivery. Online acquiring remains provider/webhook-driven.

## Refunds

Online providers implementing `OnlinePaymentProviderInterface` may support full or partial refunds. The service subtracts both already finalized refunds and pending/processing refund requests before accepting another amount, preventing concurrent over-refunds. Refund requests use a unique idempotency key and are stored in `mc_payment_refund` before the external provider call.

A finalized partial refund sets payment/order payment state to `partially_refunded`. A full refund sets both to `refunded`. A repeated stale `success` payment webhook cannot overwrite a partially refunded/refunded payment back to `paid`.

## Notifications

Customer status messages are queued through `mc_notification_outbox`. SMTP or Telegram failures do not roll back an order mutation. The worker retries with bounded exponential backoff and recovers stale processing locks after a crashed worker.

The order page shows the recent notification state and allows a manager to queue the current order status again.

## Internal notes

Internal notes are written as `admin.note` order events. They are part of the operational history and are not sent to the customer.
