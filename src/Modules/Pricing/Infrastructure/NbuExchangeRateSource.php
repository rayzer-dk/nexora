<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Official daily rates of the National Bank of Ukraine (free, no key).
 * Returns hryvnias per one unit of each currency; UAH itself is 1.
 */
final readonly class NbuExchangeRateSource
{
    public const CODE = 'nbu';
    private const URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';

    public function __construct(private HttpClientInterface $http)
    {
    }

    /** @return array{date:string,uah_per_unit:array<string,float>} */
    public function fetch(): array
    {
        $response = $this->http->request('GET', self::URL, ['timeout' => 15.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'application/json']]);
        $rows = $response->toArray();
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

        return ['date' => $date !== '' ? $date : gmdate('Y-m-d'), 'uah_per_unit' => $rates];
    }
}
