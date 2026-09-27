<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Contract;

interface TextGenerationProviderInterface
{
    public function code(): string;
    public function enabled(): bool;
    public function generate(string $prompt, ?string $systemInstruction = null): string;
}
