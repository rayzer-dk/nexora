<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Choose Schema.org type per CMS page and blog article without changing existing defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_content_page_meta ADD COLUMN schema_type VARCHAR(24) NOT NULL DEFAULT 'auto' AFTER noindex");
        $this->addSql("ALTER TABLE mc_blog_article_meta ADD COLUMN schema_type VARCHAR(24) NOT NULL DEFAULT 'auto' AFTER noindex");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_blog_article_meta DROP COLUMN schema_type');
        $this->addSql('ALTER TABLE mc_content_page_meta DROP COLUMN schema_type');
    }
}
