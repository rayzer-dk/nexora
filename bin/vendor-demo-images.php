<?php

declare(strict_types=1);

/**
 * Maintainer tool: fetches the demo photos listed in resources/demo/dummyjson-catalog.json once, shrinks them to
 * at most 640 px and stores them as WebP in resources/demo/images/<source id>-<n>.webp. After that the demo installs
 * entirely from the package: the installer never needs the network. Run by .github/workflows/vendor-demo-images.yml.
 */

$root = dirname(__DIR__);
$catalog = json_decode((string) file_get_contents($root . '/resources/demo/dummyjson-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$target = $root . '/resources/demo/images';
if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
    fwrite(STDERR, "Cannot create $target\n");
    exit(1);
}
if (!function_exists('imagewebp')) {
    fwrite(STDERR, "GD without WebP support\n");
    exit(1);
}

$jobs = [];
foreach ($catalog['products'] as $product) {
    $id = (int) $product['source_id'];
    $urls = array_values(array_filter([(string) ($product['image_url'] ?? ''), ...array_slice((array) ($product['gallery_urls'] ?? []), 0, 2)]));
    foreach ($urls as $index => $url) {
        $jobs[$id . '-' . ($index + 1) . '.webp'] = $url;
    }
}

$stored = 0;
$failed = [];
foreach ($jobs as $name => $url) {
    $file = $target . '/' . $name;
    if (is_file($file) && filesize($file) > 0) {
        ++$stored;
        continue;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'cdn.dummyjson.com') {
        $failed[] = $name;
        continue;
    }
    $safe = $parts['scheme'] . '://' . $parts['host'] . implode('/', array_map('rawurlencode', array_map('rawurldecode', explode('/', $parts['path'] ?? ''))));
    $ch = curl_init($safe);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $image = is_string($data) && $status === 200 ? @imagecreatefromstring($data) : false;
    if ($image === false) {
        $failed[] = $name . ' (HTTP ' . $status . ')';
        continue;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1.0, 640 / max($width, $height));
    if ($scale < 1.0) {
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if ($resized !== false) {
            $image = $resized;
        }
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    if (!imagewebp($image, $file, 78) || filesize($file) < 200) {
        @unlink($file);
        $failed[] = $name . ' (encode)';
        continue;
    }
    ++$stored;
}

fwrite(STDOUT, sprintf("%d of %d photos stored in resources/demo/images\n", $stored, count($jobs)));
if ($failed !== []) {
    fwrite(STDERR, "Missing: " . implode(', ', $failed) . "\n");
    exit(2);
}
