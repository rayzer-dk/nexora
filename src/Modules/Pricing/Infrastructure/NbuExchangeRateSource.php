<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Official daily rates of the National Bank of Ukraine (free, no key).
 * Returns hryvnias per one unit of each currency; UAH itself is 1.
 */
final readonly class NbuExchangeRateSource implements ReferenceRateSourceInterface
{
    public const CODE = 'nbu';
    private const URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';

    public function __construct(private HttpClientInterface $http)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function table(): ReferenceRateTable
    {
        $raw = $this->fetch();

        return new ReferenceRateTable(self::CODE, 'UAH', $raw['date'], $raw['uah_per_unit']);
    }

    /** @return array{date:string,uah_per_unit:array<string,float>} */
    public function fetch(): array
    {
        $response = $this->http->request('GET', self::URL, ['timeout' => 10.0, 'max_duration' => 20.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'application/json']]);

        return self::parse($response->toArray());
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{date:string,uah_per_unit:array<string,float>}
     */
    public static function parse(array $rows): array
    {
        $rates = ['UAH' => 1.0];
        $date = '';
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['cc'] ?? '')));
            $rate = (float) ($row['rate'] ?? 0);
            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && $rate > 0) {
                $rates[$code] = $rate;
            }
            if ($date === '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', (string) ($row['exchangedate'] ?? ''), $m) === 1) {
                $date = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
        }

        if (count($rates) < 5) {
            throw new \UnexpectedValueException('nbu_rates_empty');
        }

        return ['date' => $date !== '' ? $date : gmdate('Y-m-d'), 'uah_per_unit' => $rates];
    }
}
