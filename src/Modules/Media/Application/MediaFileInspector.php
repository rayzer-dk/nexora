<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

/**
 * What exists on disk for one library picture: the original and every cache file made from it, plus the sizes that will be
 * made on the first request. Read-only, except forgetSizes() which removes cache files (they are made again on demand).
 */
final class MediaFileInspector
{
    public function __construct(private readonly MediaVariantService $variants, private readonly string $projectDir)
    {
    }

    /**
     * @return array{files:list<array{name:string,role:string,preset:string,format:string,bytes:int,width:int,height:int,url:string,current:bool}>,pending:list<array{preset:string,width:int}>,bytes:int}
     */
    public function inspect(string $storageKey): array
    {
        $key = ltrim(str_replace('\\', '/', $storageKey), '/');
        $result = ['files' => [], 'pending' => [], 'bytes' => 0];
        $stem = $this->variants->stemOf($key);
        if ($stem === '' || str_contains($stem, '..') || str_starts_with($key, 'cache/')) {
            return $result;
        }
        $profile = $this->variants->profile();
        $madeCurrent = [];
        $rows = [['relative' => $key, 'role' => 'original', 'preset' => '', 'current' => true]];
        foreach ($this->variants->cacheFiles($stem) as $relative) {
            $parsed = $this->variants->parse($relative);
            if ($parsed === null) {
                continue;
            }
            $current = $parsed['gen'] === $profile['generation'];
            if ($current) {
                $madeCurrent[$parsed['preset'] . '.' . $parsed['ext']] = true;
            }
            $rows[] = ['relative' => $relative, 'role' => 'size', 'preset' => $parsed['preset'], 'current' => $current];
        }
        foreach ($rows as $row) {
            $path = $this->root() . '/' . $row['relative'];
            if (!is_file($path)) {
                continue;
            }
            $format = strtolower(pathinfo($row['relative'], PATHINFO_EXTENSION));
            $info = @getimagesize($path);
            $bytes = (int) @filesize($path);
            $result['bytes'] += $bytes;
            $result['files'][] = [
                'name' => $row['role'] === 'original' ? basename($row['relative']) : basename(dirname($row['relative'])) . '/' . basename($row['relative']),
                'role' => $row['role'], 'preset' => $row['preset'], 'format' => strtoupper($format === 'jpg' ? 'jpeg' : $format), 'bytes' => $bytes,
                'width' => is_array($info) ? (int) $info[0] : 0, 'height' => is_array($info) ? (int) $info[1] : 0,
                'url' => '/media/' . $row['relative'], 'current' => $row['current'],
            ];
        }
        $order = ['original' => 0, 'size' => 1];
        $presets = array_keys(MediaImageProfile::PRESET_WIDTHS);
        usort($result['files'], static fn (array $a, array $b): int => [$order[$a['role']], array_search($a['preset'], $presets, true), $a['name']] <=> [$order[$b['role']], array_search($b['preset'], $presets, true), $b['name']]);
        if ($this->variants->url('/media/' . $key, 'thumb') !== '/media/' . $key) {
            $formats = $this->variants->avifEnabled() ? ['webp', 'avif'] : [$this->variants->mainExtension()];
            foreach ($profile['presets'] as $preset => $width) {
                foreach ($formats as $ext) {
                    if (!isset($madeCurrent[$preset . '.' . $ext])) {
                        $result['pending'][] = ['preset' => $preset, 'width' => $width];
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /** Removes every cache file of one picture (all generations and formats). The original stays. @return int files removed */
    public function forgetSizes(string $storageKey): int
    {
        return $this->variants->forget($storageKey);
    }

    private function root(): string
    {
        return rtrim($this->projectDir, '/\\') . '/public/media';
    }
}
