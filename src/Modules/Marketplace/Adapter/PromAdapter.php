<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketplace\Adapter;

use Commerce\Modules\Integration\Application\IntegrationSecretStore;
use Commerce\Modules\Marketplace\Contract\MarketplaceAdapterInterface;
use Commerce\Modules\Marketplace\Domain\ExternalOrder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prom.ua public API (my.prom.ua/api/v1, Bearer token). Written from the public documentation and covered by tests against a stand-in;
 * it has not been run against a live Prom shop. The product id on Prom is the offer id of the shop's Prom feed (the variant UUID).
 */
final class PromAdapter implements MarketplaceAdapterInterface
{
    private const STATUS = ['confirmed' => 'received', 'shipped' => 'received', 'delivered' => 'delivered', 'canceled' => 'canceled'];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function code(): string
    {
        return 'prom';
    }

    public function label(): string
    {
        return 'Prom.ua';
    }

    public function isReady(): bool
    {
        return IntegrationSecretStore::effective('PROM_ENABLED') === '1' && IntegrationSecretStore::effective('PROM_API_TOKEN') !== '';
    }

    public function newOrders(string $sinceIso): array
    {
        $data = $this->call('GET', '/orders/list', ['query' => ['limit' => 100, 'date_from' => $sinceIso]]);
        $orders = [];
        foreach ((array) ($data['orders'] ?? []) as $o) {
            if (!is_array($o) || !isset($o['id']) || !in_array((string) ($o['status'] ?? 'pending'), ['pending', 'received', 'paid'], true)) {
                continue;
            }
            $lines = [];
            foreach ((array) ($o['products'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $lines[] = [
                    'sku' => trim((string) ($p['sku'] ?? '')),
                    'external_id' => trim((string) ($p['external_id'] ?? '')),
                    'quantity' => (string) max(1, (int) ($p['quantity'] ?? 1)),
                    'price' => preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string) ($p['price'] ?? ''))) ?? '',
                ];
            }
            $name = trim(implode(' ', array_filter([(string) ($o['client_first_name'] ?? ''), (string) ($o['client_last_name'] ?? '')])));
            $delivery = trim(implode(', ', array_filter([(string) ($o['delivery_option']['name'] ?? ''), (string) ($o['delivery_address'] ?? '')])));
            $orders[] = new ExternalOrder(
                'prom', (string) $o['id'], $name !== '' ? $name : 'Prom.ua', (string) ($o['phone'] ?? ''), (string) ($o['email'] ?? ''),
                mb_substr((string) ($o['client_notes'] ?? ''), 0, 1500), $delivery, $lines,
            );
        }

        return $orders;
    }

    public function pushStock(array $items): void
    {
        foreach (array_chunk($items, 100) as $chunk) {
            $this->call('POST', '/products/edit_by_external_id', ['json' => array_map(static function (array $i): array {
                $row = ['id' => $i['external_id'], 'presence' => $i['quantity'] > 0 ? 'available' : 'not_available', 'quantity_in_stock' => $i['quantity']];
                if ($i['price'] !== null) {
                    $row['price'] = (float) $i['price'];
                }

                return $row;
            }, $chunk)]);
        }
    }

    public function pushStatus(string $externalId, string $status): void
    {
        $body = ['status' => self::STATUS[$status] ?? 'received', 'ids' => [(int) $externalId]];
        if ($status === 'canceled') {
            $body['cancellation_reason'] = 'another';
        }
        $this->call('POST', '/orders/set_status', ['json' => $body]);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function call(string $method, string $path, array $options): array
    {
        $base = rtrim(IntegrationSecretStore::effective('PROM_API_BASE') ?: 'https://my.prom.ua/api/v1', '/');
        $options['headers'] = ['Authorization' => 'Bearer ' . IntegrationSecretStore::effective('PROM_API_TOKEN'), 'Accept' => 'application/json'];
        $options['timeout'] = 15.0;
        $response = $this->http->request($method, $base . $path, $options);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300 || (is_array($data) && !empty($data['error']))) {
            throw new \RuntimeException('Prom.ua answered ' . $status . ': ' . mb_substr(is_array($data) ? (string) ($data['error'] ?? $data['message'] ?? '') : '', 0, 200));
        }

        return is_array($data) ? $data : [];
    }
}
