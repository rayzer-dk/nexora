<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Makes image sizes with the libvips command line tool ("vips") when the server has it; GD stays the fallback.
 *
 * libvips reads only the part of the picture it needs, so a size is made with less memory and time than with GD, and it
 * writes AVIF even where the PHP build has no imageavif(). The tool is looked up once per hour (var/media-engine.json),
 * so a page request never starts "vips --version". MEDIA_IMAGE_ENGINE=gd switches libvips off; MEDIA_VIPS_BINARY is the
 * path of the tool when it is not on PATH. Any failure of the tool returns false and the caller uses GD.
 */
final class VipsImageEncoder
{
    private const PROBE_TTL = 3600;
    private const TIMEOUT = 60;

    /** @var array{available:bool,avif:bool,webp:bool,jpeg:bool,version:string}|null */
    private ?array $probe = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(default::MEDIA_IMAGE_ENGINE)%')] private readonly ?string $engine = null,
        #[Autowire('%env(default::MEDIA_VIPS_BINARY)%')] private readonly ?string $binary = null,
    ) {
    }

    public function available(): bool
    {
        return $this->probe()['available'];
    }

    /** True when libvips can write the format: avif, webp or jpg. */
    public function supports(string $ext): bool
    {
        $probe = $this->probe();

        return $probe['available'] && match ($ext) {
            'avif' => $probe['avif'],
            'webp' => $probe['webp'],
            'jpg' => $probe['jpeg'],
            default => false,
        };
    }

    /** Version line of the tool, or '' when libvips is not used. */
    public function version(): string
    {
        return $this->probe()['version'];
    }

    /**
     * Writes $target: $source scaled down to $width (never enlarged), metadata stripped. A JPEG target gets a white
     * background under transparent pixels.
     */
    public function thumbnail(string $source, string $target, int $width, string $ext, int $quality): bool
    {
        if (!$this->supports($ext) || $width < 1) {
            return false;
        }
        $options = match ($ext) {
            'avif', 'webp' => 'Q=' . $quality . ',effort=4,strip',
            default => 'Q=' . $quality . ',strip,background=255',
        };
        // vips picks the saver from the file extension of the output.
        $process = new Process([$this->tool(), 'thumbnail', $source, $target . '[' . $options . ']', (string) $width, '--size', 'down']);
        $process->setTimeout(self::TIMEOUT);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($target) && filesize($target) > 0;
    }

    /** @return array{available:bool,avif:bool,webp:bool,jpeg:bool,version:string} */
    private function probe(): array
    {
        if ($this->probe !== null) {
            return $this->probe;
        }
        $off = ['available' => false, 'avif' => false, 'webp' => false, 'jpeg' => false, 'version' => ''];
        if (strtolower(trim((string) $this->engine)) === 'gd' || !function_exists('proc_open')) {
            return $this->probe = $off;
        }
        $cache = rtrim($this->projectDir, '/\\') . '/var/media-engine.json';
        $cached = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        if (is_array($cached) && ($cached['tool'] ?? null) === $this->tool() && (int) ($cached['checked_at'] ?? 0) > time() - self::PROBE_TTL) {
            return $this->probe = [
                'available' => (bool) ($cached['available'] ?? false),
                'avif' => (bool) ($cached['avif'] ?? false),
                'webp' => (bool) ($cached['webp'] ?? false),
                'jpeg' => (bool) ($cached['jpeg'] ?? false),
                'version' => (string) ($cached['version'] ?? ''),
            ];
        }
        $probe = $off;
        $version = $this->run(['--version']);
        if ($version !== null && str_starts_with($version, 'vips')) {
            $savers = (string) $this->run(['-l', 'foreign']);
            $probe = [
                'available' => true,
                'avif' => str_contains($savers, 'heifsave'),
                'webp' => str_contains($savers, 'webpsave'),
                'jpeg' => str_contains($savers, 'jpegsave'),
                'version' => trim(strtok($version, "\n") ?: ''),
            ];
        }
        $dir = dirname($cache);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($cache, (string) json_encode($probe + ['tool' => $this->tool(), 'checked_at' => time()]), LOCK_EX);
        }

        return $this->probe = $probe;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): ?string
    {
        $process = new Process([$this->tool(), ...$arguments]);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function tool(): string
    {
        $binary = trim((string) $this->binary);

        return $binary !== '' ? $binary : 'vips';
    }
}
