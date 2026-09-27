#!/usr/bin/env php
<?php

declare(strict_types=1);

use Commerce\Modules\GoogleCommerce\Infrastructure\GoogleMerchantApiClient;
use Commerce\Modules\GoogleCommerce\Infrastructure\GoogleMerchantTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = [];
$requests = [];
$responses = [
    new MockResponse(json_encode(['name' => 'accounts/123/productInputs/uk~UA~SKU-1', 'product' => 'uk~UA~SKU-1'], JSON_THROW_ON_ERROR)),
    new MockResponse('', ['http_code' => 204]),
    new MockResponse(json_encode(['name' => 'accounts/123/products/uk~UA~SKU-1', 'productStatus' => ['itemLevelIssues' => []]], JSON_THROW_ON_ERROR)),
];

$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
    $requests[] = [$method, $url, $options];
    if ($responses === []) {
        throw new RuntimeException('Unexpected Merchant API request.');
    }
    return array_shift($responses);
});
$tokens = new GoogleMerchantTokenProvider('', 'integration-test-token');
$client = new GoogleMerchantApiClient($http, $tokens, 'https://merchantapi.googleapis.com', '123', 'accounts/123/dataSources/456');

$insert = $client->upsertProduct([
    'offerId' => 'SKU-1',
    'contentLanguage' => 'uk',
    'feedLabel' => 'UA',
    'attributes' => ['title' => 'Test product'],
]);
if (($insert['product'] ?? null) !== 'uk~UA~SKU-1') $failures[] = 'Product insert response is not propagated.';
$client->deleteProduct('uk', 'UA', 'SKU-1');
$processed = $client->getProcessedProduct('uk~UA~SKU-1');
if (($processed['productStatus']['itemLevelIssues'] ?? null) !== []) $failures[] = 'Processed-product diagnostics are not readable.';

if (count($requests) !== 3) {
    $failures[] = 'Expected exactly three Merchant API calls.';
} else {
    [$method1, $url1, $options1] = $requests[0];
    if ($method1 !== 'POST' || !str_contains($url1, '/products/v1/accounts/123/productInputs:insert')) $failures[] = 'Insert endpoint contract mismatch.';
    if (!in_array('Authorization: Bearer integration-test-token', (array)($options1['headers'] ?? []), true)) $failures[] = 'Authorization bearer token is missing.';
    if (($options1['query']['dataSource'] ?? '') !== 'accounts/123/dataSources/456') $failures[] = 'Merchant dataSource query is missing.';
    [$method2, $url2] = $requests[1];
    if ($method2 !== 'DELETE' || !str_contains($url2, '/productInputs/uk~UA~SKU-1')) $failures[] = 'Delete endpoint/resource encoding contract mismatch.';
    [$method3, $url3] = $requests[2];
    if ($method3 !== 'GET' || !str_contains($url3, '/products/v1/accounts/123/products/uk~UA~SKU-1')) $failures[] = 'Processed-product diagnostics endpoint mismatch.';
}

$errorHttp = new MockHttpClient(new MockResponse('{"error":{"message":"invalid product"}}', ['http_code' => 400]));
$errorClient = new GoogleMerchantApiClient($errorHttp, $tokens, 'https://merchantapi.googleapis.com', '123', 'accounts/123/dataSources/456');
try {
    $errorClient->upsertProduct(['offerId' => 'bad']);
    $failures[] = 'Non-2xx Merchant API response did not fail.';
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), '400')) $failures[] = 'Merchant API error does not preserve HTTP status for diagnostics/retry classification.';
}

$unconfigured = new GoogleMerchantApiClient(new MockHttpClient(), $tokens, 'https://merchantapi.googleapis.com', '', '');
try {
    $unconfigured->upsertProduct(['offerId' => 'bad']);
    $failures[] = 'Unconfigured Merchant client did not fail closed.';
} catch (RuntimeException) {
}

$retry = new Commerce\Modules\Integration\Application\IntegrationRetryPolicy(maxAttempts: 4, baseDelaySeconds: 30, maxDelaySeconds: 300);
foreach ([1 => 30, 2 => 60, 3 => 120] as $attempt => $delay) {
    $state = $retry->afterFailure($attempt);
    if ($state['dead'] || $state['delay_seconds'] !== $delay) $failures[] = 'Retry backoff mismatch at attempt ' . $attempt . '.';
}
$terminal = $retry->afterFailure(4);
if (!$terminal['dead'] || $terminal['delay_seconds'] !== 0) $failures[] = 'Retry policy does not enter dead-letter state at max attempts.';

if ($failures !== []) {
    fwrite(STDERR, "Google Commerce integration contract FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Google Commerce integration contract: PASSED\n";
echo "insert=yes delete=yes diagnostics=yes auth=yes datasource=yes error_propagation=yes retry_backoff=yes dead_letter=yes\n";
