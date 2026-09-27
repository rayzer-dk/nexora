<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\System;

final readonly class SystemPageDefinition
{
    public function __construct(
        public string $key,
        public string $title,
        public string $path,
        public bool $indexable,
    ) {
    }
}
