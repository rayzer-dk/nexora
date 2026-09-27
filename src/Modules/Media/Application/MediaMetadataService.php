<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class MediaMetadataService
{
    public function __construct(private Connection $db)
    {
    }

    public function save(int $assetId, MediaMetadata $meta): void
    {
        if ($assetId < 1 || $meta->focalX < 0 || $meta->focalX > 100 || $meta->focalY < 0 || $meta->focalY > 100) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7466ba5dc7d7'));
        }
        $tags = array_values(array_unique(array_slice(array_filter(array_map(static fn(string $v): string => trim($v), $meta->tags), static fn(string $v): bool => $v !== '' && mb_strlen($v) <= 64), 0, 30)));
        $payload = [
            'folder_id'=>$meta->folderId,
            'alt_text'=>mb_substr(strip_tags($meta->altText),0,500),
            'title'=>mb_substr(strip_tags($meta->title),0,500),
            'focal_x'=>$meta->focalX,
            'focal_y'=>$meta->focalY,
            'tags_json'=>json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at'=>gmdate('Y-m-d H:i:s.u'),
        ];
        $exists = (bool) $this->db->fetchOne('SELECT 1 FROM mc_media_asset_meta WHERE asset_id=?', [$assetId]);
        $exists ? $this->db->update('mc_media_asset_meta', $payload, ['asset_id'=>$assetId]) : $this->db->insert('mc_media_asset_meta', ['asset_id'=>$assetId] + $payload);
    }
}
