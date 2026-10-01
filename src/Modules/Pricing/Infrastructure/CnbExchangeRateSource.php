<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Czech National Bank daily fixing (free, no key, plain text, about 30 currencies).
 * Returns koruna per one unit of each currency (the bank quotes some per 100 units; that is normalised); CZK itself is 1.
 */
final readonly class CnbExchangeRateSource implements ReferenceRateSourceInterface
{
    public const CODE = 'cnb';
    private const URL = 'https://www.cnb.cz/en/financial-markets/foreign-exchange-market/central-bank-exchange-rate-fixing/central-bank-exchange-rate-fixing/daily.txt';
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(private HttpClientInterface $http)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function table(): ReferenceRateTable
    {
        $body = $this->http->request('GET', self::URL, ['timeout' => 10.0, 'max_duration' => 20.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'text/plain']])->getContent();

        return self::parse($body);
    }

    public static function parse(string $text): ReferenceRateTable
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $date = '';
        $first = (string) ($lines[0] ?? '');
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\s+(\d{4})/', $first, $m) === 1 && isset(self::MONTHS[strtolower($m[2])])) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], self::MONTHS[strtolower($m[2])], (int) $m[1]);
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $first, $m) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        $rates = ['CZK' => 1.0];
        foreach (array_slice($lines, 2) as $line) {
            $cells = explode('|', $line);
            if (count($cells) < 5) {
                continue;
            }
            $amount = (float) str_replace(',', '.', trim($cells[2]));
            $code = strtoupper(trim($cells[3]));
            $rate = (float) str_replace(',', '.', trim($cells[4]));
            if ($amount > 0 && $rate > 0 && preg_match('/^[A-Z]{3}$/', $code) === 1) {
                $rates[$code] = $rate / $amount;
            }
        }
        if (count($rates) < 5) {
            throw new \UnexpectedValueException('cnb_rates_empty');
        }

        return new ReferenceRateTable(self::CODE, 'CZK', $date !== '' ? $date : gmdate('Y-m-d'), $rates);
    }
}
