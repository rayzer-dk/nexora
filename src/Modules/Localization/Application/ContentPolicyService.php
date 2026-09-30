<?php

declare(strict_types=1);

namespace Commerce\Modules\Localization\Application;

use Doctrine\DBAL\Connection;

/**
 * Whether an item may be published while a store language has no text for it:
 * "off" (default) publishes anyway, "warn" publishes and tells the editor, "block" refuses until every language is filled.
 */
final readonly class ContentPolicyService
{
    public const MODES = ['off', 'warn', 'block'];

    public function __construct(private Connection $db)
    {
    }

    public function mode(int $storeId): string
    {
        try {
            $mode = (string) $this->db->fetchOne('SELECT require_translations FROM mc_store_content_policy WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            return 'off';
        }

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public function save(int $storeId, string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode_invalid');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_store_content_policy (store_id, require_translations, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE require_translations=VALUES(require_translations), updated_at=VALUES(updated_at)',
            [$storeId, $mode, $now],
        );
    }
}
