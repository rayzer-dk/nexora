<?php

declare(strict_types=1);
namespace Commerce\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924234500 extends AbstractMigration
{
    public function getDescription(): string { return 'Granular administrator roles, permission matrix and safe access-control presets.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_admin_role (code VARCHAR(96) NOT NULL,name VARCHAR(190) NOT NULL,description VARCHAR(500) NULL,is_system TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach ([['ROLE_SUPER_ADMIN','Супер-адміністратор','Повний доступ до всієї системи.',1],['ROLE_MANAGER','Менеджер','Каталог, замовлення та щоденна робота магазину.',1],['ROLE_EDITOR','Контент-редактор','Каталог, контент і зовнішній вигляд без системних налаштувань.',1],['ROLE_SUPPORT','Підтримка','Перегляд і обробка замовлень та звернень.',1],['ROLE_VIEWER','Тільки перегляд','Безпечний read-only доступ до вибраних розділів.',1]] as $r) $this->addSql('INSERT INTO mc_admin_role(code,name,description,is_system,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$r[0],$r[1],$r[2],$r[3],$now,$now]);
        $permissions=[
            'catalog.delete'=>['Delete catalog entities','high'],'catalog.export'=>['Export catalog data','high'],'catalog.bulk'=>['Run bulk catalog operations','high'],'orders.export'=>['Export order data','high'],'customers.export'=>['Export customer data','critical'],
            'content.view'=>['View content','low'],'content.delete'=>['Delete content','high'],'appearance.view'=>['View appearance configuration','low'],'media.view'=>['View media library','low'],'media.manage'=>['Upload and edit media','normal'],'media.delete'=>['Delete media','high'],
            'forum.view'=>['View forum administration','low'],'search.view'=>['View search configuration','low'],'marketing.view'=>['View promotions and campaigns','low'],'marketing.manage'=>['Manage promotions and campaigns','high'],'feeds.view'=>['View commerce feeds','low'],'feeds.manage'=>['Generate and change commerce feeds','high'],
            'personal_data.view'=>['View customer personal data in orders and customer records','critical'],'notifications.view'=>['View notification delivery state','normal'],'notifications.manage'=>['Retry and manage notification delivery','high'],'system.cron.view'=>['View scheduler state','low'],'system.cron.manage'=>['Change scheduler execution state','critical']
        ];
        foreach($permissions as $code=>$meta){$this->addSql('INSERT INTO mc_admin_permission(code,description,risk_level) VALUES (?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description),risk_level=VALUES(risk_level)',[$code,$meta[0],$meta[1]]);$this->addSql('INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES (?,?)',['ROLE_SUPER_ADMIN',$code]);}
        foreach(['dashboard.view','catalog.view','orders.view','customers.view','content.view','appearance.view','media.view','forum.view','search.view','marketing.view','feeds.view','notifications.view','system.cron.view'] as $p) $this->addSql('INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES (?,?)',['ROLE_VIEWER',$p]);
        foreach(['catalog.delete','catalog.export','catalog.bulk','orders.export','customers.export','personal_data.view','content.view','content.delete','appearance.view','media.view','media.manage','media.delete','forum.view','search.view','marketing.view','marketing.manage','feeds.view','feeds.manage','notifications.view','notifications.manage','system.cron.view'] as $p) $this->addSql('INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES (?,?)',['ROLE_MANAGER',$p]);
        foreach(['content.view','appearance.view','media.view','media.manage','search.view'] as $p) $this->addSql('INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES (?,?)',['ROLE_EDITOR',$p]);
        foreach(['dashboard.view','orders.view','customers.view','personal_data.view','notifications.view'] as $p) $this->addSql('INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES (?,?)',['ROLE_SUPPORT',$p]);
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE IF EXISTS mc_admin_role'); }
}
