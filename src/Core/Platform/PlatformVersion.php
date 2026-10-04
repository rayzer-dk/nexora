<?php

declare(strict_types=1);

namespace Commerce\Core\Platform;

final class PlatformVersion
{
    public const VERSION = '3.45.0';
    public const CHANNEL = 'production';
    public const EXTENSION_API = '2.0';
    public const DATABASE_SCHEMA = 78;
    public const MIN_PHP = '8.4.0';
    public const MAX_PHP_EXCLUSIVE = '9.0.0';
    public const MIN_MYSQL = '8.4.0';
    public const MIN_MARIADB = '10.11.0';

    private function __construct()
    {
    }
}
