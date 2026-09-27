<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

final readonly class MigrationImportPlan
{
    /** @param array<string,string> $localeMap */
    public function __construct(
        public int $storeId,
        public int $marketId,
        public string $primaryLocale,
        public string $currency,
        public string $sourceInstanceKey,
        public array $localeMap = [],
        public bool $publishProducts = false,
        public bool $publishCategories = true,
        public int $batchSize = 250,
        public ?string $sourceImageRoot = null,
    ) {
        if ($storeId < 1 || $marketId < 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.575bdb8f88b5'));
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $primaryLocale)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5be9e9350859'));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.84fdbdd6b7a0'));
        }
        if (trim($sourceInstanceKey) === '') {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.29e36816fe3f'));
        }
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.41a9b6382668'));
        }
    }

    public function mappedLocale(string $sourceLocale): ?string
    {
        if (isset($this->localeMap[$sourceLocale])) {
            return $this->localeMap[$sourceLocale];
        }
        return preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $sourceLocale) === 1 ? $sourceLocale : null;
    }
}
