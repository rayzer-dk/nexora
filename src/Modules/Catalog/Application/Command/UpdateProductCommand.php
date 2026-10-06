<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application\Command;

use InvalidArgumentException;

final readonly class UpdateProductCommand
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public int $productId,
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $name,
        public string $sku,
        public int $priceMinor,
        public string $currency,
        public string $stockQuantity,
        public string $unitCode,
        public array $categoryIds,
        public ?string $manualSlug,
        public ?string $shortDescription,
        public ?string $description,
        public ?string $gtin,
        public ?string $mpn,
        public string $status,
        public ?int $brandId = null,
        public string $purchaseMode = 'auto',
        public ?string $purchaseButtonLabel = null,
        public ?string $purchaseEtaText = null,
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
        public bool $updateSeoMeta = false,
        public ?int $compareAtMinor = null,
        public bool $updateCompareAt = false,
        public ?string $h1 = null,
    ) {
        if ($metaTitle !== null && mb_strlen(trim($metaTitle), 'UTF-8') > 255) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.product.error.seo_title_long'));
        }
        if ($metaDescription !== null && mb_strlen(trim($metaDescription), 'UTF-8') > 500) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.product.error.seo_description_long'));
        }
        if ($compareAtMinor !== null && $compareAtMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.product.error.old_price_negative'));
        }
        if ($productId < 1 || $storeId < 1 || $marketId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.995588e393fa'));
        }
        if (trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 255) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5cc9a3422d2f'));
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,189}$/', trim($sku))) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.49e80697d4bc'));
        }
        if ($priceMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.761eaf929b25'));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.015154d784a5'));
        }
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $stockQuantity)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.453330818a6f'));
        }
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a4aaa4bec49'));
        }
        if ($brandId !== null && $brandId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c1a5b95a1482'));
        }
        if (!in_array($this->purchaseMode, ['auto','in_stock','backorder','preorder','coming_soon','sold_out','notify','price_request'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e3c504c74d7b'));
        }
        if ($this->purchaseButtonLabel !== null && mb_strlen(trim($this->purchaseButtonLabel), 'UTF-8') > 120) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0a0dae60d43e'));
        }
        if ($this->purchaseEtaText !== null && mb_strlen(trim($this->purchaseEtaText), 'UTF-8') > 190) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.45c7e510bd24'));
        }
        foreach ($categoryIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bef5a53b28da'));
            }
        }
    }
}
