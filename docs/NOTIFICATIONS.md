# Notification Center

Notification is a protected core subsystem; transports are optional.

Built-in transports in 0.6: responsive HTML email through Symfony Mailer and Telegram Bot API. In-app and Web Push are already reserved as channel contracts and can be added without changing Orders.

Order/checkout code enqueues notifications into `mc_notification_outbox`. The bounded worker sends them later:

`php bin/console commerce:notifications:work --limit=50`

An SMTP or Telegram outage therefore cannot cancel a successful order. The worker claims rows atomically, retries with exponential backoff and stops after a bounded number of failures.

Email templates live in the theme and are replaceable without touching notification business logic. Default templates use conservative responsive email HTML instead of frontend frameworks.

Telegram tokens are secrets and must never be committed to the repository.

## Worker crash recovery
Rows are claimed with a random lock token and timestamp. A worker restart automatically releases processing locks older than 15 minutes, so a server crash cannot leave an email or Telegram notification stuck forever.

## 2.9 deferred-work integration

Order creation now publishes a transactional `commerce.order.placed` domain event. The notification subscriber runs only after commit and enqueues idempotent email/Telegram rows. A short post-response drain attempts immediate delivery on a default installation; `php bin/console commerce:work --limit=50` is the recommended production worker/retry command.
