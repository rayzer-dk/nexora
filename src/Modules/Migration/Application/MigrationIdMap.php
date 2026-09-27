<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class MigrationIdMap
{
    public function __construct(private Connection $db) {}

    public function sourceHash(string $sourceInstanceKey): string
    {
        return hash('sha256', $sourceInstanceKey, true);
    }

    public function find(string $sourceSystem, string $sourceHash, string $entityType, string $sourceKey): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT target_public_id FROM mc_import_id_map WHERE source_system=? AND source_instance_hash=? AND entity_type=? AND source_key=? LIMIT 1',
            [$sourceSystem, $sourceHash, $entityType, $sourceKey],
        );
        if ($value === false || !is_string($value) || strlen($value) !== 16) {
            return null;
        }
        return Uuid::fromBinary($value)->toRfc4122();
    }

    public function save(string $sourceSystem, string $sourceHash, string $entityType, string $sourceKey, string $targetPublicId): void
    {
        $binary = Uuid::fromString($targetPublicId)->toBinary();
        $now = gmdate('Y-m-d H:i:s.u');
        $existing = $this->db->fetchOne(
            'SELECT id FROM mc_import_id_map WHERE source_system=? AND source_instance_hash=? AND entity_type=? AND source_key=? LIMIT 1',
            [$sourceSystem, $sourceHash, $entityType, $sourceKey],
        );
        if ($existing === false) {
            $this->db->insert('mc_import_id_map', [
                'source_system'=>$sourceSystem,'source_instance_hash'=>$sourceHash,'entity_type'=>$entityType,'source_key'=>$sourceKey,
                'target_public_id'=>$binary,'updated_at'=>$now,
            ]);
            return;
        }
        $this->db->update('mc_import_id_map', ['target_public_id'=>$binary,'updated_at'=>$now], ['id'=>(int)$existing]);
    }

    public function targetInternalId(string $table, string $publicId): ?int
    {
        $allowed = ['mc_product','mc_category','mc_brand'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.31490c059ff7'));
        }
        $id = $this->db->fetchOne('SELECT id FROM '.$table.' WHERE public_id=? LIMIT 1', [Uuid::fromString($publicId)->toBinary()]);
        return $id === false ? null : (int)$id;
    }
}
