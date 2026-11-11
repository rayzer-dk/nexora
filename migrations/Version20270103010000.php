<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270103010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Core Web Vitals measured in visitors\' browsers (LCP, CLS, INP): anonymous samples, one row per metric.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_web_vital (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            page_kind VARCHAR(16) NOT NULL,
            device VARCHAR(8) NOT NULL,
            metric VARCHAR(4) NOT NULL,
            value DECIMAL(10,3) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_web_vital_report (store_id, metric, page_kind, device, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_web_vital');
    }
}
