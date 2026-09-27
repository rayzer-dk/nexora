<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Product;

final class ProductAiContextBuilder
{
    /** @param array<string, mixed> $product @return array<string, mixed> */
    public function build(array $product): array
    {
        return [
            'schema_version' => 1,
            'entity_type' => 'product',
            'id' => (string) ($product['id'] ?? ''),
            'locale' => (string) ($product['locale'] ?? 'en'),
            'identity' => [
                'name' => (string) ($product['name'] ?? ''),
                'brand' => $product['brand'] ?? null,
                'sku' => $product['sku'] ?? null,
                'gtin' => $product['gtin'] ?? null,
                'mpn' => $product['mpn'] ?? null,
            ],
            'offer' => [
                'price' => $product['price'] ?? null,
                'currency' => $product['currency'] ?? null,
                'availability' => $product['availability'] ?? null,
            ],
            'content' => [
                'description' => $product['description'] ?? null,
                'features' => array_values((array) ($product['features'] ?? [])),
                'attributes' => array_values((array) ($product['attributes'] ?? [])),
                'questions' => array_values((array) ($product['questions'] ?? [])),
                'documents' => array_values((array) ($product['documents'] ?? [])),
            ],
            'relationships' => [
                'related' => array_values((array) ($product['related'] ?? [])),
            ],
            'links' => [
                'canonical' => $product['url'] ?? null,
            ],
            'updated_at' => $product['updated_at'] ?? null,
        ];
    }
}
