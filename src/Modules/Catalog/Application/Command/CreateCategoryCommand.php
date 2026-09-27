<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application\Command;

use InvalidArgumentException;

final readonly class CreateCategoryCommand
{
    public function __construct(
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $name,
        public ?int $parentId = null,
        public ?string $manualSlug = null,
        public int $sortOrder = 0,
    ) {
        if ($storeId < 1 || $marketId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.160d92178a56'));
        }
        if (trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 255) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a912a23c31de'));
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0a738d3469af'));
        }
    }
}
