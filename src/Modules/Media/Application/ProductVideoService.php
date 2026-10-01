<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Videos of a product are LINKS (YouTube, Vimeo or a direct video file); nothing is uploaded or hosted here.
 * The storefront shows a preview picture and loads the player only after a click. The preview is fetched once when the
 * link is saved and stored as an ordinary library picture (so it gets the usual sizes); only fixed hosts are ever contacted.
 */
final class ProductVideoService
{
    public const MAX_PER_PRODUCT = 8;
    private const FILE_EXTENSIONS = ['mp4', 'webm', 'ogv', 'm4v'];

    public function __construct(
        private readonly Connection $connection,
        private readonly MediaImageService $images,
        private readonly MediaVariantService $variants,
        private readonly HttpClientInterface $http,
    ) {
    }

    /**
     * Recognizes a video link. Returns provider (youtube|vimeo|file), the provider's video id (the URL for a file) and the
     * canonical URL; null when the link is not a supported video.
     *
     * @return array{provider:string,ref:string,url:string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 500 || preg_match('~[\s<>"\']~', $input) === 1) {
            return null;
        }
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $input) === 1) {
            return ['provider' => 'youtube', 'ref' => $input, 'url' => 'https://www.youtube.com/watch?v=' . $input];
        }
        $parts = parse_url(str_contains($input, '://') ? $input : 'https://' . $input);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower(preg_replace('~^(www|m)\.~i', '', (string) $parts['host']) ?? '');
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $id = (string) ($query['v'] ?? '');
            } elseif (preg_match('~^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~', $path, $m) === 1) {
                $id = $m[1];
            }
        }
        if ($id !== null) {
            $id = substr($id, 0, 11);

            return preg_match('~^[A-Za-z0-9_-]{11}$~', $id) === 1 ? ['provider' => 'youtube', 'ref' => $id, 'url' => 'https://www.youtube.com/watch?v=' . $id] : null;
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('~^(?:/video|/channels/[^/]+|/groups/[^/]+/videos)?/(\d{5,12})(?:/([a-f0-9]{6,16}))?/?$~', $path, $m) === 1) {
            $hash = $m[2] ?? ($query['h'] ?? '');

            return ['provider' => 'vimeo', 'ref' => $m[1] . (is_string($hash) && $hash !== '' ? ':' . $hash : ''), 'url' => 'https://vimeo.com/' . $m[1] . (is_string($hash) && $hash !== '' ? '/' . $hash : '')];
        }
        if (strtolower((string) ($parts['scheme'])) === 'https' && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::FILE_EXTENSIONS, true) && !isset($parts['user'])) {
            $url = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');

            return strlen($url) <= 500 ? ['provider' => 'file', 'ref' => substr(hash('sha256', $url), 0, 32), 'url' => $url] : null;
        }

        return null;
    }

    /** Address of the player of a link (without autoplay; the storefront script adds it on click). */
    public static function embedUrl(string $provider, string $ref, string $url): string
    {
        return match ($provider) {
            'youtube' => 'https://www.youtube-nocookie.com/embed/' . $ref . '?rel=0&playsinline=1',
            'vimeo' => 'https://player.vimeo.com/video/' . explode(':', $ref)[0] . (str_contains($ref, ':') ? '?h=' . explode(':', $ref)[1] : ''),
            default => $url,
        };
    }

    /** @return list<array<string,mixed>> */
    public function forAdmin(int $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.id,v.provider,v.video_ref,v.url,v.title,v.sort_order,v.poster_asset_id,ma.storage_key FROM mc_product_video v LEFT JOIN mc_media_asset ma ON ma.id=v.poster_asset_id WHERE v.product_id=? ORDER BY v.sort_order,v.id',
            [$productId],
        );

        return array_map(fn (array $r): array => [
            'id' => (int) $r['id'], 'provider' => (string) $r['provider'], 'url' => (string) $r['url'], 'title' => (string) ($r['title'] ?? ''),
            'sort_order' => (int) $r['sort_order'],
            'poster' => $this->posterUrl($r, 'thumb'),
        ], $rows);
    }

    /**
     * Videos for the product page, each with its place (sort_order) in the shared list of photos and videos.
     *
     * @return list<array<string,mixed>>
     */
    public function forStorefront(int $productId, string $productName): array
    {
        $out = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.id,v.provider,v.video_ref,v.url,v.title,v.sort_order,v.poster_asset_id,ma.storage_key,ma.width,ma.height FROM mc_product_video v LEFT JOIN mc_media_asset ma ON ma.id=v.poster_asset_id WHERE v.product_id=? ORDER BY v.sort_order,v.id',
            [$productId],
        );
        foreach ($rows as $r) {
            $master = $r['storage_key'] !== null ? '/media/' . ltrim((string) $r['storage_key'], '/') : null;
            $poster = $this->posterUrl($r, 'product');
            $title = (string) ($r['title'] ?: $productName);
            $out[] = [
                'type' => 'video',
                'provider' => (string) $r['provider'],
                'embed' => self::embedUrl((string) $r['provider'], (string) $r['video_ref'], (string) $r['url']),
                'video_url' => (string) $r['url'],
                'title' => $title,
                'url' => $poster,
                'srcset' => $master !== null ? $this->variants->srcset($master, ['product', 'zoom']) : '',
                'avif_srcset' => $master !== null && $this->variants->avifEnabled() ? $this->variants->srcset($master, ['product', 'zoom'], 'avif') : '',
                'sizes' => '(max-width: 900px) 100vw, 50vw',
                'thumb' => $this->posterUrl($r, 'thumb'),
                'full' => $master !== null ? $this->variants->url($master, 'zoom') : $poster,
                'alt' => $title,
                'width' => (int) ($r['width'] ?: 1280),
                'height' => (int) ($r['height'] ?: 720),
                'has_poster' => $master !== null || $r['provider'] === 'youtube',
                'sort_order' => (int) $r['sort_order'],
            ];
        }

        return $out;
    }

    /**
     * Adds a link to a product. Throws InvalidArgumentException with a translated message on a bad link or a full list.
     */
    public function add(int $productId, string $input, ?string $title, ?UploadedFile $poster = null, ?int $storeId = null): int
    {
        $parsed = self::parse($input);
        if ($parsed === null) {
            throw new \InvalidArgumentException(CanonicalUiText::get('media.video_link_invalid'));
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_product_video WHERE product_id=?', [$productId]) >= self::MAX_PER_PRODUCT) {
            throw new \InvalidArgumentException(CanonicalUiText::get('media.video_limit', ['max' => self::MAX_PER_PRODUCT]));
        }
        if ($this->connection->fetchOne('SELECT 1 FROM mc_product_video WHERE product_id=? AND provider=? AND video_ref=?', [$productId, $parsed['provider'], $parsed['ref']]) !== false) {
            throw new \InvalidArgumentException(CanonicalUiText::get('media.video_duplicate'));
        }
        $posterId = null;
        if ($poster instanceof UploadedFile && $poster->isValid()) {
            $posterId = $this->images->upload($poster, $storeId, null, 'video-posters')->assetId;
        } else {
            $posterId = $this->fetchPoster($parsed, $storeId);
        }
        $next = (int) $this->connection->fetchOne('SELECT GREATEST(COALESCE((SELECT MAX(sort_order) FROM mc_product_media WHERE product_id=?),0),COALESCE((SELECT MAX(sort_order) FROM mc_product_video WHERE product_id=?),0))+10', [$productId, $productId]);
        $this->connection->insert('mc_product_video', [
            'product_id' => $productId, 'provider' => $parsed['provider'], 'video_ref' => $parsed['ref'], 'url' => $parsed['url'],
            'title' => $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 190) : null,
            'sort_order' => $next, 'poster_asset_id' => $posterId,
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @param array<int|string,mixed> $titles */
    public function updateMany(int $productId, array $titles): void
    {
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM mc_product_video WHERE product_id=?', [$productId]) as $id) {
            $id = (int) $id;
            if (array_key_exists($id, $titles)) {
                $title = trim((string) $titles[$id]);
                $this->connection->update('mc_product_video', ['title' => $title === '' ? null : mb_substr($title, 0, 190)], ['id' => $id, 'product_id' => $productId]);
            }
        }
    }

    public function remove(int $productId, int $id): void
    {
        $this->connection->delete('mc_product_video', ['id' => $id, 'product_id' => $productId]);
    }

    /** @param array<string,mixed> $row */
    private function posterUrl(array $row, string $preset): string
    {
        if (($row['storage_key'] ?? null) !== null) {
            return $this->variants->url('/media/' . ltrim((string) $row['storage_key'], '/'), $preset);
        }
        if (($row['provider'] ?? '') === 'youtube') {
            return 'https://i.ytimg.com/vi/' . $row['video_ref'] . '/hqdefault.jpg';
        }

        return '/assets/video-placeholder.svg';
    }

    /**
     * Preview of YouTube/Vimeo, stored as a library picture. Best effort: when the host cannot be reached the storefront
     * falls back to the provider's own preview (YouTube) or a neutral placeholder.
     *
     * @param array{provider:string,ref:string,url:string} $parsed
     */
    private function fetchPoster(array $parsed, ?int $storeId): ?int
    {
        try {
            $address = null;
            if ($parsed['provider'] === 'youtube') {
                $address = 'https://i.ytimg.com/vi/' . $parsed['ref'] . '/hqdefault.jpg';
            } elseif ($parsed['provider'] === 'vimeo') {
                $meta = $this->http->request('GET', 'https://vimeo.com/api/oembed.json', ['query' => ['url' => $parsed['url'], 'width' => 1280], 'timeout' => 5, 'max_duration' => 8])->toArray();
                $thumb = (string) ($meta['thumbnail_url'] ?? '');
                $host = strtolower((string) parse_url($thumb, PHP_URL_HOST));
                if (str_starts_with($thumb, 'https://') && ($host === 'vimeocdn.com' || str_ends_with($host, '.vimeocdn.com'))) {
                    $address = $thumb;
                }
            }
            if ($address === null) {
                return null;
            }
            $response = $this->http->request('GET', $address, ['timeout' => 5, 'max_duration' => 8]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getContent();
            if ($body === '' || strlen($body) > 5_000_000) {
                return null;
            }
            $temporary = tempnam(sys_get_temp_dir(), 'vposter');
            if ($temporary === false) {
                return null;
            }
            try {
                file_put_contents($temporary, $body);
                $info = @getimagesize($temporary);
                if (!is_array($info) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return null;
                }

                return $this->images->upload(new UploadedFile($temporary, 'poster-' . $parsed['ref'] . '.jpg', $info['mime'], null, true), $storeId, null, 'video-posters')->assetId;
            } finally {
                @unlink($temporary);
            }
        } catch (\Throwable) {
            return null;
        }
    }
}
