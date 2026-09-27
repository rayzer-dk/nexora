<?php

declare(strict_types=1);

namespace Commerce\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ViteAssetExtension extends AbstractExtension
{
    private ?array $manifest = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('vite_asset', [$this, 'asset'])];
    }

    public function asset(string $entry, string $fallback): string
    {
        $record = $this->manifest()[$entry] ?? null;
        if (!is_array($record)) {
            return $fallback;
        }

        $file = $record['file'] ?? null;
        if (!is_string($file) || $file === '' || str_contains($file, '..')) {
            return $fallback;
        }

        return '/build/' . ltrim($file, '/');
    }

    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = rtrim($this->projectDir, '/\\') . '/public/build/.vite/manifest.json';
        if (!is_file($path)) {
            return $this->manifest = [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->manifest = [];
        }

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }
}
