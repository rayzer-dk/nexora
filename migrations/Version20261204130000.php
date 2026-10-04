<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261204130000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false; // ALTER TABLE commits implicitly; the data copy below runs in its own transactions
    }

    public function getDescription(): string
    {
        return 'Texts copied from the default language into languages that have no translation yet are marked, so they follow the default text until translated.';
    }

    public function up(Schema $schema): void
    {
        foreach (['mc_product_translation', 'mc_category_translation', 'mc_brand_translation'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD is_fallback TINYINT(1) NOT NULL DEFAULT 0', $table));
        }
    }

    /** Existing stores: every enabled language that lacks a translation shows the default text from now on. */
    public function postUp(Schema $schema): void
    {
        $filler = new \Commerce\Modules\Localization\Application\TranslationFallbackFiller($this->connection);
        foreach ($this->connection->fetchAllAssociative('SELECT store_id,locale_code FROM mc_store_locale WHERE enabled=1 AND is_default=0') as $row) {
            $filler->backfillLocale((int) $row['store_id'], (string) $row['locale_code']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['mc_product_translation', 'mc_category_translation', 'mc_brand_translation'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN is_fallback', $table));
        }
    }
}
