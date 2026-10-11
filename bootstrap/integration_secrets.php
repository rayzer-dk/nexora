<?php

declare(strict_types=1);

/**
 * Settings of outside services typed in the admin (Integrations → Connections) live in var/config/integration-secrets.json
 * (and the older var/config/carrier-secrets.json). They are put into the process environment before the kernel boots, so they
 * win over the same names in the .env file. Only names of known services are taken.
 */
(static function (): void {
    $dir = dirname(__DIR__) . '/var/config';
    foreach (['carrier-secrets.json', 'integration-secrets.json'] as $name) {
        $file = $dir . '/' . $name;
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value) || $value === '' || preg_match('/^(NOVA_POST|UKRPOSHTA|DELIVERY_AUTO|DHL|GLS|MEEST|MONOBANK|MONO_PARTS|PRIVAT_PARTS|LIQPAY|WAYFORPAY|STRIPE|PAYPAL|KEYCRM|SALESDRIVE|BITRIX24|PROM|ROZETKA|EPICENTR|CUSTOMER_TELEGRAM|CUSTOMER_VIBER)_[A-Z0-9_]{1,60}$/', $key) !== 1) {
                continue;
            }
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
})();
