<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer messengers: a shopper links Telegram or Viber to an order (or to the account) and gets the order updates there.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_messenger_link (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            channel VARCHAR(16) NOT NULL,
            token CHAR(24) NOT NULL,
            chat_id VARCHAR(190) NULL,
            display_name VARCHAR(190) NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            created_at DATETIME(6) NOT NULL,
            linked_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_messenger_token (token),
            KEY idx_messenger_order (order_id, channel, status),
            KEY idx_messenger_customer (customer_id, channel, status),
            KEY idx_messenger_chat (channel, chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_messenger_link');
    }
}
