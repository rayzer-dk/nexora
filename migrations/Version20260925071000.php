<?php

declare(strict_types=1);
namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925071000 extends AbstractMigration
{
    public function getDescription(): string { return 'Localized navigation manager for storefront header/footer menus.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_navigation_item (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            menu_code VARCHAR(64) NOT NULL DEFAULT 'header',
            parent_id BIGINT UNSIGNED NULL,
            item_type VARCHAR(32) NOT NULL DEFAULT 'custom',
            target_ref VARCHAR(255) NULL,
            url VARCHAR(1000) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            open_new_tab TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_navigation_public_id (public_id),
            KEY idx_navigation_store_menu (store_id,menu_code,status,sort_order,id),
            KEY idx_navigation_parent (parent_id,sort_order,id),
            CONSTRAINT fk_navigation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_navigation_parent FOREIGN KEY (parent_id) REFERENCES mc_navigation_item(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_navigation_item_translation (
            navigation_item_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            label VARCHAR(190) NOT NULL,
            badge VARCHAR(64) NULL,
            PRIMARY KEY (navigation_item_id,locale),
            CONSTRAINT fk_navigation_translation_item FOREIGN KEY (navigation_item_id) REFERENCES mc_navigation_item(id) ON DELETE CASCADE,
            CONSTRAINT fk_navigation_translation_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE IF EXISTS mc_navigation_item_translation');$this->addSql('DROP TABLE IF EXISTS mc_navigation_item'); }
}
