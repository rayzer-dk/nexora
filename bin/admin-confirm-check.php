#!/usr/bin/env php
<?php

declare(strict_types=1);

// Every admin form that performs a destructive or hard-to-reverse action must ask for confirmation
// (`data-confirm` on the form or on its submit button, handled by admin-runtime.js modal dialog).

$root = dirname(__DIR__);
$dangerous = '/(delete|remove|disable|uninstall|restore|rollback|revoke|purge|reset|isolate|archive|unpublish|ban|clear|truncate|wipe|deactivate|cancel_order|refund)/i';
$exempt = '/admin_account_security_cancel|admin_.*_saved_view_save/';
$errors = [];
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes/default/templates/admin', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    if (!preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $text, $forms, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($forms as $form) {
        if (!preg_match('#action="([^"]*)"#', $form[1], $action)) {
            continue;
        }
        $target = $action[1];
        if (preg_match('#path\(\s*\'([^\']+)\'#', $target, $route)) {
            $target = $route[1];
        }
        if (!preg_match($dangerous, $target) || preg_match($exempt, $target)) {
            continue;
        }
        $count++;
        if (!str_contains($form[0], 'data-confirm')) {
            $errors[] = str_replace($root . '/', '', $file->getPathname()) . ' → ' . $target;
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, "Admin confirm check FAILED: destructive forms without confirmation\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}
echo "Admin confirm check: OK ($count destructive forms guarded)\n";
