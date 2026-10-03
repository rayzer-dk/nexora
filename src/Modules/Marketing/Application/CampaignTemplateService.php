<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Doctrine\DBAL\Connection;

/** Reusable campaign texts: saved by name, loaded into the campaign form, deleted on their own (sent campaigns stay in the history). */
final readonly class CampaignTemplateService
{
    public function __construct(private Connection $db, private NewsletterCampaignService $campaigns)
    {
    }

    /** @return list<array{id:int,name:string,subject:string,body_format:string,updated_at:string}> */
    public function list(int $storeId): array
    {
        try {
            /** @var list<array{id:int,name:string,subject:string,body_format:string,updated_at:string}> $rows */
            $rows = $this->db->fetchAllAssociative('SELECT id,name,subject,body_format,updated_at FROM mc_campaign_template WHERE store_id=? ORDER BY name', [$storeId]);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'subject' => (string) $r['subject'], 'body_format' => (string) $r['body_format'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    /** @return array{id:int,name:string,subject:string,body:string,body_format:string}|null */
    public function find(int $storeId, int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id,name,subject,body,body_format FROM mc_campaign_template WHERE store_id=? AND id=?', [$storeId, $id]);

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'body_format' => (string) $row['body_format']] : null;
    }

    /** Saves under the name (a template with the same name is replaced). @return array{id:int,name:string} */
    public function save(int $storeId, string $name, string $subject, string $body, string $format): array
    {
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($name)) ?? '');
        $subject = trim($subject);
        if ($name === '' || mb_strlen($name) > 120 || $subject === '' || mb_strlen($subject) > 255 || trim($body) === '' || mb_strlen($body) > 20000) {
            throw new \InvalidArgumentException('template');
        }
        $format = in_array($format, NewsletterCampaignService::FORMATS, true) ? $format : 'text';
        $body = $this->campaigns->cleanBody($body, $format);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->executeStatement(
            'INSERT INTO mc_campaign_template (store_id,name,subject,body,body_format,created_at,updated_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE subject=VALUES(subject),body=VALUES(body),body_format=VALUES(body_format),updated_at=VALUES(updated_at)',
            [$storeId, $name, $subject, $body, $format, $now, $now],
        );
        $id = (int) $this->db->fetchOne('SELECT id FROM mc_campaign_template WHERE store_id=? AND name=?', [$storeId, $name]);

        return ['id' => $id, 'name' => $name];
    }

    public function delete(int $storeId, int $id): bool
    {
        return $this->db->delete('mc_campaign_template', ['store_id' => $storeId, 'id' => $id]) > 0;
    }
}
