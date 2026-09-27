<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Ucp;

final class UcpProfileBuilder
{
    /** @return array<string, mixed> */
    public function build(string $publicBaseUrl, string $version): array
    {
        $base = rtrim($publicBaseUrl, '/');

        return [
            'ucp' => [
                'version' => $version,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version' => $version,
                        'spec' => 'https://ucp.dev/specification/overview',
                        'transport' => 'rest',
                        'endpoint' => $base . '/ucp/v1',
                        'schema' => sprintf('https://ucp.dev/%s/services/shopping/rest.openapi.json', $version),
                    ]],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [[
                        'version' => $version,
                        'spec' => sprintf('https://ucp.dev/%s/specification/checkout', $version),
                        'schema' => sprintf('https://ucp.dev/%s/schemas/shopping/checkout.json', $version),
                    ]],
                    'dev.ucp.shopping.fulfillment' => [[
                        'version' => $version,
                        'spec' => sprintf('https://ucp.dev/%s/specification/fulfillment', $version),
                    ]],
                ],
            ],
        ];
    }
}
