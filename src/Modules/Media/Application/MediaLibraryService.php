<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class MediaLibraryService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function search(int $storeId, string $query = '', ?int $folderId = null, int $page = 1, int $limit = 48): array
    {
        $page = max(1, $page);
        $limit = max(12, min(96, $limit));
        $offset = ($page - 1) * $limit;
        $where = ['1=1'];
        $params = [];
        if ($folderId !== null) {
            $where[] = 'mam.folder_id=?';
            $params[] = $folderId;
        }
        $query = trim($query);
        if ($query !== '') {
            $where[] = '(COALESCE(mam.title,\'\') LIKE ? OR COALESCE(mam.alt_text,\'\') LIKE ? OR ma.storage_key LIKE ? OR CAST(COALESCE(mam.tags_json, JSON_ARRAY()) AS CHAR) LIKE ?)';
            $needle = '%' . addcslashes($query, '%_') . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }
        $sqlWhere = implode(' AND ', $where);
        $rows = $this->db->fetchAllAssociative(
            "SELECT ma.id,ma.storage_key,ma.mime_type,ma.bytes,ma.width,ma.height,ma.created_at,mam.folder_id,mam.alt_text,mam.title,mam.focal_x,mam.focal_y,mam.tags_json
             FROM mc_media_asset ma
             LEFT JOIN mc_media_asset_meta mam ON mam.asset_id=ma.id
             WHERE {$sqlWhere}
             ORDER BY ma.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_media_asset ma LEFT JOIN mc_media_asset_meta mam ON mam.asset_id=ma.id WHERE {$sqlWhere}", $params);
        $items = array_map(function (array $row): array {
            $tags = [];
            if (is_string($row['tags_json'] ?? null) && $row['tags_json'] !== '') {
                try { $decoded = json_decode($row['tags_json'], true, 16, JSON_THROW_ON_ERROR); $tags = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : []; } catch (\Throwable) {}
            }
            return [
                'id'=>(int)$row['id'], 'url'=>'/media/' . ltrim((string)$row['storage_key'],'/'), 'storage_key'=>(string)$row['storage_key'],
                'mime_type'=>(string)$row['mime_type'], 'bytes'=>(int)$row['bytes'], 'width'=>(int)$row['width'], 'height'=>(int)$row['height'],
                'folder_id'=>isset($row['folder_id']) ? (int)$row['folder_id'] : null, 'alt_text'=>(string)($row['alt_text'] ?? ''), 'title'=>(string)($row['title'] ?? ''),
                'focal_x'=>(float)($row['focal_x'] ?? 50), 'focal_y'=>(float)($row['focal_y'] ?? 50), 'tags'=>$tags, 'created_at'=>$row['created_at'] ?? null,
            ];
        }, $rows);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit];
    }

    /** @return list<array<string,mixed>> */
    public function folders(int $storeId): array
    {
        return $this->db->fetchAllAssociative('SELECT id,parent_id,name,slug FROM mc_media_folder WHERE store_id=? ORDER BY COALESCE(parent_id,0),name,id', [$storeId]);
    }

    public function createFolder(int $storeId, string $name, ?int $parentId = null): int
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 190) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.medialibraryservice.nazva_papky_maie_mistyty_vid_1_do_190_symvoliv'));
        if ($parentId !== null && !(bool)$this->db->fetchOne('SELECT 1 FROM mc_media_folder WHERE id=? AND store_id=?', [$parentId,$storeId])) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.medialibraryservice.batkivsku_papku_ne_znaideno'));
        $base = mb_strtolower($name);
        $base = preg_replace('/[^\pL\pN]+/u','-', $base) ?: 'folder';
        $base = trim($base,'-'); if ($base === '') $base = 'folder';
        $slug = mb_substr($base,0,170); $candidate=$slug; $i=2;
        while ((bool)$this->db->fetchOne('SELECT 1 FROM mc_media_folder WHERE store_id=? AND parent_id <=> ? AND slug=?',[$storeId,$parentId,$candidate])) { $candidate=mb_substr($slug,0,160).'-'.$i++; }
        $this->db->insert('mc_media_folder',['store_id'=>$storeId,'parent_id'=>$parentId,'name'=>$name,'slug'=>$candidate,'created_at'=>gmdate('Y-m-d H:i:s.u')]);
        return (int)$this->db->lastInsertId();
    }

    public function usageCount(int $assetId): int
    {
        $storageKey=$this->db->fetchOne('SELECT storage_key FROM mc_media_asset WHERE id=?',[$assetId]);
        if (!is_string($storageKey) || $storageKey === '') return 0;
        $catalog=(int)$this->db->fetchOne("SELECT (SELECT COUNT(*) FROM mc_product_media WHERE media_asset_id=?) + (SELECT COUNT(*) FROM mc_product_document WHERE media_id=?)", [$assetId,$assetId]);
        $layout=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_layout_revision WHERE CAST(payload AS CHAR) LIKE ?', ['%' . $storageKey . '%']);
        return $catalog + $layout;
    }

    public function delete(int $assetId): void
    {
        $usage = $this->usageCount($assetId);
        if ($usage > 0) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.medialibraryservice.fail_vykorystovuietsia_v_katalozi_spochatku_pryberit'));
        $this->db->delete('mc_media_asset',['id'=>$assetId]);
    }
}
