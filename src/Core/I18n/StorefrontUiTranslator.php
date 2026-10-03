<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

require_once __DIR__ . '/TranslationCatalogLoader.php';

use Doctrine\DBAL\Connection;

final class StorefrontUiTranslator
{
    /** @var array<string,array<string,string>> */
    private array $catalogs = [];

    private readonly TranslationCatalogLoader $loader;

    public function __construct(
        string $projectDir = '',
        private readonly ?Connection $connection = null,
    ) {
        $this->loader = new TranslationCatalogLoader($projectDir);
    }

    public function translate(string $key, string $locale, array $replace = []): string
    {
        $locale = $this->normalize($locale);
        $catalog = $this->catalog($locale);
        $fallback = $this->catalog('uk-UA');
        $value = $catalog[$key] ?? $fallback[$key] ?? $key;
        foreach ($replace as $name => $replacement) {
            $value = str_replace('%' . $name . '%', (string) $replacement, $value);
        }
        return $value;
    }

    /** @return array<string,string> */
    public function catalogFor(string $locale): array
    {
        $locale = $this->normalize($locale);
        return $this->catalog($locale) + $this->catalog('uk-UA');
    }

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }
        $catalog = $this->loader->load($locale, $this->activeExtensions());
        if ($locale !== 'uk-UA' && $catalog === []) {
            return $this->catalogs[$locale] = $this->catalog('uk-UA');
        }
        return $this->catalogs[$locale] = $catalog;
    }

    /** @return list<array{code:string,install_path:string,manifest_json:string}> */
    private function activeExtensions(): array
    {
        if ($this->connection === null) {
            return [];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT code,install_path,manifest_json FROM mc_extension_installation WHERE status='active' ORDER BY code,id"
            );
        } catch (\Throwable) {
            // Installer/bootstrap can run before the extensions table exists. Core translations must still work.
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'code' => (string) ($row['code'] ?? ''),
                'install_path' => (string) ($row['install_path'] ?? ''),
                'manifest_json' => (string) ($row['manifest_json'] ?? ''),
            ];
        }
        return $result;
    }

    private function normalize(string $locale): string
    {
        $value = str_replace('_', '-', trim($locale));
        if ($value === '') {
            return 'uk-UA';
        }
        $map = ['uk' => 'uk-UA', 'en' => 'en-US', 'de' => 'de-DE', 'da' => 'da-DK', 'ru' => 'ru-RU', 'pl' => 'pl-PL'];
        return $map[strtolower($value)] ?? $value;
    }
}
