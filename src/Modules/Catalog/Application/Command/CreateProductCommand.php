<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application\Command;

use InvalidArgumentException;

final readonly class CreateProductCommand
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $name,
        public string $sku,
        public int $priceMinor,
        public string $currency,
        public string $stockQuantity = '0.000000',
        public string $unitCode = 'item',
        public string $productType = 'physical',
        public array $categoryIds = [],
        public ?string $manualSlug = null,
        public ?string $shortDescription = null,
        public ?string $description = null,
        public ?string $gtin = null,
        public ?string $mpn = null,
        public ?int $brandId = null,
        public string $purchaseMode = 'auto',
        public ?string $purchaseButtonLabel = null,
        public ?string $purchaseEtaText = null,
    ) {
        if ($storeId < 1 || $marketId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6b437cb6452a'));
        }
        if (trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 255) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e9ba6c2f7f76'));
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,189}$/', trim($sku))) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f3ae32d7403c'));
        }
        if ($priceMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d2edea432a02'));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.85c07bbd91bc'));
        }
        if (!in_array($productType, ['physical', 'digital'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b1826f856ab6'));
        }
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $stockQuantity)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.acff444c39fc'));
        }
        if ($brandId !== null && $brandId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c3816e991ff7'));
        }
        if (!in_array($this->purchaseMode, ['auto','in_stock','backorder','preorder','coming_soon','sold_out','notify','price_request'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9947720aec49'));
        }
        if ($this->purchaseButtonLabel !== null && mb_strlen(trim($this->purchaseButtonLabel), 'UTF-8') > 120) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.59470c51af6b'));
        }
        if ($this->purchaseEtaText !== null && mb_strlen(trim($this->purchaseEtaText), 'UTF-8') > 190) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.35123cde9a91'));
        }
        foreach ($categoryIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9c2d71db5c68'));
            }
        }
    }
}
