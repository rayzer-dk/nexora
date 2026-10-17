<?php

declare(strict_types=1);

namespace Commerce\DevelopmentTools;

// phpcs:disable PSR1.Files.SideEffects -- Standalone CLI with a guarded entry point for reusable tests.

/** Read-only source validation; this tool does not normalize or rewrite text. */
final class SourceEncodingCheck
{
    private const array EXCLUDED_DIRECTORIES = [
        'vendor', 'node_modules', '.git', 'var', '.autopilot', '.claude', '.codex',
    ];

    private const array TEXT_EXTENSIONS = [
        'php', 'twig', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'json', 'jsonl',
        'xml', 'yaml', 'yml', 'neon', 'css', 'scss', 'sass', 'less', 'html', 'htm',
        'svg', 'sql', 'md', 'markdown', 'txt', 'rst', 'adoc', 'sh', 'bash', 'bat',
        'cmd', 'ps1', 'ini', 'toml', 'conf', 'lock', 'csv', 'tsv',
    ];

    public static function contentError(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return 'UTF-8 BOM is not allowed';
        }
        if (
            str_starts_with($content, "\xFF\xFE")
            || str_starts_with($content, "\xFE\xFF")
            || str_starts_with($content, "\x00\x00\xFE\xFF")
        ) {
            return 'UTF-16/UTF-32 BOM is not allowed';
        }

        return preg_match('//u', $content) === 1 ? null : 'Invalid UTF-8';
    }

    /**
     * Explicit paths permit diagnostics inside directories excluded from the default scan.
     * File contents are never included in diagnostics.
     *
     * @param list<string> $paths Paths relative to the project root, or absolute paths.
     * @return array{checked: int, errors: list<string>}
     */
    public static function check(string $projectRoot, array $paths = []): array
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            return ['checked' => 0, 'errors' => ['Project root: Directory does not exist']];
        }

        $files = [];
        $errors = [];
        $defaultScan = $paths === [];
        foreach ($defaultScan ? ['.'] : $paths as $selected) {
            $absolute = self::isAbsolute($selected) ? $selected : $root . '/' . $selected;
            $resolved = realpath($absolute);
            if ($resolved === false) {
                $errors[] = self::displayPath($absolute, $root) . ': Path does not exist';
                continue;
            }
            if (is_file($resolved)) {
                $files[$resolved] = true;
                continue;
            }
            if (!is_dir($resolved)) {
                $errors[] = self::displayPath($resolved, $root) . ': Not a regular file or directory';
                continue;
            }

            try {
                $filter = new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $entry) use ($root, $defaultScan): bool {
                        if ($entry->isLink()) {
                            return false;
                        }
                        if ($entry->isDir()) {
                            return !$defaultScan || !self::excludedDirectory($entry->getPathname(), $root);
                        }

                        return $entry->isFile() && self::isSourceFile($entry->getFilename());
                    },
                );
                foreach (new \RecursiveIteratorIterator($filter) as $entry) {
                    $files[$entry->getPathname()] = true;
                }
            } catch (\UnexpectedValueException) {
                $errors[] = self::displayPath($resolved, $root) . ': Cannot read directory';
            }
        }

        ksort($files);
        foreach (array_keys($files) as $file) {
            $content = @file_get_contents($file);
            $error = $content === false ? 'Cannot read file' : self::contentError($content);
            if ($error !== null) {
                $errors[] = self::displayPath($file, $root) . ': ' . $error;
            }
        }
        sort($errors);

        return ['checked' => count($files), 'errors' => $errors];
    }

    private static function isSourceFile(string $name): bool
    {
        if (
            in_array($name, ['console', 'Dockerfile', 'Makefile', 'LICENSE',
                '.editorconfig', '.gitignore', '.gitattributes', '.htaccess'], true)
            || str_starts_with($name, '.env')
        ) {
            return true;
        }

        $name = preg_replace('/\.(dist|example|sample)$/i', '', $name) ?? $name;

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true);
    }

    private static function excludedDirectory(string $path, string $root): bool
    {
        return in_array(basename($path), self::EXCLUDED_DIRECTORIES, true)
            || in_array(self::displayPath($path, $root), ['public/build', 'public/media'], true);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function displayPath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        echo "Usage: php bin/source-encoding-check.php [file-or-directory ...]\n";
        echo "Checks source text for UTF-8 without BOM; never modifies files.\n";
        echo "Default scan excludes dependencies, runtime data, local agent data and generated media/build.\n";
        echo "Explicit paths override those directory exclusions for focused diagnostics.\n";
        exit(0);
    }

    $result = SourceEncodingCheck::check(dirname(__DIR__), $arguments);
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    if ($result['errors'] !== []) {
        fwrite(STDERR, 'Source encoding: FAILED (' . count($result['errors']) . ' errors).' . PHP_EOL);
        exit(1);
    }
    echo 'Source encoding: OK (' . $result['checked'] . ' files, UTF-8 without BOM).' . PHP_EOL;
}
