<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Twig;

use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The PDF engine may not fetch anything from the network, so the shop logo and the product photos of an invoice are read from
 * the local media folder and embedded in the page.
 */
final class OrderDocumentTwigExtension extends AbstractExtension
{
    private const MAX_IMAGE_BYTES = 400_000;

    public function __construct(
        private readonly Connection $db,
        private readonly StorefrontPresentationSettings $presentation,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('document_assets', $this->assets(...))];
    }

    /**
     * @param array<string,mixed> $document
     * @return array{logo:?string,images:array<string,string>}
     */
    public function assets(array $document): array
    {
        $out = ['logo' => null, 'images' => []];
        try {
            $storeId = (int) $this->db->fetchOne('SELECT store_id FROM mc_order_document WHERE public_id=?', [Uuid::fromString((string) ($document['public_id'] ?? ''))->toBinary()]);
            if ($storeId < 1) {
                return $out;
            }
            $brand = $this->presentation->get($storeId)['brand'] ?? [];
            $out['logo'] = $this->embed((string) ($brand['logo'] ?? ''), 320);
            $skus = array_values(array_unique(array_filter(array_map(static fn (array $i): string => (string) ($i['sku'] ?? ''), (array) ($document['snapshot']['items'] ?? [])))));
            if ($skus !== []) {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT v.sku,ma.storage_key FROM mc_product_variant v JOIN mc_product_media pm ON pm.product_id=v.product_id AND pm.role IN ('primary','gallery')
                     JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE v.sku IN (" . implode(',', array_fill(0, count($skus), '?')) . ') ORDER BY (pm.role=\'primary\') DESC,pm.sort_order',
                    $skus,
                );
                foreach ($rows as $row) {
                    $sku = (string) $row['sku'];
                    if (!isset($out['images'][$sku]) && ($uri = $this->embed('/media/' . ltrim((string) $row['storage_key'], '/'))) !== null) {
                        $out['images'][$sku] = $uri;
                    }
                }
            }
        } catch (\Throwable) {
            // An invoice without pictures is still a valid invoice.
        }

        return $out;
    }

    /** Local image as a small PNG (the PDF engine reads PNG and JPEG everywhere, WebP not always). */
    private function embed(string $publicPath, int $maxSide = 160): ?string
    {
        $path = (string) parse_url($publicPath, PHP_URL_PATH);
        if ($path === '' || str_contains($path, '..') || !(str_starts_with($path, '/media/') || str_starts_with($path, '/assets/'))) {
            return null;
        }
        $file = rtrim($this->projectDir, '/\\') . '/public' . $path;
        if (!is_file($file) || (int) filesize($file) > 4_000_000 || strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($raw);
            if ($image !== false) {
                $w = imagesx($image);
                $h = imagesy($image);
                $scale = min(1.0, $maxSide / max(1, max($w, $h)));
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $canvas = imagecreatetruecolor($nw, $nh);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                imagepng($canvas, null, 7);
                $png = (string) ob_get_clean();

                return $png !== '' ? 'data:image/png;base64,' . base64_encode($png) : null;
            }
        }
        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null };

        return $type !== null && strlen($raw) <= self::MAX_IMAGE_BYTES ? 'data:' . $type . ';base64,' . base64_encode($raw) : null;
    }
}
