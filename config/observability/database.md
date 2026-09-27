# Database observability baseline

Run `php bin/console commerce:db:observe` for a safe snapshot of connection, slow-query, InnoDB read and lock counters. Production monitoring must additionally retain slow-query logs/APM traces and calculate p50/p95/p99 over time.

Watch at minimum: active/maximum connections, Threads_running, Slow_queries, buffer-pool hit rate, row lock waits/time, deadlocks, DB CPU/IO, query p95/p99, PHP-FPM saturation and durable queue lag.
