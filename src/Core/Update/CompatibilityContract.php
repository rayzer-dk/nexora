<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use InvalidArgumentException;

final readonly class CompatibilityContract
{
    public function __construct(
        public string $coreApi,
        public string $minimumPhp,
        public ?string $maximumCoreApi = null,
    ) {
        if (trim($this->coreApi) === '' || trim($this->minimumPhp) === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.654d430cac08'));
        }
    }

    public function supportsRuntime(string $phpVersion): bool
    {
        return version_compare($phpVersion, $this->minimumPhp, '>=');
    }
}
