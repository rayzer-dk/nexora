<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Contract;

/** A text model that can also look at one picture (a product photo). */
interface ImageTextGenerationProviderInterface extends TextGenerationProviderInterface
{
    public function generateFromImage(string $prompt, string $mimeType, string $base64, ?string $systemInstruction = null): string;
}
