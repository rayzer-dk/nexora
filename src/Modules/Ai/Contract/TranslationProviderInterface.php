<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Contract;

/**
 * A machine-translation service (Google Cloud Translation, DeepL, ...) contributed by a signed extension.
 * It appears next to the AI providers on every "Translate" button and works on the same texts.
 */
interface TranslationProviderInterface
{
    /** Stable code stored in the usage log, for example `google_translate`. Must not clash with a built-in AI provider. */
    public function code(): string;

    /** Name shown in the provider list. */
    public function label(): string;

    /** False while the provider is not configured (no key): it is then not offered. */
    public function enabled(): bool;

    /**
     * @param string $text HTML or plain text; HTML tags and attributes must come back unchanged
     * @param string $sourceLocale locale of the text (for example `uk-UA`), or '' to detect it
     * @param string $targetLocale locale to translate into (for example `en-US`)
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string;
}
