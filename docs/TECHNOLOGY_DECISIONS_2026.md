# Technology decisions for the current platform line

The September 2026 architecture review was applied selectively rather than as a stack rewrite.

PHP syntax baseline stays 8.4 while CI/runtime targets PHP 8.4 and 8.5. PHP 8.5-only syntax is not used in Core because doing so would break the supported 8.4 runtime. Symfony 8.1 components already used by the project remain; downgrading to Symfony 7 would add risk without architectural benefit.

Doctrine DBAL plus explicit repositories/migrations remains the persistence model. A full Doctrine ORM migration is not required to get safe boundaries and would make catalog/filter/stock SQL less transparent. MySQL/MariaDB remains the required zero-extra-service database. PostgreSQL is not introduced as a second mandatory persistence platform in this release.

The default storefront stays SSR-first Twig plus progressive Vue/JavaScript enhancement. It remains functional without client-side JavaScript for critical commerce flows. A mandatory Next.js/Nuxt/SvelteKit deployment, separate Node server or Kubernetes cluster would violate the install-and-sell objective. Headless clients can use API v1; GraphQL can be added as an optional read facade.

Redis/Valkey and Meilisearch/OpenSearch are future optional accelerators/providers. The small/medium-store baseline must remain correct with PHP + MySQL/MariaDB only. Redis or an external search engine must never become a reason the storefront cannot boot.

Docker, blue/green infrastructure and Kubernetes are deployment options for larger installations, not prerequisites. Core Update continues to focus on signed packages, verified recovery points, maintenance barriers, smoke probes and rollback, while immutable `releases/current/shared` deployment remains a production-hardening target to certify with failure-injection tests.
