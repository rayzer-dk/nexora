<?php
declare(strict_types=1);

use Twig\Environment;
use Twig\Error\Error;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$paths = array_slice($argv, 1);
if ($paths === []) {
    $paths = [$root . '/themes'];
}

$twig = new Environment(new Twig\Loader\ArrayLoader());
$twig->registerUndefinedFunctionCallback(static fn(string $name): TwigFunction => new TwigFunction($name, static fn(...$args) => null));
$twig->registerUndefinedFilterCallback(static fn(string $name): TwigFilter => new TwigFilter($name, static fn($value, ...$args) => $value));

$files = [];
foreach ($paths as $path) {
    $path = str_starts_with($path, '/') ? $path : $root . '/' . $path;
    if (is_file($path) && str_ends_with($path, '.twig')) {
        $files[] = $path;
        continue;
    }
    if (!is_dir($path)) {
        fwrite(STDERR, "Twig path not found: {$path}\n");
        exit(2);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = 0;
foreach ($files as $file) {
    $relative = ltrim(str_replace($root, '', $file), '/');
    try {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new RuntimeException('Unable to read template');
        }
        $twig->parse($twig->tokenize(new Source($code, $relative, $file)));
    } catch (Throwable $e) {
        ++$failed;
        $line = $e instanceof Error ? $e->getTemplateLine() : 0;
        fwrite(STDERR, sprintf("TWIG SYNTAX ERROR: %s%s: %s\n", $relative, $line > 0 ? ':' . $line : '', $e->getMessage()));
    }
}

if ($failed > 0) {
    fwrite(STDERR, "Twig syntax check FAILED: {$failed} template(s).\n");
    exit(1);
}

echo 'Twig syntax check PASSED: ' . count($files) . " template(s).\n";
