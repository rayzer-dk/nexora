<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924235500 extends AbstractMigration
{
    public function getDescription(): string { return 'Administrator activity-log permission for the existing audit trail.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mc_admin_permission(code,description,risk_level) VALUES ('system.audit.view','View administrator activity audit trail','high') ON DUPLICATE KEY UPDATE description=VALUES(description),risk_level=VALUES(risk_level)");
        $this->addSql("INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES ('ROLE_SUPER_ADMIN','system.audit.view')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_admin_role_permission WHERE permission_code='system.audit.view'");
        $this->addSql("DELETE FROM mc_admin_permission WHERE code='system.audit.view'");
    }
}
