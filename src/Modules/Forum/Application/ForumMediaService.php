<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Avatars and post pictures: every upload is decoded and re-encoded to WebP, so EXIF data and hidden payloads never reach the site. */
final readonly class ForumMediaService
{
    public const MAX_ATTACHMENTS = 4;
    private const MAX_BYTES = 5_000_000;
    private const MAX_EDGE = 1600;

    public function __construct(private Connection $connection, private string $projectDir)
    {
    }

    public function saveAvatar(int $storeId, int $customerId, UploadedFile $file): void
    {
        [$path] = $this->store($file, 'avatars', 192, true);
        $old = $this->connection->fetchOne('SELECT avatar_url FROM mc_forum_profile WHERE store_id=? AND customer_id=?', [$storeId, $customerId]);
        $this->connection->update('mc_forum_profile', ['avatar_url' => '/media/' . $path], ['store_id' => $storeId, 'customer_id' => $customerId]);
        $this->deleteFile(is_string($old) ? $old : null);
    }

    public function removeAvatar(int $storeId, int $customerId): void
    {
        $old = $this->connection->fetchOne('SELECT avatar_url FROM mc_forum_profile WHERE store_id=? AND customer_id=?', [$storeId, $customerId]);
        $this->connection->update('mc_forum_profile', ['avatar_url' => null], ['store_id' => $storeId, 'customer_id' => $customerId]);
        $this->deleteFile(is_string($old) ? $old : null);
    }

    /** @param list<UploadedFile> $files */
    public function attach(int $postId, int $customerId, array $files): int
    {
        $saved = 0;
        foreach ($files as $file) {
            if ($saved >= self::MAX_ATTACHMENTS) {
                break;
            }
            [$path, $width, $height, $size] = $this->store($file, 'posts', self::MAX_EDGE, false);
            $this->connection->insert('mc_forum_attachment', [
                'post_id' => $postId,
                'customer_id' => $customerId,
                'storage_path' => '/media/' . $path,
                'original_name' => mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '', $file->getClientOriginalName()) ?: 'image', 0, 180, 'UTF-8'),
                'width' => $width,
                'height' => $height,
                'size_bytes' => $size,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
            ++$saved;
        }

        return $saved;
    }

    /**
     * @param list<int> $postIds
     * @return array<int,list<array{url:string,name:string,width:int,height:int}>>
     */
    public function forPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT post_id,storage_path,original_name,width,height FROM mc_forum_attachment WHERE post_id IN (' . implode(',', array_map('intval', $postIds)) . ') ORDER BY id',
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = ['url' => (string) $row['storage_path'], 'name' => (string) $row['original_name'], 'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }

        return $map;
    }

    /** @return array{0:string,1:int,2:int,3:int} relative path under public/media, width, height, bytes */
    private function store(UploadedFile $file, string $group, int $maxEdge, bool $square): array
    {
        if (!$file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.image_invalid'));
        }
        $info = @getimagesize($file->getPathname());
        $source = null;
        if (is_array($info) && function_exists('imagecreatefromstring')) {
            $mime = (string) ($info['mime'] ?? '');
            if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && ($info[0] * $info[1]) <= 40_000_000) {
                $raw = file_get_contents($file->getPathname());
                $source = $raw === false ? false : @imagecreatefromstring($raw);
            }
        }
        if (!$source instanceof \GdImage || !function_exists('imagewebp')) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.image_invalid'));
        }
        $w = imagesx($source);
        $h = imagesy($source);
        if ($square) {
            $edge = min($w, $h);
            $cropped = imagecrop($source, ['x' => intdiv($w - $edge, 2), 'y' => intdiv($h - $edge, 2), 'width' => $edge, 'height' => $edge]);
            if ($cropped instanceof \GdImage) {
                $source = $cropped;
                $w = $h = $edge;
            }
        }
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($nw, $nh);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $relative = 'forum/' . $group . '/' . date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.webp';
        $absolute = rtrim($this->projectDir, '/\\') . '/public/media/' . $relative;
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.image_invalid'));
        }
        if (!imagewebp($canvas, $absolute, 84)) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.image_invalid'));
        }

        return [$relative, $nw, $nh, (int) filesize($absolute)];
    }

    private function deleteFile(?string $url): void
    {
        if ($url === null || !str_starts_with($url, '/media/forum/avatars/') || str_contains($url, '..')) {
            return;
        }
        @unlink(rtrim($this->projectDir, '/\\') . '/public' . $url);
    }
}
