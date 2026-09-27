<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Domain;

final readonly class ProductCatalogFilter
{
    public const SORT_NEWEST = 'newest';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_NAME_ASC = 'name_asc';
    public const SORT_NAME_DESC = 'name_desc';

    /** @var list<string> */
    public const SORTS = [
        self::SORT_NEWEST,
        self::SORT_PRICE_ASC,
        self::SORT_PRICE_DESC,
        self::SORT_NAME_ASC,
        self::SORT_NAME_DESC,
    ];

    public function __construct(
        public string $search = '',
        public ?int $brandId = null,
        public bool $inStockOnly = false,
        public ?int $minPriceMinor = null,
        public ?int $maxPriceMinor = null,
        public string $sort = self::SORT_NEWEST,
        /** @var array<string,list<string>> */
        public array $attributeFilters = [],
    ) {
        if ($brandId !== null && $brandId < 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c26eae3e1518'));
        }
        if ($minPriceMinor !== null && $minPriceMinor < 0) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b261af7a89bf'));
        }
        if ($maxPriceMinor !== null && $maxPriceMinor < 0) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8294b9aaebc0'));
        }
        if ($minPriceMinor !== null && $maxPriceMinor !== null && $minPriceMinor > $maxPriceMinor) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6c572f75ac9b'));
        }
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a12d5ce0ec17'));
        }
        if (count($attributeFilters) > 12) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4b59e1aade5d'));
        }
        foreach ($attributeFilters as $code => $values) {
            if (!is_string($code) || preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $code) !== 1 || !is_array($values) || count($values) > 12) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6c717c26153d'));
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 96) {
                    throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.31d7b22be9a6'));
                }
            }
        }
    }

    public function isFiltered(): bool
    {
        return $this->search !== ''
            || $this->brandId !== null
            || $this->inStockOnly
            || $this->minPriceMinor !== null
            || $this->maxPriceMinor !== null
            || $this->sort !== self::SORT_NEWEST
            || $this->attributeFilters !== [];
    }
    public function isFilteredExceptSearch(): bool
    {
        return $this->brandId !== null || $this->inStockOnly || $this->minPriceMinor !== null || $this->maxPriceMinor !== null || $this->attributeFilters !== [] || $this->sort !== self::SORT_NEWEST;
    }

}
