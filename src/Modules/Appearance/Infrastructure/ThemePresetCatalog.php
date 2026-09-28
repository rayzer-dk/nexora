<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

final readonly class ThemePresetCatalog
{
    public function __construct(private string $configPath)
    {
    }

    /** @return array<string,array{label:string,description_key:string,theme:array<string,string>}> */
    public function all(): array
    {
        $json = @file_get_contents($this->configPath);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Theme preset configuration is unavailable.');
        }

        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || $data === []) {
            throw new \RuntimeException('Theme preset configuration is invalid.');
        }

        $out = [];
        foreach ($data as $code => $preset) {
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $code) || !is_array($preset)) {
                continue;
            }
            $theme = is_array($preset['theme'] ?? null) ? $preset['theme'] : [];
            $out[$code] = [
                'label' => trim((string)($preset['label'] ?? ucfirst($code))),
                'description_key' => trim((string)($preset['description_key'] ?? '')),
                'theme' => array_map(static fn (mixed $value): string => trim((string)$value), $theme),
            ];
        }

        if ($out === []) {
            throw new \RuntimeException('Theme preset configuration contains no usable presets.');
        }

        return $out;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }
}
