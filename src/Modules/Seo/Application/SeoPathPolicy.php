<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use InvalidArgumentException;

final class SeoPathPolicy
{
    /** @var list<string> */
    private const INTERNAL_RESERVED_ROOTS = [
        'admin', 'api', 'graphql', 'assets', 'build', 'sitemap.xml', 'robots.txt', 'llms.txt', 'llms-full.txt', '.well-known',
        'health', 'webhooks', 'media', 'uploads', 'manifest.webmanifest', 'sw.js', 'offline', 'withdrawal', 'accessibility',
    ];

    public function __construct(private readonly SystemPageRouteCatalog $systemPages)
    {
    }

    public function path(SeoEntityType $type, string $slug, string $locale = 'uk-UA'): string
    {
        $prefix = $this->systemPages->entityNamespace($type, $locale);
        $path = $prefix === '' ? $slug : $prefix . '/' . $slug;
        $this->assertAllowed($path, $prefix);
        return $path;
    }

    public function withCollisionSuffix(string $baseSlug, int $suffix): string
    {
        if ($suffix < 2) {
            return $baseSlug;
        }
        $suffixText = '-' . $suffix;
        $maxBase = max(1, 180 - strlen($suffixText));
        return rtrim(mb_substr($baseSlug, 0, $maxBase, 'UTF-8'), '-') . $suffixText;
    }

    public function normalizeIncomingPath(string $path): string
    {
        $path = rawurldecode(parse_url('/' . ltrim($path, '/'), PHP_URL_PATH) ?: '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return trim($path, '/');
    }

    private function assertAllowed(string $path, string $ownedNamespace = ''): void
    {
        $root = explode('/', $path, 2)[0];
        if (in_array($root, self::INTERNAL_RESERVED_ROOTS, true)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.3f90b8547019'), $path));
        }
        if ($ownedNamespace === '' && in_array($root, $this->systemPages->reservedRoots(), true)) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.e71f27d7dac2'), $path));
        }
        if ($ownedNamespace !== '' && $root !== $ownedNamespace) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d9b21ec5c075'));
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $path)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aa0810576642'));
        }
        if (str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.95cc3a276d72'));
        }
    }
}
