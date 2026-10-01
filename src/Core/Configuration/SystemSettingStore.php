<?php

declare(strict_types=1);

namespace Commerce\Core\Configuration;

use Doctrine\DBAL\Connection;

/**
 * Tiny global key/value store (mc_system_setting) for platform-level state that is not per-store:
 * cron settings and the last cron tick, cached provider model lists. Every method is failure tolerant:
 * a missing table (before the migration ran) simply behaves as "no value".
 */
class SystemSettingStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function getArray(string $key): ?array
    {
        $raw = $this->getString($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function getString(string $key): ?string
    {
        try {
            $value = $this->db->fetchOne('SELECT setting_value FROM mc_system_setting WHERE setting_key=?', [$key]);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    public function setArray(string $key, array $value): bool
    {
        return $this->setString($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function setString(string $key, string $value): bool
    {
        try {
            $this->db->executeStatement(
                'INSERT INTO mc_system_setting (setting_key,setting_value,updated_at) VALUES (?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)',
                [mb_substr($key, 0, 120), $value],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
