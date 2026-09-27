<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

final class PlatformContractVersion
{
    public const CORE = '0.5.0';
    public const EXTENSION_API = '2.0';
    public const STORE_API = '1.0';
    public const WEBHOOK_API = '1.0';

    private function __construct()
    {
    }
}
