<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Infrastructure;

use RuntimeException;
use ZipArchive;

final class SafeTransferPackageExtractor
{
    public function __construct(
        private readonly int $maxEntries = 250000,
        private readonly int $maxUncompressedBytes = 10_737_418_240,
    ) {
    }

    public function extract(string $archivePath, string $targetDirectory): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.18eeb50532c9'));
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19303430acdd'));
        }
        if ($zip->numFiles > $this->maxEntries) {
            $zip->close();
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1f4c45a0fec3'));
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                $zip->close();
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b8bc7c6645b6'));
            }
            $name = (string) ($stat['name'] ?? '');
            if (!$this->safeName($name)) {
                $zip->close();
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.504d5b5321bb'));
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > $this->maxUncompressedBytes) {
                $zip->close();
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.de92975797a8'));
            }
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) {
            $zip->close();
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a6556de9350'));
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $destination = $targetDirectory . DIRECTORY_SEPARATOR . $name;
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1ec5184c2642'));
                }
                $input = $zip->getStream($name);
                $output = fopen($destination, 'wb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9f1fab3b9d51'));
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                @chmod($destination, 0600);
            }
        } finally {
            $zip->close();
        }
    }

    private function safeName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 1024
            && !str_starts_with($name, '/')
            && !str_starts_with($name, '\\')
            && !str_contains($name, '..')
            && !str_contains($name, "\0")
            && !preg_match('/^[A-Za-z]:/', $name);
    }
}
