# Architecture guardrails

The platform remains a modular monolith. Deploying one application is the default; domain boundaries are enforced in code and tests.

Domain/Application code should depend on another module through Contract interfaces, not through that module's Infrastructure or HTTP implementation. `tests/ModuleBoundaryTest.php` scans imports and rejects new unreviewed cross-module Infrastructure edges. A small existing baseline is listed explicitly in `config/architecture/module_boundaries.json` and should shrink over time.

Heavy/optional side effects are triggered after a durable domain event is committed. Failure of mail, Telegram, analytics or a future remote extension must not roll back an order/payment/catalog write.

Third-party extensions do not overwrite Core. Declarative packages are staged, executable packages are quarantined, and signed trusted code travels only through the Core release/update channel.
