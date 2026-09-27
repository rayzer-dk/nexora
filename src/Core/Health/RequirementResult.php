<?php

declare(strict_types=1);
namespace Commerce\Core\Health;
final readonly class RequirementResult
{
    public function __construct(
        public string $code,
        public string $label,
        public bool $passed,
        public RequirementLevel $level,
        public string $current,
        public string $required,
        public ?string $action = null,
    ) {}
}
