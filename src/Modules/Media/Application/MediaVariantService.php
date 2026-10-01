<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Doctrine\DBAL\Connection;
use GdImage;

/**
 * Sizes and formats ("variants") of a stored original picture, kept as a cache.
 *
 *  original: media/<folder>/<name>.<ext>                                       what was uploaded (jpg, png, webp, avif)
 *  variant:  media/cache/<preset>-g<N>/<folder>/<name>.<webp|avif|jpg>         made from the original, a plain file
 *
 * A variant URL is built without touching the database. The first request for a file that does not exist yet reaches the
 * media controller, which makes the file under a lock and serves it; every later request is answered by the web server
 * from disk. The set of presets is closed (see MediaImageProfile::PRESET_WIDTHS), so no other size can be requested.
 * The whole cache can be deleted at any time: it is made again on demand.
 */
final class MediaVariantService
{
    private const STEM = '[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*';
    private const ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    private const MAX_PIXELS = 48000000;

    /** @var array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int}|null */
    private ?array $profile = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigurationRevisionStore $revisions,
        private readonly string $projectDir,
    ) {
    }

    /** @return array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int} */
    public function profile(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }
        $payload = null;
        try {
            $storeId = (int) $this->connection->fetchOne("SELECT id FROM mc_store WHERE status='active' ORDER BY id LIMIT 1");
            if ($storeId > 0) {
                $payload = $this->revisions->latestValidPayload($storeId, 'media', 'image_processing');
            }
        } catch (\Throwable) {
            $payload = null;
        }

        return $this->profile = MediaImageProfile::normalize(is_array($payload) ? $payload : []);
    }

    /** True when the storefront also offers AVIF (chosen in the settings and supported by this PHP build). */
    public function avifEnabled(): bool
    {
        return $this->profile()['format'] === 'avif_webp' && function_exists('imageavif');
    }

    /** The format of the fallback (main) file: webp or jpg. */
    public function mainExtension(): string
    {
        return MediaImageProfile::extension($this->profile()['format']);
    }

    /**
     * URL of the named size of a stored original. Anything that is not a raster original under /media/ (placeholders,
     * SVG, external URLs, cache files) comes back unchanged. $ext picks the format (default: the main one).
     */
    public function url(string $url, string $preset, ?string $ext = null): string
    {
        $profile = $this->profile();
        if (!isset($profile['presets'][$preset]) || !str_starts_with($url, '/media/')) {
            return $url;
        }
        $key = substr($url, 7);
        if (str_starts_with($key, 'cache/') || preg_match('~^(?<stem>' . self::STEM . ')\.(?<ext>webp|avif|jpe?g|png)$~', $key, $m) !== 1) {
            return $url;
        }
        $ext = $ext === 'avif' ? 'avif' : $this->mainExtension();

        return '/media/cache/' . $preset . '-g' . $profile['generation'] . '/' . $m['stem'] . '.' . $ext;
    }

    /**
     * srcset of several presets of one picture, e.g. "…thumb… 160w, …card… 480w".
     *
     * @param list<string> $presets
     */
    public function srcset(string $url, array $presets, ?string $ext = null): string
    {
        $profile = $this->profile();
        $parts = [];
        foreach ($presets as $preset) {
            $variant = $this->url($url, $preset, $ext);
            if ($variant !== $url && isset($profile['presets'][$preset])) {
                $parts[] = $variant . ' ' . $profile['presets'][$preset] . 'w';
            }
        }

        return implode(', ', $parts);
    }

    /** @return array{stem:string,preset:string,gen:int,ext:string}|null */
    public function parse(string $key): ?array
    {
        if (preg_match('~^cache/(?<preset>thumb|card|product|zoom)-g(?<gen>\d{1,4})/(?<stem>' . self::STEM . ')\.(?<ext>webp|avif|jpg)$~', $key, $m) !== 1) {
            return null;
        }

        return ['stem' => $m['stem'], 'preset' => $m['preset'], 'gen' => (int) $m['gen'], 'ext' => $m['ext']];
    }

    /**
     * The URL with the current generation, when a request names an OLDER one (cached pages, bookmarks); otherwise null.
     * Never a redirect to an older generation: two requests that disagree about the current one cannot loop.
     */
    public function currentGenerationUrl(string $key): ?string
    {
        $parsed = $this->parse($key);
        if ($parsed === null || $parsed['gen'] >= $this->profile()['generation']) {
            return null;
        }

        return '/media/cache/' . $parsed['preset'] . '-g' . $this->profile()['generation'] . '/' . $parsed['stem'] . '.' . $parsed['ext'];
    }

    /** Relative path (to media/) of the original of a stem, or null when no original exists. */
    public function findOriginal(string $stem): ?string
    {
        if ($stem === '' || str_contains($stem, '..')) {
            return null;
        }
        foreach (self::ORIGINAL_EXTENSIONS as $ext) {
            if (is_file($this->path($stem . '.' . $ext))) {
                return $stem . '.' . $ext;
            }
        }

        return null;
    }

    /**
     * Path of the variant file; it is made first when missing. Null when the name is not a variant, the master is
     * missing, or the picture cannot be processed.
     */
    public function ensure(string $key): ?string
    {
        $parsed = $this->parse($key);
        if ($parsed === null) {
            return null;
        }
        $profile = $this->profile();
        $width = $profile['presets'][$parsed['preset']] ?? null;
        if ($width === null || $parsed['gen'] !== $profile['generation']) {
            return null;
        }
        $target = $this->path($key);
        if (is_file($target)) {
            return $target;
        }
        $original = $this->findOriginal($parsed['stem']);
        if ($original === null) {
            return null;
        }
        $master = $this->path($original);
        $lockDir = rtrim($this->projectDir, '/\\') . '/var/media-locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return null;
        }
        $lock = @fopen($lockDir . '/' . sha1($key) . '.lock', 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($target)) {
                return $target; // another request made it while this one waited
            }

            return $this->make($master, $target, $parsed['ext'], $width, $parsed['ext'] === 'avif' ? $profile['avif_quality'] : $profile['quality']) ? $target : null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockDir . '/' . sha1($key) . '.lock');
        }
    }

    /**
     * Makes the given presets of one stored picture now (the main photo of a product is prepared at once).
     *
     * @param list<string>|null $presets
     */
    public function warm(string $storageKey, ?array $presets = null): int
    {
        $made = 0;
        $original = '/media/' . ltrim($storageKey, '/');
        foreach ($presets ?? MediaImageProfile::EAGER_PRESETS as $preset) {
            foreach ($this->avifEnabled() ? ['webp', 'avif'] : [null] as $ext) {
                $url = $this->url($original, $preset, $ext);
                if ($url === $original) {
                    continue;
                }
                $key = substr($url, 7);
                $existed = is_file($this->path($key));
                if ($this->ensure($key) !== null && !$existed) {
                    ++$made;
                }
            }
        }

        return $made;
    }

    /**
     * Warms the primary photos that still lack their eager variants (background job after an import). Walks the photos
     * in id order in small chunks, so it is cheap to run often: a photo whose files exist costs three file checks.
     */
    public function warmPrimaries(int $limit = 200000, ?int $deadline = null): int
    {
        $made = 0;
        $seen = 0;
        $last = 0;
        while ($seen < $limit && ($deadline === null || time() < $deadline)) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT DISTINCT ma.id,ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.role='primary' AND ma.id>? ORDER BY ma.id LIMIT 500",
                [$last],
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                ++$seen;
                $made += $this->warm((string) $row['storage_key']);
                if ($deadline !== null && time() >= $deadline) {
                    break 2;
                }
            }
        }

        return $made;
    }

    /**
     * Removes cache files nobody references any more: older generations and files whose original is gone. Never touches
     * an original or any file outside media/cache. A newly made file always gets a grace period (except "everything").
     *
     * @return array{files:int,bytes:int}
     */
    public function collectStale(bool $dryRun = true, int $graceDays = 7, bool $everything = false, ?int $deadline = null): array
    {
        $root = rtrim($this->projectDir, '/\\') . '/public/media';
        $result = ['files' => 0, 'bytes' => 0];
        if (!is_dir($root . '/cache')) {
            return $result;
        }
        $current = $this->profile()['generation'];
        $limitTime = time() - max(0, $graceDays) * 86400;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/cache', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($deadline !== null && time() >= $deadline) {
                break;
            }
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $parsed = $this->parse($relative);
            $isTemporary = str_ends_with($relative, '.tmp');
            if ($parsed === null && !$isTemporary) {
                continue; // anything unexpected stays untouched
            }
            $stale = $everything || $isTemporary || $parsed['gen'] !== $current || $this->findOriginal($parsed['stem']) === null;
            if (!$stale || (!$everything && $file->getMTime() > $limitTime)) {
                continue;
            }
            $result['files']++;
            $result['bytes'] += (int) $file->getSize();
            if (!$dryRun) {
                @unlink($file->getPathname());
            }
        }
        if (!$dryRun) {
            $this->removeEmptyDirectories($root . '/cache');
        }

        return $result;
    }

    /** Removes every cache file of one picture (all presets, generations and formats). @return int files removed */
    public function forget(string $storageKey): int
    {
        $removed = 0;
        $stem = $this->stemOf($storageKey);
        foreach ($this->cacheFiles($stem) as $relative) {
            if (@unlink($this->path($relative))) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<string> cache files (relative to media/) of one picture */
    public function cacheFiles(string $stem): array
    {
        $files = [];
        if ($stem === '' || str_contains($stem, '..')) {
            return $files;
        }
        foreach (glob($this->path('cache') . '/*-g*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (['webp', 'avif', 'jpg'] as $ext) {
                $candidate = $dir . '/' . $stem . '.' . $ext;
                if (is_file($candidate)) {
                    $files[] = 'cache/' . basename($dir) . '/' . $stem . '.' . $ext;
                }
            }
        }

        return $files;
    }

    /** media/<folder>/<name>.<ext> => <folder>/<name> */
    public function stemOf(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');

        return preg_replace('~\.[A-Za-z0-9]+$~', '', $key) ?? $key;
    }

    private function removeEmptyDirectories(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            }
        }
    }

    public function path(string $key): string
    {
        return rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
    }

    private function make(string $original, string $target, string $ext, int $width, int $quality): bool
    {
        $info = @getimagesize($original);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return false;
        }
        if ($ext === 'avif' && !function_exists('imageavif')) {
            return false;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $sameFormat = match ($info['mime'] ?? '') {
            'image/webp' => $ext === 'webp',
            'image/avif' => $ext === 'avif',
            'image/jpeg' => $ext === 'jpg',
            default => false,
        };
        if ($sameFormat && $info[0] <= $width) {
            // The original is already that small and in that format: the variant is a plain copy.
            $ok = @copy($original, $temporary);
        } else {
            $raw = @file_get_contents($original);
            $source = is_string($raw) ? @imagecreatefromstring($raw) : false;
            if (!$source instanceof GdImage) {
                return false;
            }
            $targetWidth = min($width, $info[0]); // never enlarge
            $height = max(1, (int) round($info[1] * ($targetWidth / $info[0])));
            $canvas = imagecreatetruecolor($targetWidth, $height);
            if (!$canvas instanceof GdImage) {
                imagedestroy($source);

                return false;
            }
            if ($ext === 'jpg') {
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $height, (int) imagecolorallocate($canvas, 255, 255, 255));
            } else {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $height, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
            }
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $height, $info[0], $info[1]);
            $ok = match ($ext) {
                'webp' => function_exists('imagewebp') && @imagewebp($canvas, $temporary, $quality),
                'avif' => @imageavif($canvas, $temporary, $quality, 6),
                default => @imagejpeg($canvas, $temporary, $quality),
            };
            imagedestroy($canvas);
            imagedestroy($source);
        }
        if (!$ok || !is_file($temporary) || filesize($temporary) === 0) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0644);

        return @rename($temporary, $target);
    }
}
