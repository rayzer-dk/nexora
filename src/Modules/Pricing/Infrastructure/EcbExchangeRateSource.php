<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Daily reference rates of the European Central Bank (free, no key, about 30 currencies).
 * Returns units of each currency per one euro; EUR itself is 1.
 */
final readonly class EcbExchangeRateSource implements ReferenceRateSourceInterface
{
    public const CODE = 'ecb';
    private const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

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
        $pivotPerUnit = [];
        foreach ($raw['per_eur'] as $currency => $perEur) {
            if ($perEur > 0) {
                $pivotPerUnit[$currency] = 1 / $perEur;
            }
        }

        return new ReferenceRateTable(self::CODE, 'EUR', $raw['date'], $pivotPerUnit);
    }

    /** @return array{date:string,per_eur:array<string,float>} */
    public function fetch(): array
    {
        $xml = $this->http->request('GET', self::URL, ['timeout' => 10.0, 'max_duration' => 20.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'application/xml']])->getContent();

        return self::parse($xml);
    }

    /** @return array{date:string,per_eur:array<string,float>} */
    public static function parse(string $xml): array
    {
        $rates = ['EUR' => 1.0];
        $date = '';
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \UnexpectedValueException('ecb_rates_invalid_xml');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($document->getElementsByTagName('Cube') as $node) {
            if ($node->hasAttribute('time') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $node->getAttribute('time')) === 1) {
                $date = $node->getAttribute('time');
            }
            $code = strtoupper($node->getAttribute('currency'));
            $rate = (float) $node->getAttribute('rate');
            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && $rate > 0) {
                $rates[$code] = $rate;
            }
        }
        if (count($rates) < 5) {
            throw new \UnexpectedValueException('ecb_rates_empty');
        }

        return ['date' => $date !== '' ? $date : gmdate('Y-m-d'), 'per_eur' => $rates];
    }
}
