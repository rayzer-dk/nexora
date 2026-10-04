<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use DomainException;

/**
 * Language packs edited by the store owner: a JSON file per language and scope in var/translations/<locale>/
 * (storefront.json for the shop, admin.json for the back office).
 * var/ is never touched by updates, JSON has no quoting traps (an apostrophe is just a character), and a broken
 * pack is rejected on upload instead of taking the site down. Missing texts fall back to English, then Ukrainian.
 */
final class LanguagePackService
{
    public const SCOPES = ['storefront', 'admin'];
    private const MAX_BYTES = 4_194_304;
    private const MAX_TEXT = 4000;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function isValidLocale(string $locale): bool
    {
        return preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) === 1;
    }

    /**
     * Texts to translate: the current text of the language, or the Ukrainian text where it is not translated yet.
     *
     * @return array<string,string>
     */
    public function export(string $locale, string $scope = 'storefront'): array
    {
        $this->assertLocale($locale);
        $base = $this->base($scope);
        $loader = new TranslationCatalogLoader($this->projectDir);
        $current = $locale === 'uk-UA' ? $base : $loader->load($locale);
        // What is not translated yet is offered in English (the usual source language for translators), or Ukrainian where there is no English text.
        $english = $locale === 'uk-UA' ? [] : $loader->load('en-US');
        $texts = [];
        foreach ($base as $key => $text) {
            $texts[$key] = $current[$key] ?? $english[$key] ?? $text;
        }
        ksort($texts, SORT_STRING);

        return $texts;
    }

    /**
     * @return array{saved:int,translated:int,rejected:array<string,string>} rejected: key => reason
     */
    public function import(string $locale, string $json, string $scope = 'storefront'): array
    {
        $this->assertLocale($locale);
        if ($locale === 'uk-UA') {
            throw new DomainException(CanonicalUiText::get('admin.langpack.error_base'));
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new DomainException(CanonicalUiText::get('admin.langpack.error_size'));
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DomainException(CanonicalUiText::get('admin.langpack.error_json') . ' ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new DomainException(CanonicalUiText::get('admin.langpack.error_shape'));
        }
        $base = $this->base($scope);
        $pack = [];
        $rejected = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (!isset($base[$key])) {
                $rejected[$key] = 'unknown_key';
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > self::MAX_TEXT) {
                $rejected[$key] = 'invalid_text';
                continue;
            }
            $value = str_replace("\0", '', $value);
            if ($this->placeholders($value) !== $this->placeholders($base[$key])) {
                $rejected[$key] = 'placeholders';
                continue;
            }
            $pack[$key] = $value;
        }
        if ($pack === []) {
            throw new DomainException(CanonicalUiText::get('admin.langpack.error_empty'));
        }
        $this->write($locale, $pack, $scope);
        $translated = 0;
        foreach ($pack as $key => $value) {
            if ($value !== $base[$key]) {
                ++$translated;
            }
        }

        return ['saved' => count($pack), 'translated' => $translated, 'rejected' => $rejected];
    }

    public function exists(string $locale, string $scope = 'storefront'): bool
    {
        return $this->isValidLocale($locale) && in_array($scope, self::SCOPES, true) && is_file($this->path($locale, $scope));
    }

    /** Percentage of the Ukrainian texts of a scope that have a translation in this language (a pack line equal to the Ukrainian text is not a translation). */
    public function coverage(string $locale, string $scope = 'storefront'): int
    {
        $base = $this->base($scope);
        if ($base === [] || !$this->isValidLocale($locale)) {
            return 0;
        }
        if ($locale === 'uk-UA') {
            return 100;
        }
        $loader = new TranslationCatalogLoader($this->projectDir);
        $current = $loader->load($locale);
        $english = $locale === 'en-US' ? [] : $loader->load('en-US');
        $done = 0;
        foreach ($base as $key => $text) {
            // A line equal to the Ukrainian or the English text (an untouched export) is not a translation.
            if (isset($current[$key]) && $current[$key] !== $text && $current[$key] !== ($english[$key] ?? null)) {
                ++$done;
            }
        }

        return (int) floor($done * 100 / count($base));
    }

    /** @return array<string,string> */
    private function base(string $scope): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new DomainException(CanonicalUiText::get('common.error.operation_failed'));
        }
        $file = $this->projectDir . '/resources/translations/uk-UA/' . $scope . '.php';
        $data = is_file($file) ? require $file : [];

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/%[a-z_]+%/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @param array<string,string> $pack */
    private function write(string $locale, array $pack, string $scope): void
    {
        $dir = dirname($this->path($locale, $scope));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DomainException(CanonicalUiText::get('common.error.operation_failed'));
        }
        ksort($pack, SORT_STRING);
        $tmp = $dir . '/.' . $scope . '-' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($tmp, json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
        if ($written === false || !@rename($tmp, $this->path($locale, $scope))) {
            @unlink($tmp);
            throw new DomainException(CanonicalUiText::get('common.error.operation_failed'));
        }
    }

    private function path(string $locale, string $scope): string
    {
        return $this->projectDir . '/var/translations/' . $locale . '/' . $scope . '.json';
    }

    private function assertLocale(string $locale): void
    {
        if (!$this->isValidLocale($locale)) {
            throw new DomainException(CanonicalUiText::get('admin.localization.locale.invalid_code'));
        }
    }
}
