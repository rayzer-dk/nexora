<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum/community tables were introduced after the global collation normalization migration.
 * On MySQL 8.4 and MariaDB they therefore inherited the server default again.
 *
 * Keep this migration separate from historical migrations: released migrations must remain immutable.
 */
final class Version20260927170000 extends AbstractMigration
{
    private const TARGET = 'utf8mb4_unicode_ci';

    /** @var list<string> */
    private const TABLES = [
        'mc_customer_verification_code',
        'mc_forum_subscription',
        'mc_forum_reaction',
        'mc_forum_report',
        'mc_forum_topic_read',
        'mc_forum_post_revision',
        'mc_forum_profile',
        'mc_forum_block',
        'mc_forum_dm_thread',
        'mc_forum_dm_message',
        'mc_forum_dm_report',
        'mc_forum_ban',
    ];

    public function getDescription(): string
    {
        return 'Normalize forum/community table collations introduced after the global collation migration.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
                [$table],
            );
            if ($exists !== 1) {
                continue;
            }

            $collation = (string) $this->connection->fetchOne(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
                [$table],
            );
            if ($collation === self::TARGET) {
                continue;
            }

            $this->addSql(sprintf(
                'ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
                $this->quote($table),
                self::TARGET,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // One-way hardening. Restoring mixed server-default collations would reintroduce SQLSTATE 1267 risk.
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function quote(string $identifier): string
    {
        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $identifier) . chr(96);
    }
}
