<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Doctrine\DBAL\Connection;
use GdImage;

/**
 * Named image sizes ("variants") of a stored master picture.
 *
 *  master:   media/<stem>.<ext>                     written once at upload (at most MASTER_WIDTH px wide)
 *  variant:  media/<stem>.<preset>-g<N>.<ext>       made from the master, kept on disk as a plain file
 *
 * A variant URL is built without touching the database. The first request for a file that does not exist yet reaches the
 * media controller, which makes the file under a lock and serves it; every later request is answered by the web server
 * from disk. The set of presets is closed (see MediaImageProfile::PRESET_WIDTHS), so no other size can be requested.
 */
final class MediaVariantService
{
    private const MASTER_PATTERN = '~^(?<stem>[A-Za-z0-9_/\-]+)\.(?<ext>webp|avif|jpe?g|png)$~';
    private const VARIANT_PATTERN = '~^(?<stem>[A-Za-z0-9_/\-]+)\.(?<preset>thumb|card|product|zoom)-g(?<gen>\d{1,4})\.(?<ext>webp|avif|jpe?g|png)$~';
    private const MAX_PIXELS = 48000000;

    /** @var array{format:string,quality:int,keep_source:bool,presets:array<string,int>,generation:int}|null */
    private ?array $profile = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigurationRevisionStore $revisions,
        private readonly string $projectDir,
    ) {
    }

    /** @return array{format:string,quality:int,keep_source:bool,presets:array<string,int>,generation:int} */
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

    /** URL of the named size of a stored picture; anything that is not a raster file under /media/ comes back unchanged. */
    public function url(string $url, string $preset): string
    {
        $profile = $this->profile();
        if (!isset($profile['presets'][$preset]) || !str_starts_with($url, '/media/')) {
            return $url;
        }
        $key = substr($url, 7);
        if (preg_match(self::MASTER_PATTERN, $key, $m) !== 1) {
            return $url;
        }

        return '/media/' . $m['stem'] . '.' . $preset . '-g' . $profile['generation'] . '.' . $m['ext'];
    }

    /**
     * srcset of several presets of one picture, e.g. "…thumb… 160w, …card… 480w".
     *
     * @param list<string> $presets
     */
    public function srcset(string $url, array $presets): string
    {
        $profile = $this->profile();
        $parts = [];
        foreach ($presets as $preset) {
            $variant = $this->url($url, $preset);
            if ($variant !== $url && isset($profile['presets'][$preset])) {
                $parts[] = $variant . ' ' . $profile['presets'][$preset] . 'w';
            }
        }

        return implode(', ', $parts);
    }

    /** @return array{stem:string,preset:string,gen:int,ext:string}|null */
    public function parse(string $key): ?array
    {
        if (preg_match(self::VARIANT_PATTERN, $key, $m) !== 1) {
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

        return '/media/' . $parsed['stem'] . '.' . $parsed['preset'] . '-g' . $this->profile()['generation'] . '.' . $parsed['ext'];
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
        $master = $this->path($parsed['stem'] . '.' . $parsed['ext']);
        if (!is_file($master)) {
            return null;
        }
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

            return $this->make($master, $target, $parsed['ext'], $width, $profile['quality']) ? $target : null;
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
        foreach ($presets ?? MediaImageProfile::EAGER_PRESETS as $preset) {
            $url = $this->url('/media/' . ltrim($storageKey, '/'), $preset);
            if ($url === '/media/' . ltrim($storageKey, '/')) {
                continue;
            }
            $key = substr($url, 7);
            $existed = is_file($this->path($key));
            if ($this->ensure($key) !== null && !$existed) {
                ++$made;
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
     * Removes variant files that no page references any more: older generations, and variants whose master is gone.
     * Never touches a master, a kept source or any other file. A newly made file always gets a grace period.
     *
     * @return array{files:int,bytes:int}
     */
    public function collectStale(bool $dryRun = true, int $graceDays = 7, bool $everything = false, ?int $deadline = null): array
    {
        $root = rtrim($this->projectDir, '/\\') . '/public/media';
        $result = ['files' => 0, 'bytes' => 0];
        if (!is_dir($root)) {
            return $result;
        }
        $current = $this->profile()['generation'];
        $limitTime = time() - max(0, $graceDays) * 86400;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($deadline !== null && time() >= $deadline) {
                break;
            }
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $parsed = $this->parse($relative);
            if ($parsed === null) {
                continue; // masters, sources, demo files and everything else stay untouched
            }
            $masterExists = is_file($root . '/' . $parsed['stem'] . '.' . $parsed['ext']);
            $stale = $parsed['gen'] !== $current || !$masterExists || $everything;
            if (!$stale || (!$everything && $file->getMTime() > $limitTime)) {
                continue;
            }
            $result['files']++;
            $result['bytes'] += (int) $file->getSize();
            if (!$dryRun) {
                @unlink($file->getPathname());
            }
        }

        return $result;
    }

    public function path(string $key): string
    {
        return rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
    }

    private function make(string $master, string $target, string $ext, int $width, int $quality): bool
    {
        $info = @getimagesize($master);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return false;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($info[0] <= $width) {
            // The master is already that small: the variant is a plain copy, so the URL stays a static file.
            $ok = @copy($master, $temporary);
        } else {
            $raw = @file_get_contents($master);
            $source = is_string($raw) ? @imagecreatefromstring($raw) : false;
            if (!$source instanceof GdImage) {
                return false;
            }
            $height = max(1, (int) round($info[1] * ($width / $info[0])));
            $canvas = imagecreatetruecolor($width, $height);
            if (!$canvas instanceof GdImage) {
                imagedestroy($source);

                return false;
            }
            if ($ext === 'jpg' || $ext === 'jpeg') {
                imagefilledrectangle($canvas, 0, 0, $width, $height, (int) imagecolorallocate($canvas, 255, 255, 255));
            } else {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $width, $height, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
            }
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            $ok = match ($ext) {
                'webp' => function_exists('imagewebp') && @imagewebp($canvas, $temporary, $quality),
                'avif' => function_exists('imageavif') && @imageavif($canvas, $temporary, $quality),
                'png' => @imagepng($canvas, $temporary, 6),
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
