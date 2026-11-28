<?php

declare(strict_types=1);

$publicDir = dirname(__DIR__, 2) . '/public';
$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uriPath = is_string($uriPath) && $uriPath !== '' ? rawurldecode($uriPath) : '/';

if ($uriPath !== '/' && !str_contains($uriPath, "\0")) {
    $candidate = realpath($publicDir . '/' . ltrim($uriPath, '/'));
    $publicReal = realpath($publicDir);

    if ($candidate !== false && $publicReal !== false && str_starts_with($candidate, $publicReal . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        if (getenv('E2E_STATIC_CACHE') === '1' && preg_match('~^/(?:build/assets/|assets/|media/cache/(?:thumb|card|product|zoom)-g[0-9]+/).+\\.(?:js|mjs|css|json|svg|woff2|png|webp|avif|jpe?g)$~D', $uriPath) === 1) {
            $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $types = ['js'=>'application/javascript','mjs'=>'application/javascript','css'=>'text/css','json'=>'application/json','svg'=>'image/svg+xml','woff2'=>'font/woff2','png'=>'image/png','webp'=>'image/webp','avif'=>'image/avif','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
            $immutable = str_starts_with($uriPath, '/build/assets/') || str_starts_with($uriPath, '/media/cache/');
            header('Cache-Control: ' . ($immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=3600, must-revalidate'));
            header('Content-Type: ' . $types[$extension]);
            $encoding = '';
            if (in_array($extension, ['js','mjs','css','json','svg'], true)) {
                header('Vary: Accept-Encoding');
                foreach (['br','gzip'] as $algorithm) {
                    $accepted = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
                    if (preg_match('~(?:^|,)\\s*' . $algorithm . '(?:\\s*;\\s*q=(?!0(?:\\.0*)?(?:,|$))[^,]+)?\\s*(?:,|$)~i', $accepted) && is_file($candidate . ($algorithm === 'gzip' ? '.gz' : '.br'))) {
                        $candidate .= $algorithm === 'gzip' ? '.gz' : '.br';
                        $encoding = $algorithm;
                        break;
                    }
                }
            }
            if ($encoding !== '') header('Content-Encoding: ' . $encoding);
            $etag = '"' . hash_file('sha256', $candidate) . '"';
            header('ETag: ' . $etag);
            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                return true;
            }
            header('Content-Length: ' . filesize($candidate));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($candidate);
            return true;
        }
        return false;
    }
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir . '/index.php';
require $publicDir . '/index.php';
