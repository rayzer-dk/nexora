<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Doctrine\DBAL\Connection;

/**
 * One ordered list of the photos and videos of a product. The merchant drags items in the admin; the position of every item
 * is its sort_order (photos: mc_product_media, videos: mc_product_video). The first PHOTO of the list is the main photo.
 */
final class ProductMediaOrder
{
    public function __construct(private readonly Connection $connection, private readonly MediaImageService $images)
    {
    }

    /**
     * Photos and videos in the shared order, as tokens ("p:<asset id>", "v:<video id>") with their row.
     *
     * @param list<array<string,mixed>> $photos rows of MediaImageService::productImages
     * @param list<array<string,mixed>> $videos rows of ProductVideoService::forAdmin
     * @return list<array<string,mixed>>
     */
    public function merge(array $photos, array $videos): array
    {
        $lowest = $photos === [] ? 0 : (int) min(array_map(static fn (array $p): int => (int) $p['sort_order'], $photos));
        $items = [];
        foreach ($videos as $video) {
            $items[] = ['kind' => 'video', 'token' => 'v:' . $video['id'], 'sort' => (int) $video['sort_order'], 'rank' => 0, 'row' => $video];
        }
        foreach ($photos as $photo) {
            $items[] = ['kind' => 'photo', 'token' => 'p:' . $photo['id'], 'sort' => $photo['role'] === 'primary' ? $lowest : (int) $photo['sort_order'], 'rank' => $photo['role'] === 'primary' ? 1 : 2, 'row' => $photo];
        }
        usort($items, static fn (array $a, array $b): int => [$a['sort'], $a['rank']] <=> [$b['sort'], $b['rank']]);

        return $items;
    }

    /**
     * Saves the order. Tokens that are not in the list keep their relative order after the listed ones. The first photo of
     * the result becomes the main photo. Unknown or foreign tokens are ignored.
     *
     * @param list<string> $tokens
     */
    public function save(int $productId, array $tokens): void
    {
        $this->connection->transactional(function (Connection $db) use ($productId, $tokens): void {
            $photos = $db->fetchAllAssociative("SELECT media_asset_id AS id,role,sort_order FROM mc_product_media WHERE product_id=? AND role IN ('primary','gallery') ORDER BY (role='primary') DESC,sort_order,media_asset_id", [$productId]);
            $videos = $db->fetchAllAssociative('SELECT id,sort_order FROM mc_product_video WHERE product_id=? ORDER BY sort_order,id', [$productId]);
            $known = $this->merge(
                array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'role' => (string) $r['role'], 'sort_order' => (int) $r['sort_order']], $photos),
                array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sort_order' => (int) $r['sort_order']], $videos),
            );
            $byToken = [];
            foreach ($known as $item) {
                $byToken[$item['token']] = $item;
            }
            $ordered = [];
            foreach ($tokens as $token) {
                $token = (string) $token;
                if (isset($byToken[$token]) && !isset($ordered[$token])) {
                    $ordered[$token] = $byToken[$token];
                }
            }
            foreach ($known as $item) {
                $ordered[$item['token']] ??= $item;
            }
            $position = 10;
            $mainPhoto = null;
            foreach ($ordered as $item) {
                if ($item['kind'] === 'photo') {
                    $db->executeStatement('UPDATE mc_product_media SET sort_order=? WHERE product_id=? AND media_asset_id=?', [$position, $productId, $item['row']['id']]);
                    $mainPhoto ??= (int) $item['row']['id'];
                } else {
                    $db->executeStatement('UPDATE mc_product_video SET sort_order=? WHERE product_id=? AND id=?', [$position, $productId, $item['row']['id']]);
                }
                $position += 10;
            }
            if ($mainPhoto !== null) {
                $db->executeStatement("UPDATE mc_product_media SET role='gallery' WHERE product_id=? AND role='primary' AND media_asset_id<>?", [$productId, $mainPhoto]);
                $db->executeStatement("UPDATE mc_product_media SET role='primary' WHERE product_id=? AND media_asset_id=?", [$productId, $mainPhoto]);
            }
        });
        $this->images->warmPrimaryOf($productId);
    }
}
