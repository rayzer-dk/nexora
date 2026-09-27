<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent webhook replay protection for idempotent payment and integration callbacks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_webhook_replay (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(32) NOT NULL,
            fingerprint BINARY(32) NOT NULL,
            received_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_webhook_replay (provider, fingerprint),
            KEY idx_webhook_replay_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_webhook_replay');
    }
}
