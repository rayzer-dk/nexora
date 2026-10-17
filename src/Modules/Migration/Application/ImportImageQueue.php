<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Media\Application\MediaImageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The photos of imported products stay on the old shop's server. The import only lists them here; they are downloaded afterwards, a few at a time
 * (by the scheduler and by the "Download photos" button), so a thousand products never make one request wait for a thousand downloads.
 */
final class ImportImageQueue
{
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly MediaImageService $media,
        private readonly HttpClientInterface $http,
        private readonly OutboundUrlPolicy $policy,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    /** @param list<string> $urls the first one becomes the main photo */
    public function enqueue(int $storeId, int $productId, array $urls, string $altText): int
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $count = 0;
        foreach (array_values($urls) as $index => $url) {
            $url = trim($url);
            if ($url === '' || strlen($url) > 1500 || preg_match('~^https?://~i', $url) !== 1) {
                continue;
            }
            $this->db->insert('mc_import_image_queue', [
                'store_id' => $storeId, 'product_id' => $productId, 'url' => $url, 'alt_text' => mb_substr($altText, 0, 255), 'role' => $index === 0 ? 'primary' : 'gallery',
                'sort_order' => $index, 'status' => 'pending', 'attempts' => 0, 'created_at' => $now,
            ]);
            ++$count;
        }

        return $count;
    }

    /** @return array{pending:int,done:int,failed:int} */
    public function counts(): array
    {
        $out = ['pending' => 0, 'done' => 0, 'failed' => 0];
        try {
            foreach ($this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM mc_import_image_queue GROUP BY status') as $status => $n) {
                if (isset($out[$status])) {
                    $out[$status] = (int) $n;
                }
            }
        } catch (\Throwable) {
            // The table does not exist before the migration ran.
        }

        return $out;
    }

    /** Takes the next photos from the list. Returns how many were stored and how many failed in this round. @return array{done:int,failed:int} */
    public function process(int $limit = 20, float $budgetSeconds = 40.0): array
    {
        $started = microtime(true);
        $done = 0;
        $failed = 0;
        $rows = $this->db->fetchAllAssociative("SELECT id,store_id,product_id,url,alt_text,role,sort_order,attempts FROM mc_import_image_queue WHERE status='pending' ORDER BY id LIMIT " . max(1, min(200, $limit)));
        foreach ($rows as $row) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
            try {
                $this->store($row);
                $this->db->update('mc_import_image_queue', ['status' => 'done', 'processed_at' => gmdate('Y-m-d H:i:s.u'), 'error' => null], ['id' => (int) $row['id']]);
                ++$done;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $this->db->update('mc_import_image_queue', ['attempts' => $attempts, 'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'error' => mb_substr($e->getMessage(), 0, 250), 'processed_at' => gmdate('Y-m-d H:i:s.u')], ['id' => (int) $row['id']]);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    ++$failed;
                }
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Puts a failed photo back in the line (after the owner fixed the old shop's address, for example). */
    public function retryFailed(): int
    {
        return $this->db->executeStatement("UPDATE mc_import_image_queue SET status='pending',attempts=0,error=NULL WHERE status='failed'");
    }

    /** @param array<string,mixed> $row */
    private function store(array $row): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nexora-img-');
        if ($tmp === false) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.temp'));
        }
        try {
            $this->download((string) $row['url'], $tmp);
            $upload = new UploadedFile($tmp, 'imported-' . (int) $row['id'], null, UPLOAD_ERR_OK, true);
            $asset = $this->media->upload($upload, (int) $row['store_id']);
            $this->media->attachToProduct((int) $row['product_id'], $asset->assetId, (string) $row['role'], (int) $row['sort_order'], $row['alt_text'] !== null ? (string) $row['alt_text'] : null);
        } finally {
            @unlink($tmp);
        }
    }

    private function download(string $url, string $target): void
    {
        for ($hop = 0; $hop < 4; ++$hop) {
            if (!$this->allowPrivateHosts) {
                $this->policy->assertPublicHttps($url, true);
            }
            $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 15, 'headers' => ['User-Agent' => 'NexoraImporter/1.0', 'Accept' => 'image/*']]);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $location = (string) ($response->getHeaders(false)['location'][0] ?? '');
                if ($location === '') {
                    throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.redirect'));
                }
                $url = $this->absolute($url, $location);
                continue;
            }
            if ($status !== 200) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.response_prefix') . $status);
            }
            $fh = fopen($target, 'wb');
            if ($fh === false) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.temp'));
            }
            $size = 0;
            try {
                foreach ($this->http->stream($response) as $chunk) {
                    $size += strlen($chunk->getContent());
                    if ($size > self::MAX_BYTES) {
                        throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.size'));
                    }
                    fwrite($fh, $chunk->getContent());
                }
            } finally {
                fclose($fh);
            }
            if ($size < 20) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.not_image'));
            }

            return;
        }
        throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.migration.image.redirect_limit'));
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/' . ltrim($location, '/');
    }
}
