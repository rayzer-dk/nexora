<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Http;

/** The OpenAPI paths of the write endpoints (merged into /api/v1/openapi.json). */
final class ApiWriteOpenApi
{
    /** @return array<string,mixed> */
    public static function paths(): array
    {
        $security = [['bearerAuth' => []]];
        $key = [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]];
        $body = static fn (array $props): array => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props]]]];
        $variant = [
            'price_minor' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Price in minor units (cents)'],
            'compare_at_minor' => ['type' => ['integer', 'null'], 'minimum' => 0, 'description' => 'Old price, null clears it'],
            'stock_quantity' => ['type' => ['number', 'string'], 'description' => 'New absolute stock'],
            'stock_delta' => ['type' => ['number', 'string'], 'description' => 'Added to the stock (negative subtracts)'],
        ];
        $ok = ['200' => ['description' => 'Applied'], '404' => ['description' => 'Variant not found'], '422' => ['description' => 'Validation failed']];

        return [
            '/api/v1/catalog/variants/{publicId}' => ['patch' => ['security' => $security, 'x-scope' => 'catalog:write (prices), inventory:write (stock)', 'summary' => 'Change price and/or stock of one variant', 'requestBody' => $body($variant), 'responses' => $ok]],
            '/api/v1/catalog/variants/by-sku/{sku}' => ['patch' => ['security' => $security, 'x-scope' => 'catalog:write (prices), inventory:write (stock)', 'summary' => 'The same, found by SKU', 'requestBody' => $body($variant), 'responses' => $ok]],
            '/api/v1/catalog/sync' => ['post' => ['security' => $security, 'x-scope' => 'catalog:write', 'summary' => 'Batch of up to 500 price/stock changes', 'parameters' => $key, 'requestBody' => $body(['items' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string'], 'variant_public_id' => ['type' => 'string']] + $variant]]]), 'responses' => ['200' => ['description' => 'Per-item results'], '422' => ['description' => 'Validation failed']]]],
            '/api/v1/orders/{publicId}' => ['patch' => ['security' => $security, 'x-scope' => 'orders:write', 'summary' => 'Move an order on: paid, shipped with tracking, completed, cancelled, internal note', 'requestBody' => $body([
                'mark_paid' => ['type' => 'boolean'], 'fulfillment_status' => ['type' => 'string', 'enum' => ['pending', 'preparing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled']],
                'tracking_number' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['completed', 'cancelled']], 'note' => ['type' => 'string'],
            ]), 'responses' => ['200' => ['description' => 'The order after the change'], '404' => ['description' => 'Order not found'], '422' => ['description' => 'Validation failed']]]],
        ];
    }
}