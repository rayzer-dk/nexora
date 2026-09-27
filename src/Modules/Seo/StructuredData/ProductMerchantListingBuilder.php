<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\StructuredData;

use InvalidArgumentException;

final class ProductMerchantListingBuilder
{
    public function build(array $product): array
    {
        foreach (['name', 'url', 'price', 'currency', 'availability'] as $required) {
            if (!array_key_exists($required, $product) || $product[$required] === '' || $product[$required] === null) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f268402b6fa0') . $required);
            }
        }

        $images = $product['image'] ?? $product['images'] ?? [];
        $imageUrls = [];
        foreach ((array) $images as $image) {
            if (is_string($image) && $image !== '') {
                $imageUrls[] = $image;
            } elseif (is_array($image) && !empty($image['url'])) {
                $imageUrls[] = (string) $image['url'];
            }
        }

        if ($imageUrls === []) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.87edfb1059ef'));
        }

        $offerPrice = (string) ($product['merchant_price'] ?? $product['gross_price'] ?? $product['price']);

        $offer = [
            '@type' => 'Offer',
            'url' => $product['url'],
            'price' => $offerPrice,
            'priceCurrency' => strtoupper((string) $product['currency']),
            'availability' => $product['availability'],
            'itemCondition' => $product['condition'] ?? 'https://schema.org/NewCondition',
        ];

        if (!empty($product['price_valid_until'])) {
            $offer['priceValidUntil'] = $product['price_valid_until'];
        }
        if (!empty($product['shipping']['shippingDetails'])) {
            $offer['shippingDetails'] = $product['shipping']['shippingDetails'];
        }
        if (!empty($product['return_policy'])) {
            $offer['hasMerchantReturnPolicy'] = $product['return_policy'];
        }

        if (!empty($product['unit_pricing'])) {
            $unit = $product['unit_pricing'];
            if (!empty($unit['measure_value']) && !empty($unit['measure_code'])) {
                $offer['priceSpecification'] = [
                    '@type' => 'UnitPriceSpecification',
                    'price' => $offerPrice,
                    'priceCurrency' => strtoupper((string) $product['currency']),
                    'referenceQuantity' => [
                        '@type' => 'QuantitativeValue',
                        'value' => (float) ($unit['base_value'] ?? $unit['measure_value']),
                        'unitText' => (string) ($unit['base_code'] ?? $unit['measure_code']),
                    ],
                ];
            }
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'url' => $product['url'],
            'image' => $imageUrls,
            'offers' => $offer,
        ];

        foreach (['description', 'sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $optional) {
            if (!empty($product[$optional])) {
                $data[$optional] = $product[$optional];
            }
        }

        if (!empty($product['brand'])) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
        }

        if (!empty($product['rating']['value']) && !empty($product['rating']['count'])) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (float) $product['rating']['value'],
                'reviewCount' => (int) $product['rating']['count'],
            ];
        }

        if (!empty($product['reviews'])) {
            $reviews = [];
            foreach ((array) $product['reviews'] as $review) {
                if (empty($review['author']) || empty($review['rating']) || empty($review['text'])) {
                    continue;
                }
                $reviews[] = [
                    '@type' => 'Review',
                    'author' => ['@type' => 'Person', 'name' => (string) $review['author']],
                    'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (float) $review['rating'], 'bestRating' => 5],
                    'reviewBody' => (string) $review['text'],
                ];
            }
            if ($reviews !== []) {
                $data['review'] = $reviews;
            }
        }

        return $data;
    }
}
