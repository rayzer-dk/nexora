<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transactional domain event delivery, notification idempotency and contextual admin RBAC foundation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_outbox_event
            ADD COLUMN event_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER event_type,
            ADD COLUMN metadata JSON NULL AFTER payload,
            ADD COLUMN locked_at DATETIME(6) NULL AFTER attempts,
            ADD COLUMN locked_by VARCHAR(64) NULL AFTER locked_at,
            ADD COLUMN last_error VARCHAR(1000) NULL AFTER locked_by,
            ADD COLUMN occurred_at DATETIME(6) NULL AFTER last_error,
            ADD COLUMN completed_at DATETIME(6) NULL AFTER processed_at");
        $this->addSql("UPDATE mc_outbox_event SET metadata=JSON_OBJECT(), occurred_at=COALESCE(occurred_at, created_at) WHERE metadata IS NULL OR occurred_at IS NULL");
        $this->addSql('ALTER TABLE mc_outbox_event MODIFY metadata JSON NOT NULL, MODIFY occurred_at DATETIME(6) NOT NULL');
        $this->addSql('CREATE INDEX idx_outbox_event_stale_lock ON mc_outbox_event (status, locked_at, id)');

        $this->addSql("CREATE TABLE mc_domain_event_delivery (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_outbox_id BIGINT UNSIGNED NOT NULL,
            subscriber_id VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME(6) NULL,
            last_error VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            delivered_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_event_delivery_subscriber (event_outbox_id, subscriber_id),
            KEY idx_event_delivery_retry (status, available_at, id),
            KEY idx_event_delivery_subscriber_status (subscriber_id, status, id),
            CONSTRAINT fk_event_delivery_outbox FOREIGN KEY (event_outbox_id) REFERENCES mc_outbox_event(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql('ALTER TABLE mc_notification_outbox ADD COLUMN dedupe_key VARCHAR(190) NULL AFTER public_id');
        $this->addSql('CREATE UNIQUE INDEX uq_notification_dedupe_key ON mc_notification_outbox (dedupe_key)');

        $this->addSql("CREATE TABLE mc_extension_setting (
            installation_id BIGINT UNSIGNED NOT NULL,
            setting_key VARCHAR(96) NOT NULL,
            value_payload LONGTEXT NOT NULL,
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (installation_id, setting_key),
            CONSTRAINT fk_extension_setting_installation FOREIGN KEY (installation_id) REFERENCES mc_extension_installation(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_extension_setting_revision (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            installation_id BIGINT UNSIGNED NOT NULL,
            settings_json JSON NOT NULL,
            actor VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_extension_setting_revision_installation (installation_id, id),
            CONSTRAINT fk_extension_setting_revision_installation FOREIGN KEY (installation_id) REFERENCES mc_extension_installation(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_admin_permission (
            code VARCHAR(128) NOT NULL,
            description VARCHAR(255) NOT NULL,
            risk_level VARCHAR(16) NOT NULL DEFAULT 'normal',
            PRIMARY KEY (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_admin_role_permission (
            role_code VARCHAR(96) NOT NULL,
            permission_code VARCHAR(128) NOT NULL,
            PRIMARY KEY (role_code, permission_code),
            KEY idx_admin_role_permission_permission (permission_code),
            CONSTRAINT fk_admin_role_permission_permission FOREIGN KEY (permission_code) REFERENCES mc_admin_permission(code) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_admin_store_scope (
            admin_user_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (admin_user_id, store_id),
            KEY idx_admin_store_scope_store (store_id, admin_user_id),
            CONSTRAINT fk_admin_store_scope_user FOREIGN KEY (admin_user_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE,
            CONSTRAINT fk_admin_store_scope_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ($this->permissions() as $code => [$description, $risk]) {
            $this->addSql(
                'INSERT INTO mc_admin_permission (code,description,risk_level) VALUES (?,?,?)',
                [$code, $description, $risk],
            );
        }
        foreach (array_keys($this->permissions()) as $permission) {
            $this->addSql('INSERT INTO mc_admin_role_permission (role_code,permission_code) VALUES (?,?)', ['ROLE_SUPER_ADMIN', $permission]);
        }
        foreach ([
            'catalog.view','catalog.manage','orders.view','orders.manage','customers.view','content.manage','appearance.manage','forum.manage',
        ] as $permission) {
            $this->addSql('INSERT INTO mc_admin_role_permission (role_code,permission_code) VALUES (?,?)', ['ROLE_MANAGER', $permission]);
        }
        foreach (['catalog.view','catalog.manage','content.manage','appearance.manage'] as $permission) {
            $this->addSql('INSERT INTO mc_admin_role_permission (role_code,permission_code) VALUES (?,?)', ['ROLE_EDITOR', $permission]);
        }
        foreach (['orders.view','orders.manage','customers.view'] as $permission) {
            $this->addSql('INSERT INTO mc_admin_role_permission (role_code,permission_code) VALUES (?,?)', ['ROLE_SUPPORT', $permission]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_admin_store_scope');
        $this->addSql('DROP TABLE IF EXISTS mc_extension_setting_revision');
        $this->addSql('DROP TABLE IF EXISTS mc_extension_setting');
        $this->addSql('DROP TABLE IF EXISTS mc_admin_role_permission');
        $this->addSql('DROP TABLE IF EXISTS mc_admin_permission');
        $this->addSql('ALTER TABLE mc_notification_outbox DROP INDEX uq_notification_dedupe_key, DROP COLUMN dedupe_key');
        $this->addSql('DROP TABLE IF EXISTS mc_domain_event_delivery');
        $this->addSql('ALTER TABLE mc_outbox_event DROP INDEX idx_outbox_event_stale_lock, DROP COLUMN event_version, DROP COLUMN metadata, DROP COLUMN locked_at, DROP COLUMN locked_by, DROP COLUMN last_error, DROP COLUMN occurred_at, DROP COLUMN completed_at');
    }

    /** @return array<string,array{0:string,1:string}> */
    private function permissions(): array
    {
        return [
            'dashboard.view' => ['View administration dashboard', 'low'],
            'catalog.view' => ['View catalog', 'low'],
            'catalog.manage' => ['Create and change catalog data', 'normal'],
            'orders.view' => ['View orders', 'normal'],
            'orders.manage' => ['Change order and fulfillment state', 'high'],
            'orders.refund' => ['Issue payment refunds', 'critical'],
            'customers.view' => ['View customer data', 'high'],
            'customers.manage' => ['Change customer data', 'high'],
            'content.manage' => ['Manage pages, news and content', 'normal'],
            'appearance.manage' => ['Manage storefront appearance', 'normal'],
            'forum.manage' => ['Manage forum and moderation', 'normal'],
            'search.manage' => ['Manage search configuration', 'normal'],
            'extensions.manage' => ['Install, activate or disable extensions', 'critical'],
            'system.settings' => ['Change system and store settings', 'critical'],
            'system.recovery' => ['Create or restore recovery points', 'critical'],
            'system.update' => ['Stage or apply Core updates', 'critical'],
            'integrations.manage' => ['Manage external integration settings and secrets', 'critical'],
            'admin_users.manage' => ['Manage administrator access', 'critical'],
        ];
    }
}
