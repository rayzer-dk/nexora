<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationIssue;
use Commerce\Modules\Migration\Domain\MigrationRecord;
use Doctrine\DBAL\Connection;

final readonly class MigrationTargetConflictAnalyzer
{
    public function __construct(private Connection $db, private MigrationIdMap $idMap)
    {
    }

    /** @return list<MigrationIssue> */
    public function analyze(MigrationSourceInterface $source, MigrationImportPlan $plan): array
    {
        $issues = [];
        $sourceHash = $this->idMap->sourceHash($plan->sourceInstanceKey);

        if (in_array(MigrationEntityType::Product, $source->supportedEntities(), true)) {
            $this->walk($source, MigrationEntityType::Product, $plan->batchSize, function (MigrationRecord $record) use (&$issues, $source, $sourceHash): void {
                if ($this->idMap->find($source->code(), $sourceHash, 'product', $record->sourceKey) !== null) {
                    return;
                }
                $sku = trim((string) ($record->data['sku'] ?? ''));
                if ($sku !== '' && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE sku=?', [$sku]) > 0) {
                    $issues[] = new MigrationIssue('warning', 'target_sku_conflict', 'SKU already exists in the target. A deterministic migration SKU will be used instead of overwriting the existing product.', MigrationEntityType::Product, $record->sourceKey);
                }
                $gtin = trim((string) ($record->data['gtin'] ?? ''));
                if ($gtin !== '' && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE gtin=?', [$gtin]) > 0) {
                    $issues[] = new MigrationIssue('warning', 'target_gtin_conflict', 'GTIN already exists in the target. The source product remains a separate imported product and requires post-import review.', MigrationEntityType::Product, $record->sourceKey);
                }
            });
        }

        if (in_array(MigrationEntityType::Customer, $source->supportedEntities(), true)) {
            $this->walk($source, MigrationEntityType::Customer, $plan->batchSize, function (MigrationRecord $record) use (&$issues, $source, $sourceHash): void {
                if ($this->idMap->find($source->code(), $sourceHash, 'customer', $record->sourceKey) !== null) {
                    return;
                }
                $email = mb_strtolower(trim((string) ($record->data['email'] ?? '')));
                if ($email !== '' && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_customer WHERE email_normalized=?', [$email]) > 0) {
                    $issues[] = new MigrationIssue('warning', 'target_customer_email_reuse', 'Customer email already exists in the target. The migration will reuse that customer instead of creating a duplicate.', MigrationEntityType::Customer, $record->sourceKey);
                }
            });
        }

        return $issues;
    }

    private function walk(MigrationSourceInterface $source, MigrationEntityType $type, int $limit, callable $consumer): void
    {
        $cursor = null;
        do {
            $batch = $source->read($type, $cursor, $limit);
            foreach ($batch->records as $record) {
                $consumer($record);
            }
            $cursor = $batch->nextCursor;
        } while (!$batch->complete);
    }
}
