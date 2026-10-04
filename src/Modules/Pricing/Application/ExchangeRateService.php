<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Stores manual or fetched exchange rates for every base->target pair the stores need.
 *
 * Rate source of a currency (mc_store_currency.rate_source):
 *  - auto   official rates, ECB first, NBU for what the ECB does not publish (the default, suits UAH and EUR shops);
 *  - ecb, nbu, nbp, cnb   exactly that central bank (cross rates through its pivot currency: EUR, UAH, PLN, CZK);
 *  - api    a commercial rate API with the merchant's own key (pivot USD);
 *  - manual the merchant types the rate; nothing is fetched.
 */
final class ExchangeRateService
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';
    /** Order tried by the "auto" source. */
    public const AUTO_ORDER = ['ecb', 'nbu'];
    /** A fetched rate older than this is treated as missing; converted prices are then withdrawn. */
    private const FETCHED_TTL_DAYS = 4;

    /** @var array<string,ReferenceRateSourceInterface> */
    private array $sources = [];

    /** @param iterable<ReferenceRateSourceInterface> $sources */
    public function __construct(
        private readonly Connection $db,
        #[AutowireIterator('commerce.rate_source')] iterable $sources,
        private readonly ?ExchangeRateStatusStore $status = null,
        private readonly ?\Commerce\Core\Extension\ExtensionServiceRegistry $extensions = null,
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->code()] = $source;
        }
    }

    /**
     * Built-in sources plus those of signed modules (`provider.exchange_rate`); a built-in code cannot be replaced.
     *
     * @return array<string,ReferenceRateSourceInterface>
     */
    private function sources(): array
    {
        $sources = $this->sources;
        foreach ($this->extensions?->all('provider.exchange_rate') ?? [] as $source) {
            if ($source instanceof ReferenceRateSourceInterface && preg_match('/^[a-z0-9_]{2,20}$/D', $source->code()) === 1 && !isset($this->sources[$source->code()])) {
                $sources[$source->code()] = $source;
            }
        }

        return $sources;
    }

    /** @return list<string> every value a currency's rate source may take, in display order */
    public function sourceChoices(): array
    {
        $choices = [self::SOURCE_AUTO];
        foreach (['ecb', 'nbu', 'nbp', 'cnb', 'api'] as $code) {
            if (isset($this->sources()[$code])) {
                $choices[] = $code;
            }
        }
        foreach (array_keys($this->sources()) as $code) {
            if (!in_array($code, $choices, true)) {
                $choices[] = $code;
            }
        }
        $choices[] = self::SOURCE_MANUAL;

        return $choices;
    }

    public function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));

        return in_array($source, $this->sourceChoices(), true) ? $source : self::SOURCE_AUTO;
    }

    /** Pairs that need an official rate (auto-converted currencies whose store did not choose a manual rate). @return list<array{base:string,quote:string,source:string}> */
    public function requiredPairs(?int $storeId = null): array
    {
        $sql = "SELECT DISTINCT s.default_currency base, sc.currency_code quote, sc.rate_source source FROM mc_store s JOIN mc_store_currency sc ON sc.store_id=s.id AND sc.enabled=1 AND sc.auto_convert=1 AND sc.rate_source<>'manual' AND sc.currency_code<>s.default_currency WHERE s.status='active'";
        $params = [];
        if ($storeId !== null) {
            $sql .= ' AND s.id=?';
            $params[] = $storeId;
        }

        return array_map(
            fn (array $r): array => ['base' => strtoupper((string) $r['base']), 'quote' => strtoupper((string) $r['quote']), 'source' => $this->normalizeSource((string) $r['source'])],
            $this->db->fetchAllAssociative($sql, $params),
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
     * Works out the rate of every pair from the chosen source, fetching each needed publisher at most once.
     * Pure with respect to the database: nothing is written here.
     *
     * @param list<array{base:string,quote:string,source:string}> $pairs
     * @return array{rates:list<array{base:string,quote:string,rate:float,provider:string,date:string}>,missing:list<string>,fetched:array<string,ReferenceRateTable>,errors:array<string,string>}
     */
    public function resolvePairs(array $pairs): array
    {
        $fetched = [];
        $errors = [];
        $rates = [];
        $missing = [];
        $table = function (string $code) use (&$fetched, &$errors): ?ReferenceRateTable {
            if (isset($fetched[$code])) {
                return $fetched[$code];
            }
            if (isset($errors[$code]) || !isset($this->sources()[$code])) {
                return null;
            }
            try {
                return $fetched[$code] = $this->sources()[$code]->table();
            } catch (\Throwable $e) {
                $errors[$code] = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

                return null;
            }
        };
        foreach ($pairs as $pair) {
            $order = $pair['source'] === self::SOURCE_AUTO ? self::AUTO_ORDER : [$pair['source']];
            foreach ($order as $code) {
                $published = $table($code);
                $rate = $published?->cross($pair['base'], $pair['quote']);
                if ($published !== null && $rate !== null && $rate > 0) {
                    $rates[] = ['base' => $pair['base'], 'quote' => $pair['quote'], 'rate' => $rate, 'provider' => $code, 'date' => $published->date];
                    continue 2;
                }
            }
            $missing[] = $pair['base'] . '/' . $pair['quote'];
        }

        return ['rates' => $rates, 'missing' => $missing, 'fetched' => $fetched, 'errors' => $errors];
    }

    /**
     * Fetches official rates from the sources the stores chose and stores the pairs they need. A source that is down is skipped
     * (and its error remembered for the admin); the last stored rate stays valid until it expires.
     *
     * @return array{provider:string,date:string,stored:int,missing:list<string>,errors:array<string,string>}
     */
    public function refresh(?int $storeId = null): array
    {
        $pairs = $this->requiredPairs($storeId);
        $result = $this->resolvePairs($pairs);
        $storedBy = [];
        foreach ($result['rates'] as $row) {
            $this->insert($row['base'], $row['quote'], $row['rate'], $row['provider'], self::FETCHED_TTL_DAYS, $row['date'] . ' 00:00:00');
            $storedBy[$row['provider']] = ($storedBy[$row['provider']] ?? 0) + 1;
        }
        foreach ($result['fetched'] as $code => $published) {
            $this->status?->success($code, $published->date, $storedBy[$code] ?? 0);
        }
        foreach ($result['errors'] as $code => $message) {
            $this->status?->failure($code, $message);
        }
        if ($result['rates'] === [] && $pairs !== [] && $result['fetched'] === [] && $result['errors'] !== []) {
            throw new \RuntimeException((string) reset($result['errors']));
        }
        $dates = array_map(static fn (array $r): string => $r['date'], $result['rates']);

        return [
            'provider' => implode('+', array_keys($storedBy)),
            'date' => $dates !== [] ? max($dates) : gmdate('Y-m-d'),
            'stored' => count($result['rates']),
            'missing' => $result['missing'],
            'errors' => $result['errors'],
        ];
    }

    /** @deprecated kept for callers written before the provider registry; use refresh() */
    public function refreshFromNbu(): array
    {
        return $this->refresh();
    }

    /** @return array<string,array<string,mixed>> provider status by code: last attempt/success/error, for every registered source */
    public function providerStatus(): array
    {
        $known = $this->status?->all() ?? [];
        $out = [];
        foreach ($this->sources() as $code => $_) {
            $out[$code] = $known[$code] ?? ['provider' => $code, 'last_attempt_at' => null, 'last_success_at' => null, 'last_rate_date' => null, 'last_error' => null, 'pairs_stored' => 0, 'consecutive_failures' => 0];
        }

        return $out;
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
