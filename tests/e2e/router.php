<?php

declare(strict_types=1);

$publicDir = dirname(__DIR__, 2) . '/public';
$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uriPath = is_string($uriPath) && $uriPath !== '' ? rawurldecode($uriPath) : '/';

if ($uriPath !== '/' && !str_contains($uriPath, "\0")) {
    $candidate = realpath($publicDir . '/' . ltrim($uriPath, '/'));
    $publicReal = realpath($publicDir);

    if ($candidate !== false && $publicReal !== false && str_starts_with($candidate, $publicReal . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        return false;
    }
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir . '/index.php';
require $publicDir . '/index.php';
