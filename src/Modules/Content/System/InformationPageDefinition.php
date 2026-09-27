<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\System;

final readonly class InformationPageDefinition
{
    /**
     * @param list<string> $requiredStoreFields
     * @param list<string> $sections
     */
    public function __construct(
        public string $key,
        public string $routeKey,
        public string $title,
        public string $footerGroup,
        public bool $indexable,
        public array $requiredStoreFields,
        public array $sections,
    ) {
    }
}
