<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

use Commerce\Modules\Pricing\Infrastructure\EcbExchangeRateSource;
use Commerce\Modules\Pricing\Infrastructure\NbuExchangeRateSource;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/** Stores manual or fetched exchange rates for every base->target pair the stores need. */
final class ExchangeRateService
{
    /** A fetched rate older than this is treated as missing; converted prices are then withdrawn. */
    private const FETCHED_TTL_DAYS = 4;

    public function __construct(private readonly Connection $db, private readonly NbuExchangeRateSource $nbu, private readonly ?EcbExchangeRateSource $ecb = null)
    {
    }

    /** Pairs that need an official rate (auto-converted currencies whose store did not choose a manual rate). @return list<array{base:string,quote:string}> */
    public function requiredPairs(): array
    {
        return array_map(
            static fn (array $r): array => ['base' => strtoupper((string) $r['base']), 'quote' => strtoupper((string) $r['quote'])],
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT s.default_currency base, sc.currency_code quote FROM mc_store s JOIN mc_store_currency sc ON sc.store_id=s.id AND sc.enabled=1 AND sc.auto_convert=1 AND sc.rate_source<>'manual' AND sc.currency_code<>s.default_currency WHERE s.status='active'",
            ),
        );
    }

    public function storeManual(string $base, string $quote, string $rate, ?int $validDays = null): void
    {
        $value = (float) str_replace(',', '.', trim($rate));
        if ($value <= 0 || $value > 1000000 || preg_match('/^[A-Z]{3}$/', $base) !== 1 || preg_match('/^[A-Z]{3}$/', $quote) !== 1 || $base === $quote) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.localization.rate.invalid'));
        }
        $this->insert($base, $quote, $value, 'manual', $validDays !== null && $validDays > 0 ? $validDays : null);
    }

    /**
     * Fetches official rates from every free source (ECB first, then NBU for pairs the ECB does not list) and stores
     * the pairs the stores need. A source that is down is skipped; the last stored rate stays valid until it expires.
     *
     * @return array{provider:string,date:string,stored:int,missing:list<string>}
     */
    public function refresh(): array
    {
        $pairs = $this->requiredPairs();
        $stored = 0;
        $date = '';
        $providers = [];
        $failure = null;

        if ($this->ecb !== null && $pairs !== []) {
            try {
                $table = $this->ecb->fetch();
                $per = $table['per_eur'];
                $left = [];
                foreach ($pairs as $pair) {
                    if (isset($per[$pair['base']], $per[$pair['quote']])) {
                        // 1 base = (units per EUR of quote / units per EUR of base) quote
                        $this->insert($pair['base'], $pair['quote'], $per[$pair['quote']] / $per[$pair['base']], EcbExchangeRateSource::CODE, self::FETCHED_TTL_DAYS, $table['date'] . ' 00:00:00');
                        ++$stored;
                    } else {
                        $left[] = $pair;
                    }
                }
                $providers[] = EcbExchangeRateSource::CODE;
                $date = $table['date'];
                $pairs = $left;
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        $missing = [];
        if ($pairs !== []) {
            try {
                $table = $this->nbu->fetch();
                $rates = $table['uah_per_unit'];
                foreach ($pairs as $pair) {
                    if (!isset($rates[$pair['base']], $rates[$pair['quote']])) {
                        $missing[] = $pair['base'] . '/' . $pair['quote'];
                        continue;
                    }
                    // 1 base = (UAH per base / UAH per quote) quote
                    $this->insert($pair['base'], $pair['quote'], $rates[$pair['base']] / $rates[$pair['quote']], NbuExchangeRateSource::CODE, self::FETCHED_TTL_DAYS, $table['date'] . ' 00:00:00');
                    ++$stored;
                }
                $providers[] = NbuExchangeRateSource::CODE;
                $date = $date !== '' ? $date : $table['date'];
            } catch (\Throwable $e) {
                if ($providers === []) {
                    throw $e;
                }
                foreach ($pairs as $pair) {
                    $missing[] = $pair['base'] . '/' . $pair['quote'];
                }
            }
        } elseif ($providers === [] && $failure !== null) {
            throw $failure;
        }

        return ['provider' => implode('+', $providers), 'date' => $date !== '' ? $date : gmdate('Y-m-d'), 'stored' => $stored, 'missing' => $missing];
    }

    /** @deprecated kept for callers written before the ECB source; use refresh() */
    public function refreshFromNbu(): array
    {
        return $this->refresh();
    }

    /** @return list<array<string,mixed>> latest rate per pair, newest first */
    public function latest(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT r.base_currency,r.quote_currency,r.rate,r.provider,r.observed_at,r.expires_at FROM mc_exchange_rate r
             JOIN (SELECT base_currency,quote_currency,MAX(id) id FROM mc_exchange_rate GROUP BY base_currency,quote_currency) l ON l.id=r.id
             ORDER BY r.base_currency,r.quote_currency',
        );
    }

    private function insert(string $base, string $quote, float $rate, string $provider, ?int $validDays, ?string $observedAt = null): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $observed = $observedAt !== null ? new DateTimeImmutable($observedAt, new DateTimeZone('UTC')) : $now;
        $this->db->executeStatement(
            'INSERT INTO mc_exchange_rate (base_currency,quote_currency,rate,provider,observed_at,expires_at,created_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE rate=VALUES(rate),expires_at=VALUES(expires_at)',
            [$base, $quote, sprintf('%.12F', $rate), $provider, $observed->format('Y-m-d H:i:s.u'), $validDays !== null ? $observed->modify('+' . $validDays . ' days')->format('Y-m-d H:i:s.u') : null, $now->format('Y-m-d H:i:s.u')],
        );
    }
}
