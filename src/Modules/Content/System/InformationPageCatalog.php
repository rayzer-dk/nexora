<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\System;

use InvalidArgumentException;
use JsonException;

final class InformationPageCatalog
{
    /** @var array<string,mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly string $configPath)
    {
    }

    /** @return list<InformationPageDefinition> */
    public function all(): array
    {
        $result = [];
        foreach ((array) ($this->load()['pages'] ?? []) as $key => $page) {
            if (is_array($page)) {
                $result[] = $this->hydrate((string) $key, $page);
            }
        }
        return $result;
    }

    public function get(string $key): InformationPageDefinition
    {
        $page = $this->load()['pages'][$key] ?? null;
        if (!is_array($page)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.28ef77f265d4'), $key));
        }
        return $this->hydrate($key, $page);
    }

    /** @param array<string,mixed> $page */
    private function hydrate(string $key, array $page): InformationPageDefinition
    {
        return new InformationPageDefinition(
            $key,
            (string) ($page['route_key'] ?? $key),
            trim((string) ($page['title_key'] ?? '')) !== '' ? \Commerce\Core\I18n\CanonicalUiText::get((string) $page['title_key']) : (string) ($page['title'] ?? $key),
            (string) ($page['footer_group'] ?? 'help'),
            (bool) ($page['indexable'] ?? true),
            array_values(array_map('strval', (array) ($page['required_store_fields'] ?? []))),
            array_values(array_map('strval', (array) ($page['sections'] ?? []))),
        );
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $json = @file_get_contents($this->configPath);
        if ($json === false) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cd7560193524'));
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.954352852e28'), 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e92db3c14dd1'));
        }
        return $this->config = $data;
    }
}
