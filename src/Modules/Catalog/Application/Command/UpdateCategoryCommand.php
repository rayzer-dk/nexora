<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application\Command;

use InvalidArgumentException;

final readonly class UpdateCategoryCommand
{
    public function __construct(
        public int $categoryId,
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $name,
        public ?int $parentId,
        public ?string $manualSlug,
        public int $sortOrder,
        public string $status,
    ) {
        if ($categoryId < 1 || $storeId < 1 || $marketId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ed12dc4a3443'));
        }
        if (trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 255) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7ad97cf9fcc8'));
        }
        if ($parentId === $categoryId) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.01c39793109f'));
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d779d2107fdc'));
        }
    }
}
