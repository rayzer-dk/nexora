#!/usr/bin/env php
<?php

declare(strict_types=1);

// CLI upgrade after unpacking a new release over an existing installation:  php bin/upgrade.php
$root = dirname(__DIR__);
require_once $root . '/bootstrap/upgrade.php';

$result = nexora_upgrade_run($root, static function (string $step): void {
    fwrite(STDOUT, $step . PHP_EOL);
});
if ($result['output'] !== '') {
    fwrite(STDOUT, $result['output'] . PHP_EOL);
}
if (!$result['ok']) {
    fwrite(STDERR, $result['error'] . PHP_EOL . 'Details: var/log/upgrade.log' . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'OK ' . $result['version'] . ' schema ' . $result['schema'] . ' removed ' . $result['removed'] . PHP_EOL);
