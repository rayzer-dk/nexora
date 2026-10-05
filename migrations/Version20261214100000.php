<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261214100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Option values: price mode (+ - =), weight difference, start stock; options: how they are shown.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_product_option_value ADD price_mode VARCHAR(4) NOT NULL DEFAULT 'add', ADD weight_delta_g INT NOT NULL DEFAULT 0, ADD initial_quantity INT NULL");
        $this->addSql("UPDATE mc_product_option_value SET price_mode='sub', price_delta_minor=ABS(price_delta_minor) WHERE price_delta_minor<0");
        $this->addSql("ALTER TABLE mc_product_option ADD display VARCHAR(12) NOT NULL DEFAULT 'buttons'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mc_product_option_value SET price_delta_minor=-price_delta_minor WHERE price_mode='sub'");
        $this->addSql('ALTER TABLE mc_product_option_value DROP COLUMN price_mode, DROP COLUMN weight_delta_g, DROP COLUMN initial_quantity');
        $this->addSql('ALTER TABLE mc_product_option DROP COLUMN display');
    }
}
