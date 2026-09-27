<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Application;

use InvalidArgumentException;

final class CanonicalMerchantProductBuilder
{
    /** @param array<string, mixed> $product @return array<string, mixed> */
    public function build(array $product): array
    {
        foreach (['id', 'name', 'url', 'price', 'currency', 'availability'] as $required) {
            if (!isset($product[$required]) || $product[$required] === '') {
                throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.1a87cfe536e6'), $required));
            }
        }

        $merchantPrice = (string) ($product['merchant_price'] ?? $product['gross_price'] ?? $product['price']);

        return [
            'offer_id' => (string) $product['id'],
            'content_language' => (string) ($product['content_language'] ?? 'en'),
            'feed_label' => (string) ($product['feed_label'] ?? 'default'),
            'title' => (string) $product['name'],
            'description' => (string) ($product['description'] ?? ''),
            'link' => (string) $product['url'],
            'image_links' => $this->imageLinks($product),
            'availability' => (string) $product['availability'],
            'condition' => (string) ($product['condition'] ?? 'new'),
            'price' => ['value' => $merchantPrice, 'currency' => strtoupper((string) $product['currency'])],
            'brand' => $product['brand'] ?? null,
            'gtins' => isset($product['gtin']) ? [(string) $product['gtin']] : [],
            'mpn' => $product['mpn'] ?? null,
            'item_group_id' => $product['item_group_id'] ?? null,
            'google_product_category' => $product['google_product_category'] ?? null,
            'product_types' => array_values((array) ($product['product_types'] ?? [])),
            'shipping_label' => $product['shipping_label'] ?? null,
            'custom_labels' => array_values((array) ($product['custom_labels'] ?? [])),
            'conversational' => [
                'question_and_answer' => array_values((array) ($product['questions'] ?? [])),
                'document_link' => array_values((array) ($product['documents'] ?? [])),
                'related_product' => array_values((array) ($product['related'] ?? [])),
                'variant_option' => array_values((array) ($product['variant_options'] ?? [])),
            ],
        ];
    }

    /** @param array<string, mixed> $product @return list<string> */
    private function imageLinks(array $product): array
    {
        $links = [];
        foreach ((array) ($product['images'] ?? $product['image'] ?? []) as $image) {
            if (is_string($image) && $image !== '') {
                $links[] = $image;
            } elseif (is_array($image) && !empty($image['url'])) {
                $links[] = (string) $image['url'];
            }
        }

        return array_values(array_unique($links));
    }
}
