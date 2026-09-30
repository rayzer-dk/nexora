<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;

/** One cover image per category, chosen from the media library. */
final readonly class CategoryImageService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{asset_id:int,url:string,alt:string}|null */
    public function forCategory(int $categoryId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ci.asset_id,ci.alt_text,ma.storage_key FROM mc_category_image ci JOIN mc_media_asset ma ON ma.id=ci.asset_id WHERE ci.category_id=?',
            [$categoryId],
        );

        return is_array($row) ? ['asset_id' => (int) $row['asset_id'], 'url' => '/media/' . ltrim((string) $row['storage_key'], '/'), 'alt' => (string) $row['alt_text']] : null;
    }

    public function set(int $categoryId, int $storeId, ?int $assetId, string $alt = ''): void
    {
        if ($assetId === null || $assetId < 1) {
            $this->db->delete('mc_category_image', ['category_id' => $categoryId]);

            return;
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_store_media_asset WHERE store_id=? AND asset_id=?', [$storeId, $assetId]) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.category.image_missing'));
        }
        $this->db->executeStatement(
            'INSERT INTO mc_category_image (category_id,asset_id,alt_text,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),alt_text=VALUES(alt_text),updated_at=VALUES(updated_at)',
            [$categoryId, $assetId, mb_substr(trim(strip_tags($alt)), 0, 255, 'UTF-8'), gmdate('Y-m-d H:i:s.u')],
        );
    }

    public function imageFromRequest(\Symfony\Component\HttpFoundation\Request $request): int|false|null
    {
        if ($request->request->get('category_image_present') !== '1') {
            return false;
        }
        $id = (int) $request->request->get('category_image_id', 0);

        return $id > 0 ? $id : null;
    }
}
