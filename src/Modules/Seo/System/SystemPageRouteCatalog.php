<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\System;

use Commerce\Modules\Seo\Domain\SeoEntityType;
use InvalidArgumentException;
use JsonException;

final class SystemPageRouteCatalog
{
    /** @var array<string,mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly string $configPath)
    {
    }

    public function route(string $key, string $locale = 'uk-UA'): SystemPageDefinition
    {
        $config = $this->load();
        $page = $config['pages'][$key] ?? null;
        if (!is_array($page)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.568fa717e2b0'), $key));
        }

        $defaultLocale = (string) ($config['default_locale'] ?? 'uk-UA');
        $locales = (array) ($page['locales'] ?? []);
        $localized = $locales[$locale] ?? $locales[$defaultLocale] ?? null;
        if (!is_array($localized)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.855d1061a13a'), $key, $locale));
        }
        $path = (string) ($localized['path'] ?? '');
        $titleKey = trim((string) ($localized['title_key'] ?? ''));
        $title = $titleKey !== '' ? \Commerce\Core\I18n\CanonicalUiText::get($titleKey) : trim((string) ($localized['title'] ?? ''));
        $this->assertSafePath($path);
        if ($title === '') {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.e10e38557dc9'), $key));
        }

        return new SystemPageDefinition($key, $title, $path, (bool) ($page['indexable'] ?? false));
    }

    public function entityNamespace(SeoEntityType $type, string $locale): string
    {
        $key = $type->value;
        $config = $this->load();
        $defaultLocale = (string) ($config['default_locale'] ?? 'uk-UA');
        $sets = (array) ($config['entity_namespaces'] ?? []);
        $set = (array) ($sets[$locale] ?? $sets[$defaultLocale] ?? []);
        $namespace = (string) ($set[$key] ?? '');
        if ($namespace !== '') {
            $this->assertSafePath($namespace);
        }
        return $namespace;
    }

    /** @return list<string> */
    public function reservedRoots(): array
    {
        $roots = [];
        foreach ((array) ($this->load()['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ((array) ($page['locales'] ?? []) as $localized) {
                if (!is_array($localized)) {
                    continue;
                }
                $path = (string) ($localized['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $roots[explode('/', $path, 2)[0]] = true;
            }
        }
        return array_keys($roots);
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $json = @file_get_contents($this->configPath);
        if ($json === false) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b992a15feb76'));
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.615e6082da38'), 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c0f24fcb9c4b'));
        }
        return $this->config = $data;
    }

    private function assertSafePath(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $path)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.b1f64799d981'), $path));
        }
    }
}
