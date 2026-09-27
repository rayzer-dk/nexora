<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

final readonly class ExtensionPackageInspection
{
    /** @param array<string,mixed> $manifest @param list<string> $files @param list<string> $warnings */
    public function __construct(
        public array $manifest,
        public array $files,
        public array $warnings,
        public bool $quarantined,
        public string $quarantineReason = '',
    ) {
    }
}
