<?php

declare(strict_types=1);

namespace Commerce\Core\Deployment;

use RuntimeException;

final readonly class ImmutableReleaseLayout
{
    public function __construct(private string $root)
    {
    }

    public function releasesDir(): string { return $this->path('releases'); }
    public function sharedDir(): string { return $this->path('shared'); }
    public function currentLink(): string { return $this->path('current'); }

    public function ensure(): void
    {
        foreach ([$this->releasesDir(), $this->sharedDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.08fe57efbc85') . $dir);
            }
        }
    }

    public function releaseDir(string $releaseId): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $releaseId) !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f89ea4e4b59c'));
        }
        return $this->releasesDir() . '/' . $releaseId;
    }

    private function path(string $suffix): string
    {
        return rtrim($this->root, '/\\') . '/' . $suffix;
    }
}
