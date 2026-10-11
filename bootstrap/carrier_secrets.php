<?php

declare(strict_types=1);

/**
 * Carrier keys typed in the admin (Shipments → Carriers) live in var/config/carrier-secrets.json. They are put into the
 * process environment before the kernel boots, so they win over the same names in the .env file. Only known names are taken.
 */
(static function (): void {
    $file = dirname(__DIR__) . '/var/config/carrier-secrets.json';
    if (!is_file($file)) {
        return;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) {
        return;
    }
    foreach ($data as $name => $value) {
        if (!is_string($name) || !is_string($value) || $value === '' || preg_match('/^(NOVA_POST|UKRPOSHTA|DELIVERY_AUTO|DHL|GLS|MEEST)_[A-Z0-9_]{1,60}$/', $name) !== 1) {
            continue;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
})();
