<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

use Doctrine\DBAL\Connection;

/** Remembers when each rate provider was last asked and whether it answered, so the admin can show a real status. */
final readonly class ExchangeRateStatusStore
{
    public function __construct(private Connection $db)
    {
    }

    public function success(string $provider, string $rateDate, int $stored): void
    {
        $this->write($provider, true, $rateDate, $stored, null);
    }

    public function failure(string $provider, \Throwable|string $error): void
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;
        $this->write($provider, false, null, 0, mb_substr(trim($message) !== '' ? trim($message) : 'error', 0, 480, 'UTF-8'));
    }

    /** @return array<string,array{provider:string,last_attempt_at:string,last_success_at:?string,last_rate_date:?string,last_error:?string,pairs_stored:int,consecutive_failures:int}> */
    public function all(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT provider,last_attempt_at,last_success_at,last_rate_date,last_error,pairs_stored,consecutive_failures FROM mc_exchange_rate_provider_status');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['provider']] = [
                'provider' => (string) $row['provider'],
                'last_attempt_at' => substr((string) $row['last_attempt_at'], 0, 16),
                'last_success_at' => $row['last_success_at'] !== null ? substr((string) $row['last_success_at'], 0, 16) : null,
                'last_rate_date' => $row['last_rate_date'] !== null ? (string) $row['last_rate_date'] : null,
                'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
                'pairs_stored' => (int) $row['pairs_stored'],
                'consecutive_failures' => (int) $row['consecutive_failures'],
            ];
        }

        return $out;
    }

    private function write(string $provider, bool $ok, ?string $rateDate, int $stored, ?string $error): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        try {
            if ($ok) {
                $this->db->executeStatement(
                    'INSERT INTO mc_exchange_rate_provider_status (provider,last_attempt_at,last_success_at,last_rate_date,last_error,pairs_stored,consecutive_failures) VALUES (?,?,?,?,NULL,?,0)
                     ON DUPLICATE KEY UPDATE last_attempt_at=VALUES(last_attempt_at),last_success_at=VALUES(last_success_at),last_rate_date=VALUES(last_rate_date),last_error=NULL,pairs_stored=VALUES(pairs_stored),consecutive_failures=0',
                    [$provider, $now, $now, $rateDate, $stored],
                );

                return;
            }
            $this->db->executeStatement(
                'INSERT INTO mc_exchange_rate_provider_status (provider,last_attempt_at,last_error,pairs_stored,consecutive_failures) VALUES (?,?,?,0,1)
                 ON DUPLICATE KEY UPDATE last_attempt_at=VALUES(last_attempt_at),last_error=VALUES(last_error),consecutive_failures=consecutive_failures+1',
                [$provider, $now, $error],
            );
        } catch (\Throwable) {
            // Status is informational: a missing table on a half-migrated install must never break a rate refresh.
        }
    }
}
