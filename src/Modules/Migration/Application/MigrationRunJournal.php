<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Doctrine\DBAL\Connection;

final readonly class MigrationRunJournal
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string,mixed> $options */
    public function start(string $publicId, string $sourceCode, array $options = []): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_migration_run', [
            'public_id'=>$publicId,'source_code'=>$sourceCode,'status'=>'running','entity_type'=>null,'cursor_value'=>null,
            'processed_count'=>0,'issue_count'=>0,'last_error'=>null,
            'options_json'=>json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at'=>$now,'updated_at'=>$now,'completed_at'=>null,
        ]);
    }

    public function checkpoint(string $publicId, string $entityType, ?string $cursor, int $processed, int $issues): void
    {
        $this->db->update('mc_migration_run', [
            'entity_type'=>$entityType,'cursor_value'=>$cursor,'processed_count'=>$processed,'issue_count'=>$issues,
            'updated_at'=>gmdate('Y-m-d H:i:s.u'),
        ], ['public_id'=>$publicId]);
    }

    public function fail(string $publicId, string $message): void
    {
        $this->db->update('mc_migration_run', ['status'=>'failed','last_error'=>mb_substr($message,0,1000),'updated_at'=>gmdate('Y-m-d H:i:s.u')], ['public_id'=>$publicId]);
    }

    public function complete(string $publicId): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->update('mc_migration_run', ['status'=>'completed','updated_at'=>$now,'completed_at'=>$now], ['public_id'=>$publicId]);
    }

    public function rolledBack(string $publicId): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->update('mc_migration_run', [
            'status'=>'rolled_back',
            'updated_at'=>$now,
            'completed_at'=>$now,
            'last_error'=>null,
        ], ['public_id'=>$publicId]);
    }

    public function load(string $publicId): ?MigrationResumeState
    {
        $row = $this->db->fetchAssociative('SELECT public_id,source_code,status,entity_type,cursor_value,processed_count,issue_count FROM mc_migration_run WHERE public_id=?', [$publicId]);
        if (!is_array($row)) {
            return null;
        }
        return new MigrationResumeState((string)$row['public_id'],(string)$row['source_code'],(string)$row['status'],$row['entity_type']!==null?(string)$row['entity_type']:null,$row['cursor_value']!==null?(string)$row['cursor_value']:null,(int)$row['processed_count'],(int)$row['issue_count']);
    }
}
