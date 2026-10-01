<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Support;

use Commerce\Core\Configuration\SystemSettingStore;
use Doctrine\DBAL\DriverManager;

/** In-memory SystemSettingStore for unit tests (the real one speaks MariaDB SQL). */
final class ArraySystemSettingStore extends SystemSettingStore
{
    /** @var array<string,string> */
    public array $data = [];

    public function __construct()
    {
        parent::__construct(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    }

    public function getString(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function setString(string $key, string $value): bool
    {
        $this->data[$key] = $value;

        return true;
    }
}
