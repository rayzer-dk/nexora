<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * National Bank of Poland, table A of average rates (free, no key, about 30 currencies).
 * Returns zloty per one unit of each currency; PLN itself is 1.
 */
final readonly class NbpExchangeRateSource implements ReferenceRateSourceInterface
{
    public const CODE = 'nbp';
    private const URL = 'https://api.nbp.pl/api/exchangerates/tables/A?format=json';

    public function __construct(private HttpClientInterface $http)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function table(): ReferenceRateTable
    {
        $body = $this->http->request('GET', self::URL, ['timeout' => 10.0, 'max_duration' => 20.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'application/json']])->getContent();

        return self::parse($body);
    }

    public static function parse(string $json): ReferenceRateTable
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('nbp_rates_invalid_json');
        }
        $table = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : (is_array($data) ? $data : []);
        $rates = ['PLN' => 1.0];
        foreach ((array) ($table['rates'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $mid = (float) ($row['mid'] ?? 0);
            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && $mid > 0) {
                $rates[$code] = $mid;
            }
        }
        if (count($rates) < 5) {
            throw new \UnexpectedValueException('nbp_rates_empty');
        }
        $date = (string) ($table['effectiveDate'] ?? '');

        return new ReferenceRateTable(self::CODE, 'PLN', preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : gmdate('Y-m-d'), $rates);
    }
}
