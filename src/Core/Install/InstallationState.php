<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class InstallationState
{
    public function __construct(private Connection $connection)
    {
    }

    public function isInstalled(): bool
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_installation WHERE id = 1') === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
