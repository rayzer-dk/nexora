<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

require_once dirname(__DIR__) . '/I18n/TranslationCatalogLoader.php';

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Security\OutboundUrlPolicy;
use RuntimeException;
use ZipArchive;

final class ExtensionPackageValidator
{
    /** Regions rendered by the product page; a block declared for any other region would never be shown. */
    public const PRODUCT_REGIONS = ['hero_media', 'hero_summary', 'below_primary', 'below_secondary', 'mobile_sticky'];

    public function __construct(
        private readonly ExtensionSettingsSchemaValidator $settingsSchemas,
        private readonly OutboundUrlPolicy $outboundUrls,
        private readonly TrustedExtensionSignatureVerifier $trustedSignatures,
    ) {
    }

    private const MAX_ARCHIVE_BYTES = 52428800; // 50 MiB
    private const MAX_UNCOMPRESSED_BYTES = 157286400; // 150 MiB
    private const MAX_FILES = 5000;

    /** @var list<string> */
    private const SAFE_EXTENSIONS = ['json','css','png','jpg','jpeg','webp','avif','gif','txt','md','woff','woff2','ed25519'];
    /** @var list<string> */
    private const EXECUTABLE_EXTENSIONS = ['php','phtml','js','mjs','cjs','twig'];
    /** @var list<string> */
    private const FORBIDDEN_EXTENSIONS = ['phar','sh','bash','exe','dll','so','dylib','bat','cmd','ps1','jar','py','pl','rb'];

    /** @param bool $verifySignature false is used only by the pack command, which builds the archive before it can be signed */
    public function inspect(string $archivePath, bool $verifySignature = true): ExtensionPackageInspection
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f79cfe9d29c3'));
        }
        $size = filesize($archivePath);
        if (!is_int($size) || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2e5ef6fa415b'));
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b357a3330d58'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($result !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f17550c74bb7'));
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.55c421cfd90d'));
            }
            $files = [];
            $warnings = [];
            $uncompressed = 0;
            $hasExecutable = false;
            $manifestRaw = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b6e1178b9052'));
                }
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $name) || str_contains($name, "\0")) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6855495b2697'));
                }
                if ($this->isSymlink($zip, $i)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fa28d040ee99'));
                }
                $entrySize = (int) ($stat['size'] ?? 0);
                $compressedSize = max(0, (int) ($stat['comp_size'] ?? 0));
                if ($entrySize > 1024 * 1024 && $compressedSize > 0 && ($entrySize / $compressedSize) > 200.0) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.850c774b4f74'));
                }
                if ($entrySize < 0 || $entrySize > 25 * 1024 * 1024) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e941984f8307'));
                }
                $uncompressed += $entrySize;
                if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.825e8ff0d233'));
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $files[] = $name;
                $basename = basename($name);
                if ($name === 'manifest.json') {
                    $manifestRaw = $zip->getFromIndex($i);
                }
                if (str_starts_with($basename, '.') && $basename !== '.well-known') {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b28062941f6b'));
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($extension === 'twig') {
                    if (!str_starts_with($name, 'templates/') || str_starts_with($name, 'templates/admin/') || str_starts_with($name, 'templates/email/') || str_starts_with($name, 'templates/order_document/')) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.theme.error.storefront_only'));
                    }
                }
                if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.903b5e1cc3f9') . $extension);
                }
                if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
                    $hasExecutable = true;
                    continue;
                }
                if ($extension === '' || !in_array($extension, self::SAFE_EXTENSIONS, true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a3768b5df519') . $extension);
                }
                if (in_array($extension, ['json','css','txt','md'], true) && $entrySize <= 1024 * 1024) {
                    $contents = (string) $zip->getFromIndex($i);
                    if (str_contains($contents, "\0")) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fe9981445078'));
                    }
                    if ($extension === 'css') {
                        if (preg_match('/(?:expression\s*\(|javascript\s*:|-moz-binding\s*:|behavior\s*:)/i', $contents)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.07a267cd240f'));
                        }
                        if (preg_match('/@import\s+(?:url\s*\()?\s*["\']?(?:https?:|data:|file:|\/\/)/i', $contents)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.56bb20df60c2'));
                        }
                        if (preg_match('/url\s*\(\s*["\']?(?:https?:|data:|file:|javascript:|\/\/)/i', $contents)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a7a22973f018'));
                        }
                    }
                }
            }

            if (!is_string($manifestRaw) || trim($manifestRaw) === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cb5f73d5794d'));
            }
            try {
                $manifest = json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8ef51a3a36ec') . $e->getMessage());
            }
            if (!is_array($manifest)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0a959cdb9b44'));
            }
            $this->validateManifest($manifest);
            $this->validateSettingsSchemaFromArchive($zip, $manifest, $files);
            $this->validateLocalizationFromArchive($zip, $manifest, $files);
            $this->validateContributions($manifest, $files);

            $trustedExecutable = $hasExecutable && (string) ($manifest['execution'] ?? '') === 'trusted_release' && in_array((string) ($manifest['type'] ?? ''), ['trusted-module','theme'], true);
            if ($trustedExecutable && $verifySignature) {
                $this->trustedSignatures->verify($zip, $manifest);
            } elseif ($trustedExecutable) {
                $warnings[] = 'Trusted package is not signed yet: run commerce:extension:sign before installing it.';
            }

            $quarantineReason = '';
            $quarantined = false;
            if ($hasExecutable && !$trustedExecutable) {
                $quarantined = true;
                $quarantineReason = 'Package contains executable code without a trusted publisher signature.';
                $warnings[] = $quarantineReason;
            }
            if (($manifest['isolation'] ?? 'contract_only') !== 'contract_only') {
                $quarantined = true;
                $quarantineReason = 'This package requires an isolation mode that is not enabled on this release.';
                $warnings[] = $quarantineReason;
            }

            return new ExtensionPackageInspection($manifest, $files, array_values(array_unique($warnings)), $quarantined, $quarantineReason);
        } finally {
            $zip->close();
        }
    }

    /** @param array<string,mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        foreach (['code','name','version','type','core','extension_api','permissions'] as $required) {
            if (!array_key_exists($required, $manifest)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cdef08aefc1c') . $required);
            }
        }
        $code = (string) $manifest['code'];
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $code)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b153be2da952'));
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) $manifest['version'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3ff0cfb55f7d'));
        }
        if (!in_array((string) $manifest['type'], ['trusted-module','app','theme'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.55374a519ed3'));
        }
        if ((string) $manifest['extension_api'] !== PlatformVersion::EXTENSION_API) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2090de367606') . (string) $manifest['extension_api'] . \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.eaeb56b26026') . PlatformVersion::EXTENSION_API . '.');
        }
        $coreConstraint = trim((string) $manifest['core']);
        if (!$this->supportsCoreConstraint($coreConstraint)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ef7df298b331') . PlatformVersion::VERSION . '.');
        }
        if (!is_array($manifest['permissions'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.abf2acdeb454'));
        }
        foreach ($manifest['permissions'] as $permission) {
            if (!is_string($permission) || preg_match('/^[a-z][a-z0-9_.:-]{1,127}$/D', $permission) !== 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.67e8fa4fdd78'));
            }
        }
        $execution = (string) ($manifest['execution'] ?? 'declarative');
        if (!in_array($execution, ['declarative', 'remote_app', 'trusted_release'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3abd5657adb8'));
        }
        if ($execution === 'remote_app') {
            $remote = $manifest['remote'] ?? null;
            if (!is_array($remote) || !is_string($remote['base_url'] ?? null)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5337d17f2818'));
            }
            $this->outboundUrls->assertPublicHttps((string) $remote['base_url']);
        }
        if (isset($manifest['commercial'])) {
            $commercial = $manifest['commercial'];
            if (!is_array($commercial) || !in_array((string)($commercial['model'] ?? ''), ['free','one_time','subscription','external'], true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.commercial.invalid'));
            }
            foreach (['license_url','manage_url'] as $urlKey) {
                if (isset($commercial[$urlKey])) $this->outboundUrls->assertPublicHttps((string)$commercial[$urlKey]);
            }
            if (isset($commercial['trial_days']) && ((int)$commercial['trial_days'] < 0 || (int)$commercial['trial_days'] > 365)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.commercial.invalid'));
            }
        }
        $providerCapabilities = ['provider.payment','provider.shipping','provider.product_block','provider.ai','provider.translation'];
        foreach ((array) ($manifest['capabilities'] ?? []) as $capability) {
            if (str_starts_with((string) $capability, 'provider.') && !in_array((string) $capability, $providerCapabilities, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.provider_capability_unknown') . (string) $capability);
            }
            if (in_array((string) $capability, $providerCapabilities, true) && $execution !== 'trusted_release') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.provider_trusted_required'));
            }
        }

        if ($execution === 'trusted_release') {
            if (!in_array((string) ($manifest['type'] ?? ''), ['trusted-module','theme'], true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.d62865e08fde'));
            }
            if (!is_array($manifest['publisher'] ?? null) || !is_array($manifest['signature'] ?? null)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.eb43c6ae2119'));
            }
            if ((string) ($manifest['type'] ?? '') === 'trusted-module' && (!is_array($manifest['autoload'] ?? null) || !is_string($manifest['entrypoint'] ?? null))) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.a833cadc409f'));
            }
        }
        if (isset($manifest['settings_schema'])) {
            $settingsSchema = str_replace('\\', '/', (string) $manifest['settings_schema']);
            if ($settingsSchema === '' || str_starts_with($settingsSchema, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $settingsSchema) === 1 || !str_ends_with(strtolower($settingsSchema), '.json')) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0d30528c8d7b'));
            }
        }
        if (isset($manifest['localization'])) {
            $localization = $manifest['localization'];
            if (!is_array($localization)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.invalid'));
            }
            $allowed = ['default_locale','locales','translations_path'];
            if (array_diff(array_keys($localization), $allowed) !== []) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.invalid'));
            }
            if (($localization['default_locale'] ?? null) !== 'uk-UA') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.default_uk_required'));
            }
            $locales = $localization['locales'] ?? null;
            if (!is_array($locales) || $locales === [] || count($locales) > 32 || !in_array('uk-UA', $locales, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.locales_invalid'));
            }
            $seenLocales = [];
            foreach ($locales as $locale) {
                if (!is_string($locale) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) !== 1 || isset($seenLocales[$locale])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.locales_invalid'));
                }
                $seenLocales[$locale] = true;
            }
            $translationPath = str_replace('\\', '/', trim((string) ($localization['translations_path'] ?? ''), '/'));
            if ($translationPath === '' || str_starts_with($translationPath, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $translationPath) === 1 || preg_match('#^[A-Za-z0-9_.\/-]{1,160}$#D', $translationPath) !== 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.path_invalid'));
            }
        }
        $this->validateScheduledTasks($manifest);
        foreach (['events', 'ui_slots', 'capabilities'] as $listField) {
            if (!isset($manifest[$listField])) {
                continue;
            }
            if (!is_array($manifest[$listField]) || count($manifest[$listField]) > 128) {
                throw new RuntimeException($listField . \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.e51a68d59c2e'));
            }
            foreach ($manifest[$listField] as $item) {
                if (!is_string($item) || $item === '' || strlen($item) > 190) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5f349ed1628c') . $listField . '.');
                }
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function validateScheduledTasks(array $manifest): void
    {
        if (!isset($manifest['scheduled_tasks'])) {
            return;
        }
        $tasks = $manifest['scheduled_tasks'];
        if (!is_array($tasks) || count($tasks) > 16 || (string) ($manifest['execution'] ?? '') !== 'trusted_release') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.scheduled_task.invalid'));
        }
        $seen = [];
        foreach ($tasks as $task) {
            $code = is_array($task) ? $task['code'] ?? null : null;
            $interval = is_array($task) ? $task['interval'] ?? null : null;
            $label = is_array($task) ? $task['label'] ?? '' : '';
            $description = is_array($task) ? $task['description'] ?? '' : '';
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $code) !== 1 || isset($seen[$code])
                || !is_int($interval) || $interval < 300 || $interval > 604800
                || !is_string($label) || $label === '' || mb_strlen($label) > 120 || !is_string($description) || mb_strlen($description) > 300) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.scheduled_task.invalid'));
            }
            $seen[$code] = true;
        }
    }

    /** @param array<string,mixed> $manifest @param list<string> $files */
    private function validateSettingsSchemaFromArchive(ZipArchive $zip, array $manifest, array $files): void
    {
        $paths = [];
        if (isset($manifest['settings_schema'])) {
            $paths[] = str_replace('\\', '/', (string) $manifest['settings_schema']);
        }
        foreach ((array) ($manifest['blocks'] ?? []) as $block) {
            if (is_array($block) && isset($block['settings_schema'])) {
                $paths[] = str_replace('\\', '/', (string) $block['settings_schema']);
            }
        }
        foreach (array_values(array_unique($paths)) as $path) {
            if ($path === '' || !in_array($path, $files, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4d176f200751') . ' ' . $path);
            }
            $raw = $zip->getFromName($path);
            if (!is_string($raw)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a7ce4b21a901'));
            }
            $this->settingsSchemas->decodeAndValidate($raw);
        }
    }

    /** @param array<string,mixed> $manifest @param list<string> $files */
    private function validateLocalizationFromArchive(ZipArchive $zip, array $manifest, array $files): void
    {
        $localization = $manifest['localization'] ?? null;
        if (!is_array($localization)) {
            foreach ($files as $file) {
                if (str_starts_with(str_replace('\\', '/', $file), 'translations/')) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.manifest_required'));
                }
            }
            return;
        }

        $code = (string) $manifest['code'];
        $prefix = \Commerce\Core\I18n\TranslationCatalogLoader::namespaceForCode($code);
        $base = str_replace('\\', '/', trim((string) $localization['translations_path'], '/'));
        $locales = array_values($localization['locales']);
        $filesByLocale = array_fill_keys($locales, []);

        foreach ($files as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (!str_starts_with($normalized, $base . '/')) {
                continue;
            }
            $relative = substr($normalized, strlen($base) + 1);
            $matchedLocale = null;
            foreach ($locales as $locale) {
                if ($relative === $locale . '.json' || str_starts_with($relative, $locale . '/')) {
                    $matchedLocale = $locale;
                    break;
                }
            }
            if ($matchedLocale === null || !str_ends_with(strtolower($normalized), '.json')) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.file_invalid'));
            }
            $filesByLocale[$matchedLocale][] = $normalized;
        }

        foreach ($locales as $locale) {
            if (($filesByLocale[$locale] ?? []) === []) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.file_missing') . $locale);
            }
            $seen = [];
            sort($filesByLocale[$locale], SORT_STRING);
            foreach ($filesByLocale[$locale] as $file) {
                $raw = $zip->getFromName($file);
                if (!is_string($raw) || strlen($raw) > 1024 * 1024) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.file_invalid'));
                }
                try {
                    $catalog = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.json_invalid'), 0, $e);
                }
                if (!is_array($catalog) || array_is_list($catalog)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.json_invalid'));
                }
                foreach ($catalog as $key => $value) {
                    if (!is_string($key) || !is_string($value) || !str_starts_with($key, $prefix)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.namespace_invalid') . $prefix . '*');
                    }
                    if (isset($seen[$key])) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.duplicate_key') . $key);
                    }
                    $seen[$key] = true;
                }
            }
        }
    }


    /** @param array<string,mixed> $manifest @param list<string> $files */
    private function validateContributions(array $manifest, array $files): void
    {
        $code = (string) ($manifest['code'] ?? '');
        $namespace = str_replace(['.', '-'], '_', $code);
        $prefix = 'extension.' . $namespace . '.';

        $seenBlocks = [];
        foreach ((array) ($manifest['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.bc028d3d4039'));
            }
            $id = (string) ($block['id'] ?? '');
            if (!str_starts_with($id, $prefix) || preg_match('/^extension\.[a-z0-9_]+\.[a-z][a-z0-9_.-]{0,95}$/D', $id) !== 1 || isset($seenBlocks[$id])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.b21f2a137ac2') . $prefix . '*');
            }
            $surface = (string) ($block['surface'] ?? '');
            if (!in_array($surface, ['home','header','footer','product','checkout','category','cart','account','admin'], true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.879c32fc0688') . $surface);
            }
            $regions = $block['regions'] ?? [];
            if (!is_array($regions) || $regions === [] || count($regions) > 32) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.3b02c5512b48'));
            }
            if ($surface === 'product') {
                foreach ($regions as $region) {
                    if (!is_string($region) || !in_array($region, self::PRODUCT_REGIONS, true)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.product_region_invalid') . (is_string($region) ? $region : '?') . ' (' . implode(', ', self::PRODUCT_REGIONS) . ')');
                    }
                }
            }
            if (isset($block['settings_schema'])) {
                $schemaPath = str_replace('\\', '/', (string) $block['settings_schema']);
                if ($schemaPath === '' || str_starts_with($schemaPath, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $schemaPath) === 1 || !in_array($schemaPath, $files, true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.block_schema_missing') . $schemaPath);
                }
            }
            $seenBlocks[$id] = true;
        }

        $seenNames = [];
        $seenPaths = [];
        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (!is_array($route)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.6d8d109704c3'));
            }
            $name = (string) ($route['name'] ?? '');
            $path = (string) ($route['path'] ?? '');
            if (!str_starts_with($name, $prefix) || isset($seenNames[$name])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e653d9e38a04') . $prefix . '*');
            }
            $isAdmin = str_starts_with($path, '/admin/');
            $adminPrefix = '/admin/extensions/' . $namespace . '/';
            if ($path === '' || $path[0] !== '/' || str_starts_with($path, '/setup') || ($isAdmin && !str_starts_with($path, $adminPrefix)) || isset($seenPaths[$path])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.da60de54a78e') . $path);
            }
            if ($isAdmin) {
                $permission = (string) ($route['permission'] ?? '');
                if ($permission === '' || !in_array($permission, (array) ($manifest['permissions'] ?? []), true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.211aebfea768'));
                }
                if ((string) ($route['mode'] ?? '') !== 'trusted_handler') {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e66ffb3ffe6b'));
                }
            }
            $seenNames[$name] = true;
            $seenPaths[$path] = true;
        }

        foreach ((array) ($manifest['permissions'] ?? []) as $permission) {
            if (!str_starts_with((string) $permission, $prefix)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.eb7e07ccbdfb') . $prefix . '*');
            }
        }

        foreach ((array) ($manifest['pages'] ?? []) as $page) {
            if (!is_array($page) || !str_starts_with((string) ($page['id'] ?? ''), $prefix) || !str_starts_with((string) ($page['title_key'] ?? ''), $prefix)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.66b02a7ce038') . $prefix . '*');
            }
            if (isset($page['content_key']) && !str_starts_with((string) $page['content_key'], $prefix)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e6392cb28b2c') . $prefix . '*');
            }
        }

        foreach ((array) ($manifest['slot_contributions'] ?? []) as $contribution) {
            $slot = is_array($contribution) ? (string) ($contribution['slot'] ?? '') : '';
            if (!is_array($contribution) || !in_array($slot, ExtensionPoint::values(), true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.77e0d1d2e10a'));
            }
            if (!in_array($slot, (array) ($manifest['ui_slots'] ?? []), true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.slot_not_declared') . $slot);
            }
            foreach (['title_key','content_key'] as $translationField) {
                if (isset($contribution[$translationField]) && !str_starts_with((string) $contribution[$translationField], $prefix)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.slot_translation_namespace') . $prefix . '*');
                }
            }
            $component = (string) ($contribution['component'] ?? '');
            if (!isset($seenBlocks[$component])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.9a226eeb681b') . $component);
            }
        }

        foreach ((array) ($manifest['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.fd2fcf8cff13'));
            }
            $path = str_replace('\\', '/', (string) ($asset['path'] ?? ''));
            if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $path) === 1 || !in_array($path, $files, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.5f5921161acc') . $path);
            }
            $type = (string) ($asset['type'] ?? '');
            if (!in_array($type, ['css','js'], true) || !str_ends_with(strtolower($path), '.' . $type)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.a42f9cc28cad'));
            }
            if ($type === 'js' && (string) ($manifest['execution'] ?? '') !== 'trusted_release') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.b6fd0dd4c6d9'));
            }
        }

        foreach ((array) ($manifest['migrations'] ?? []) as $migration) {
            $path = str_replace('\\', '/', (string) $migration);
            if (!in_array($path, $files, true) || !str_starts_with($path, 'migrations/') || !str_ends_with(strtolower($path), '.php')) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.beab99a5a0f6') . $path);
            }
            if ((string) ($manifest['execution'] ?? '') !== 'trusted_release') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.4e467a8f699a'));
            }
        }
    }

    private function supportsCoreConstraint(string $constraint): bool
    {
        if (preg_match('/^(\^|~|>=)?\s*(\d+)\.(\d+)\.(\d+)$/D', $constraint, $match) !== 1) {
            return false;
        }
        $operator = $match[1] ?? '';
        $base = $match[2] . '.' . $match[3] . '.' . $match[4];
        $current = PlatformVersion::VERSION;
        if ($operator === '') {
            return version_compare($current, $base, '==');
        }
        if ($operator === '>=') {
            return version_compare($current, $base, '>=');
        }
        if (version_compare($current, $base, '<')) {
            return false;
        }
        $major = (int) $match[2];
        $minor = (int) $match[3];
        $patch = (int) $match[4];
        if ($operator === '~') {
            $upper = $major . '.' . ($minor + 1) . '.0';
        } elseif ($major > 0) {
            $upper = ($major + 1) . '.0.0';
        } elseif ($minor > 0) {
            $upper = '0.' . ($minor + 1) . '.0';
        } else {
            $upper = '0.0.' . ($patch + 1);
        }
        return version_compare($current, $upper, '<');
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }
        return (($attributes >> 16) & 0170000) === 0120000;
    }
}
