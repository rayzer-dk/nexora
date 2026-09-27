#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Commerce\Modules\Admin\Authorization\AdminPermissionSubscriber;
use Symfony\Component\HttpFoundation\Request;

$errors = [];
$routeNames = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $text = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/#\\[Route\\([^\\]]*?name\\s*:\\s*'([^']+)'/s", $text, $m)) {
        foreach ($m[1] as $name) $routeNames[$name] = true;
    }
}

$ref = new ReflectionClass(AdminPermissionSubscriber::class);
$subscriber = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('permissionForRoute');
$allowedUnmapped = ['admin_login','admin_logout','admin_extension_dynamic'];
foreach (array_keys($routeNames) as $route) {
    if (!str_starts_with($route, 'admin_') || in_array($route, $allowedUnmapped, true)) continue;
    foreach (['GET','POST'] as $verb) {
        $request = Request::create('/admin/access-audit', $verb);
        $permission = $method->invoke($subscriber, $route, $request);
        if ($permission === null) {
            $errors[] = $route . ' (' . $verb . ') has no explicit permission mapping';
            break;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Admin access check FAILED\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}
echo 'Admin access check: OK (' . count($routeNames) . " discovered routes)\n";
