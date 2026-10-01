<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Infrastructure;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Commercial rate API the merchant subscribes to with his own key (the optional "API" source): covers currencies no central
 * bank publishes and gives several updates per day. Two popular services are supported; the key is stored in system settings.
 * Returns units of the pivot currency (USD) per one unit of each listed currency.
 */
final readonly class ApiKeyExchangeRateSource implements ReferenceRateSourceInterface
{
    public const CODE = 'api';
    public const SETTING_KEY = 'pricing.rate_api';
    public const SERVICES = ['exchangerate_host', 'exchangerate_api'];
    private const PIVOT = 'USD';

    public function __construct(private HttpClientInterface $http, private SystemSettingStore $settings)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    /** @return array{service:string,key:string} */
    public function config(): array
    {
        $saved = $this->settings->getArray(self::SETTING_KEY) ?? [];
        $service = (string) ($saved['service'] ?? '');

        return ['service' => in_array($service, self::SERVICES, true) ? $service : self::SERVICES[0], 'key' => trim((string) ($saved['key'] ?? ''))];
    }

    public function isConfigured(): bool
    {
        return $this->config()['key'] !== '';
    }

    public function table(): ReferenceRateTable
    {
        $config = $this->config();
        if ($config['key'] === '') {
            throw new \RuntimeException('rate_api_key_missing');
        }
        $url = $config['service'] === 'exchangerate_api'
            ? 'https://v6.exchangerate-api.com/v6/' . rawurlencode($config['key']) . '/latest/' . self::PIVOT
            : 'https://api.exchangerate.host/live?access_key=' . rawurlencode($config['key']) . '&source=' . self::PIVOT;
        $body = $this->http->request('GET', $url, ['timeout' => 10.0, 'max_duration' => 20.0, 'max_redirects' => 2, 'headers' => ['Accept' => 'application/json']])->getContent();

        return self::parse($body, $config['service']);
    }

    public static function parse(string $json, string $service): ReferenceRateTable
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('api_rates_invalid_json');
        }
        if (!is_array($data)) {
            throw new \UnexpectedValueException('api_rates_invalid_json');
        }
        $perPivot = [];
        $date = '';
        if ($service === 'exchangerate_api') {
            if (($data['result'] ?? '') !== 'success') {
                throw new \UnexpectedValueException('api_rates_' . preg_replace('/[^a-z0-9_-]/i', '', (string) ($data['error-type'] ?? 'rejected')));
            }
            foreach ((array) ($data['conversion_rates'] ?? []) as $code => $perUsd) {
                $perPivot[strtoupper((string) $code)] = (float) $perUsd;
            }
            $stamp = (int) ($data['time_last_update_unix'] ?? 0);
            $date = $stamp > 0 ? gmdate('Y-m-d', $stamp) : '';
        } else {
            if (($data['success'] ?? false) !== true) {
                $type = is_array($data['error'] ?? null) ? (string) ($data['error']['type'] ?? $data['error']['code'] ?? 'rejected') : 'rejected';
                throw new \UnexpectedValueException('api_rates_' . preg_replace('/[^a-z0-9_-]/i', '', $type));
            }
            $source = strtoupper((string) ($data['source'] ?? self::PIVOT));
            foreach ((array) ($data['quotes'] ?? []) as $pair => $perSource) {
                $pair = strtoupper((string) $pair);
                if (str_starts_with($pair, $source) && strlen($pair) === 6) {
                    $perPivot[substr($pair, 3)] = (float) $perSource;
                }
            }
            $stamp = (int) ($data['timestamp'] ?? 0);
            $date = $stamp > 0 ? gmdate('Y-m-d', $stamp) : '';
        }
        $perPivot[self::PIVOT] = 1.0;
        $pivotPerUnit = [];
        foreach ($perPivot as $code => $perUsd) {
            if (preg_match('/^[A-Z]{3}$/', (string) $code) === 1 && $perUsd > 0) {
                $pivotPerUnit[(string) $code] = $code === self::PIVOT ? 1.0 : 1 / $perUsd;
            }
        }
        if (count($pivotPerUnit) < 5) {
            throw new \UnexpectedValueException('api_rates_empty');
        }

        return new ReferenceRateTable(self::CODE, self::PIVOT, $date !== '' ? $date : gmdate('Y-m-d'), $pivotPerUnit);
    }
}
