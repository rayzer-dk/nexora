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

    /** language => directory of the ready-made texts; any other language starts from the English drafts */
    private const DIRECTORIES = ['uk' => 'uk-UA', 'ru' => 'ru-RU', 'pl' => 'pl-PL', 'de' => 'de-DE', 'da' => 'da-DK', 'en' => 'en-US'];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @param array<string,mixed> $profile keys: store_name, legal_name, registration_number, registration_address, email, phone, privacy_contact, return_contact, warranty_contact */
    public function body(string $key, string $locale, array $profile, string $country = ''): ?string
    {
        $file = $this->locateFile($key, $locale);
        if ($file === null) {
            return null;
        }
        $template = (string) file_get_contents($file);
        $values = $profile;
        $values['address'] = $profile['address'] ?? $profile['registration_address'] ?? null;
        $country = $country !== '' ? $country : (string) ($profile['country_code'] ?? '');
        $frame = JurisdictionCatalog::values($country, $locale);

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($values, $frame): string {
            if (isset($frame[$m[1]])) {
                return htmlspecialchars($frame[$m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
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
        $file = $this->projectDir . '/resources/content/information/' . self::directoryFor($locale) . '/' . $key . '.html';
        return is_file($file) ? $file : null;
    }

    /** The locale the drafts for a store language are written in (uk, ru, pl, de, da, en; anything else gets English). */
    public static function directoryFor(string $locale): string
    {
        return self::DIRECTORIES[strtolower(substr($locale, 0, 2))] ?? 'en-US';
    }

    /** Page title for a draft language when the bundled titles.json has one (uk and en use the built-in title keys). */
    public function title(string $key, string $locale): ?string
    {
        $file = $this->projectDir . '/resources/content/information/' . self::directoryFor($locale) . '/titles.json';
        if (!is_file($file)) {
            return null;
        }
        $titles = json_decode((string) file_get_contents($file), true);

        return is_array($titles) && is_string($titles[$key] ?? null) && $titles[$key] !== '' ? $titles[$key] : null;
    }
}
