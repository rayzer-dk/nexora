<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks an EU VAT number in VIES (the European Commission service). Written from the public REST description and tested against a stand-in,
 * not against the live service. The address of the service can be changed with VIES_API_BASE.
 */
final readonly class ViesClient
{
    private const DEFAULT_BASE = 'https://ec.europa.eu/taxation_customs/vies/rest-api';

    /** Member states VIES answers for (Greece is EL, Northern Ireland XI). */
    public const COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    public function __construct(private HttpClientInterface $http)
    {
    }

    /** @return array{valid:bool,name:string,address:string,state:string} state: VALID, INVALID or the reason the service gave (for example MS_UNAVAILABLE) */
    public function check(string $country, string $number): array
    {
        $country = strtoupper(trim($country));
        $rawNumber = trim($number);
        $number = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($number)) ?? '';
        if (str_starts_with($number, $country)) {
            $number = substr($number, strlen($country));
        }
        if (preg_match('/^[A-Za-z0-9 .\-]{2,24}$/D', $rawNumber) !== 1 || !in_array($country, self::COUNTRIES, true) || preg_match('/^[A-Z0-9]{2,14}$/D', $number) !== 1) {
            return ['valid' => false, 'name' => '', 'address' => '', 'state' => 'INVALID_INPUT'];
        }
        $base = rtrim(trim((string) ($_ENV['VIES_API_BASE'] ?? $_SERVER['VIES_API_BASE'] ?? (getenv('VIES_API_BASE') ?: ''))) ?: self::DEFAULT_BASE, '/');
        $response = $this->http->request('GET', $base . '/ms/' . $country . '/vat/' . $number, ['timeout' => 12.0, 'headers' => ['Accept' => 'application/json']]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if (!is_array($data) || $status >= 500 || $status === 429) {
            return ['valid' => false, 'name' => '', 'address' => '', 'state' => 'SERVICE_UNAVAILABLE'];
        }
        $valid = ($data['isValid'] ?? $data['valid'] ?? false) === true;

        return [
            'valid' => $valid, 'name' => $this->clean((string) ($data['name'] ?? '')), 'address' => $this->clean((string) ($data['address'] ?? '')),
            'state' => $valid ? 'VALID' : (string) (($data['userError'] ?? '') !== '' && $data['userError'] !== 'VALID' ? $data['userError'] : 'INVALID'),
        ];
    }

    private function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '---' ? '' : mb_substr($value, 0, 300);
    }
}
