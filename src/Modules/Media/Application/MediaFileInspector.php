<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

/**
 * What exists on disk for one library picture: the untouched source, the master, every made size and the sizes that will be
 * made on the first request. Read-only, except forgetSizes() which removes made sizes (they are made again on demand).
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
        $stem = $this->stemOf($key);
        if ($stem === '' || str_contains($stem, '..')) {
            return $result;
        }
        $dir = dirname($this->root() . '/' . $stem);
        $prefix = basename($stem) . '.';
        $profile = $this->variants->profile();
        $madeCurrent = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (!str_starts_with($name, $prefix) || !is_file($dir . '/' . $name) || str_ends_with($name, '.tmp')) {
                continue;
            }
            $rest = substr($name, strlen($prefix));
            $role = 'master';
            $preset = '';
            $current = true;
            if (preg_match('~^source\.([a-z0-9]+)$~', $rest, $m) === 1) {
                $role = 'source';
                $format = $m[1];
            } elseif (preg_match('~^(thumb|card|product|zoom)-g(\d{1,4})\.([a-z0-9]+)$~', $rest, $m) === 1) {
                $role = 'size';
                $preset = $m[1];
                $format = $m[3];
                $current = (int) $m[2] === $profile['generation'];
                if ($current) {
                    $madeCurrent[$preset] = true;
                }
            } elseif (preg_match('~^([a-z0-9]+)$~', $rest, $m) === 1) {
                $format = $m[1];
            } else {
                continue;
            }
            $path = $dir . '/' . $name;
            $info = in_array($format, ['webp', 'avif', 'jpg', 'jpeg', 'png'], true) ? @getimagesize($path) : false;
            $bytes = (int) @filesize($path);
            $result['bytes'] += $bytes;
            $result['files'][] = [
                'name' => $name, 'role' => $role, 'preset' => $preset, 'format' => strtoupper($format === 'jpg' ? 'jpeg' : $format), 'bytes' => $bytes,
                'width' => is_array($info) ? (int) $info[0] : 0, 'height' => is_array($info) ? (int) $info[1] : 0,
                'url' => '/media/' . dirname($key) . '/' . $name, 'current' => $current,
            ];
        }
        $order = ['source' => 0, 'master' => 1, 'size' => 2];
        $presets = array_keys(MediaImageProfile::PRESET_WIDTHS);
        usort($result['files'], static fn (array $a, array $b): int => [$order[$a['role']], array_search($a['preset'], $presets, true), $a['name']] <=> [$order[$b['role']], array_search($b['preset'], $presets, true), $b['name']]);
        if ($this->variants->url('/media/' . $key, 'thumb') !== '/media/' . $key) {
            foreach ($profile['presets'] as $preset => $width) {
                if (!isset($madeCurrent[$preset])) {
                    $result['pending'][] = ['preset' => $preset, 'width' => $width];
                }
            }
        }

        return $result;
    }

    /** Removes every made size of one picture (all generations). The master and the source stay. @return int files removed */
    public function forgetSizes(string $storageKey): int
    {
        $removed = 0;
        foreach ($this->inspect($storageKey)['files'] as $file) {
            if ($file['role'] === 'size') {
                $path = $this->root() . '/' . dirname(ltrim($storageKey, '/')) . '/' . $file['name'];
                if (is_file($path) && @unlink($path)) {
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    private function stemOf(string $key): string
    {
        $base = basename($key);
        $dot = strpos($base, '.');

        return $dot === false ? $key : substr($key, 0, strlen($key) - strlen($base)) . substr($base, 0, $dot);
    }

    private function root(): string
    {
        return rtrim($this->projectDir, '/\\') . '/public/media';
    }
}
