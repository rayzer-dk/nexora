<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

final readonly class TranslationCatalogLoader
{
    public function __construct(private string $projectDir = '')
    {
    }

    /**
     * @param list<array{code:string,install_path:string,manifest_json?:string}> $activeExtensions
     * @return array<string,string>
     */
    public function load(string $locale, array $activeExtensions = []): array
    {
        $root = $this->projectDir !== '' ? $this->projectDir : dirname(__DIR__, 3);
        $locale = $this->normalize($locale);
        $catalog = [];

        $coreDir = $root . '/resources/translations/' . $locale;
        $coreFiles = $this->catalogFiles($coreDir, 'php');
        if ($coreFiles === []) {
            // One-release compatibility for pre-3.4.5 flat Core catalogs. New releases use locale directories.
            $legacyDir = $root . '/resources/translations';
            $coreFiles = is_dir($legacyDir) ? (glob($legacyDir . '/*.' . $locale . '.php') ?: []) : [];
            sort($coreFiles, SORT_STRING);
        }
        foreach ($coreFiles as $path) {
            $catalog = array_replace($catalog, $this->readPhpCatalog($path));
        }

        // Language packs of the store owner (var/translations/<locale>/*.json): they survive updates and win over the bundled texts.
        foreach ($this->catalogFiles($root . '/var/translations/' . $locale, 'json') as $path) {
            $catalog = array_replace($catalog, $this->readJsonCatalog($path));
        }

        foreach ($activeExtensions as $extension) {
            $code = trim((string) ($extension['code'] ?? ''));
            $installPath = (string) ($extension['install_path'] ?? '');
            if ($code === '' || $installPath === '' || !$this->isSafeInstalledPath($root, $code, $installPath)) {
                continue;
            }
            $manifest = $this->decodeManifest($extension, $installPath);
            $localization = is_array($manifest['localization'] ?? null) ? $manifest['localization'] : null;
            if ($localization === null) {
                continue;
            }
            $locales = is_array($localization['locales'] ?? null) ? $localization['locales'] : [];
            if (!in_array($locale, $locales, true)) {
                continue;
            }
            $relative = str_replace('\\', '/', trim((string) ($localization['translations_path'] ?? 'translations'), '/'));
            if (!$this->isSafeRelativePath($relative)) {
                continue;
            }
            $base = rtrim($installPath, '/\\') . '/' . $relative;
            $files = $this->catalogFiles($base . '/' . $locale, 'json');
            $flat = $base . '/' . $locale . '.json';
            if (is_file($flat)) {
                $files[] = $flat;
                $files = array_values(array_unique($files));
                sort($files, SORT_STRING);
            }
            $prefix = self::namespaceForCode($code);
            foreach ($files as $path) {
                foreach ($this->readJsonCatalog($path) as $key => $value) {
                    if (!str_starts_with($key, $prefix)) {
                        continue;
                    }
                    // Core keys and other extensions can never be overwritten because only the extension namespace is accepted.
                    $catalog[$key] = $value;
                }
            }
        }

        return $catalog;
    }


    /** @return list<string> */
    private function catalogFiles(string $directory, string $extension): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== strtolower($extension)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public static function namespaceForCode(string $code): string
    {
        $token = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($code)) ?? '');
        $token = trim($token, '_');
        return 'extension.' . $token . '.';
    }

    /** @return array<string,string> */
    private function readPhpCatalog(string $path): array
    {
        $data = is_file($path) ? require $path : [];
        return $this->normalizeCatalog($data);
    }

    /** @return array<string,string> */
    private function readJsonCatalog(string $path): array
    {
        if (!is_file($path) || filesize($path) > 1024 * 1024) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return $this->normalizeCatalog($data);
    }

    /** @return array<string,string> */
    private function normalizeCatalog(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array{manifest_json?:string} $extension @return array<string,mixed> */
    private function decodeManifest(array $extension, string $installPath): array
    {
        $raw = (string) ($extension['manifest_json'] ?? '');
        if ($raw === '' && is_file($installPath . '/manifest.json')) {
            $raw = (string) file_get_contents($installPath . '/manifest.json');
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function isSafeInstalledPath(string $root, string $code, string $installPath): bool
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $code) !== 1) {
            return false;
        }
        $base = realpath(rtrim($root, '/\\') . '/var/extensions/installed/' . $code);
        $real = realpath($installPath);
        if ($base === false || $real === false) {
            return false;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $real = rtrim(str_replace('\\', '/', $real), '/') . '/';
        return str_starts_with($real, $base);
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && preg_match('#(^|/)\.\.(?:/|$)#', $path) !== 1
            && preg_match('#^[A-Za-z0-9_.\/-]{1,160}$#D', $path) === 1;
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
