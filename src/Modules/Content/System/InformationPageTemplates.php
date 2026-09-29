<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\System;

/**
 * Ready-made bodies for the system information pages (privacy, cookies, terms, delivery, ...).
 *
 * Templates live in resources/content/information/{locale}/{key}.html and use {placeholders} that are
 * filled from the store profile. A placeholder without a value is rendered as a visible <mark> so the
 * merchant sees exactly which detail is missing before publishing.
 */
final class InformationPageTemplates
{
    private const FIELDS = [
        'store_name', 'legal_name', 'registration_number', 'address', 'email', 'phone',
        'privacy_contact', 'return_contact', 'warranty_contact',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @param array<string,mixed> $profile keys: store_name, legal_name, registration_number, registration_address, email, phone, privacy_contact, return_contact, warranty_contact */
    public function body(string $key, string $locale, array $profile): ?string
    {
        $file = $this->locateFile($key, $locale);
        if ($file === null) {
            return null;
        }
        $template = (string) file_get_contents($file);
        $values = $profile;
        $values['address'] = $profile['address'] ?? $profile['registration_address'] ?? null;

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($values): string {
            if (!in_array($m[1], self::FIELDS, true)) {
                return $m[0];
            }
            $value = trim((string) ($values[$m[1]] ?? ''));
            return $value !== ''
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '<mark class="rich-placeholder">[' . str_replace('_', ' ', $m[1]) . ']</mark>';
        }, $template);
    }

    public function has(string $key, string $locale): bool
    {
        return $this->locateFile($key, $locale) !== null;
    }

    private function locateFile(string $key, string $locale): ?string
    {
        if (!preg_match('/^[a-z_]+$/', $key)) {
            return null;
        }
        $language = strtolower(substr($locale, 0, 2));
        // English has its own text; every other locale falls back to the canonical Ukrainian text.
        $directory = $language === 'en' ? 'en-US' : 'uk-UA';
        $file = $this->projectDir . '/resources/content/information/' . $directory . '/' . $key . '.html';
        return is_file($file) ? $file : null;
    }
}
