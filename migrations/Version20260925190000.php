<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add read-only Developer Tools permission.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO mc_admin_permission(code,description,risk_level) VALUES ('developer_tools.view','View developer diagnostics','medium')");
        $this->addSql("INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES ('ROLE_MANAGER','developer_tools.view')");
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_admin_role_permission WHERE permission_code='developer_tools.view'");
        $this->addSql("DELETE FROM mc_admin_permission WHERE code='developer_tools.view'");
    }
}
