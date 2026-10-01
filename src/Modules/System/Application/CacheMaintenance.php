<?php

declare(strict_types=1);

namespace Commerce\Modules\System\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Media\Application\MediaVariantService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontCacheVersion;
use Commerce\Modules\Storefront\Projection\StorefrontFacetProjectionStore;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** The clean-up actions of the "Cache and maintenance" page. Every action only drops data that is rebuilt on demand. */
final class CacheMaintenance
{
    public const ACTIONS = ['storefront', 'templates', 'app', 'images', 'warm', 'opcache', 'all'];

    public function __construct(
        private readonly StorefrontCacheVersion $version,
        private readonly StorefrontFacetProjectionStore $facets,
        private readonly CacheInterface $cache,
        private readonly MediaVariantService $variants,
        private readonly string $cacheDir,
        private readonly string $projectDir,
    ) {
    }

    /** @return array{templates:array{files:int,bytes:int},images:array{files:int,bytes:int},avif:bool,generation:int,opcache:bool} */
    public function overview(): array
    {
        $profile = $this->variants->profile();

        return [
            'templates' => self::measure($this->cacheDir . '/twig'),
            'images' => self::measure($this->projectDir . '/public/media/cache'),
            'avif' => $this->variants->avifEnabled(),
            'generation' => (int) $profile['generation'],
            'opcache' => function_exists('opcache_reset') && (bool) ini_get('opcache.enable'),
        ];
    }

    /** @return array{files:int,bytes:int} what the action removed or made (0 when it only drops entries without counting) */
    public function run(string $action): array
    {
        return match ($action) {
            'storefront' => $this->storefront(),
            'templates' => $this->templates(),
            'app' => $this->app(),
            'images' => $this->images(),
            'warm' => ['files' => $this->variants->warmPrimaries(200000, time() + 20), 'bytes' => 0],
            'opcache' => $this->opcache(),
            'all' => $this->all(),
            default => throw new \InvalidArgumentException(CanonicalUiText::get('admin.system.maintenance.refused')),
        };
    }

    /** @return array{files:int,bytes:int} */
    private function storefront(): array
    {
        $this->version->bump();
        $this->facets->clear();

        return ['files' => 0, 'bytes' => 0];
    }

    /** @return array{files:int,bytes:int} */
    private function templates(): array
    {
        $dir = $this->cacheDir . '/twig';
        $before = self::measure($dir);
        self::removeTree($dir, false);

        return $before;
    }

    /** @return array{files:int,bytes:int} */
    private function app(): array
    {
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->clear();
        }
        $this->version->bump();
        $this->facets->clear();

        return ['files' => 0, 'bytes' => 0];
    }

    /** @return array{files:int,bytes:int} */
    private function images(): array
    {
        return $this->variants->collectStale(false, 0, true);
    }

    /** @return array{files:int,bytes:int} */
    private function opcache(): array
    {
        if (function_exists('opcache_reset') && (bool) ini_get('opcache.enable')) {
            @opcache_reset();
        }

        return ['files' => 0, 'bytes' => 0];
    }

    /** @return array{files:int,bytes:int} */
    private function all(): array
    {
        $this->app();
        $templates = $this->templates();
        $this->opcache();

        return $templates;
    }

    /** @return array{files:int,bytes:int} */
    public static function measure(string $dir): array
    {
        $result = ['files' => 0, 'bytes' => 0];
        if (!is_dir($dir)) {
            return $result;
        }
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $result['files']++;
                    $result['bytes'] += (int) $file->getSize();
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    private static function removeTree(string $dir, bool $removeRoot): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        if ($removeRoot) {
            @rmdir($dir);
        }
    }
}
