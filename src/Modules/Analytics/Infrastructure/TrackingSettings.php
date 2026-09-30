<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Infrastructure;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Client-side measurement tags (Google Analytics 4, Google Tag Manager, Meta Pixel). Only validated IDs are stored —
 * never free-form script — and the storefront loads each tag only after the matching cookie consent category.
 */
final readonly class TrackingSettings
{
    private const CSP_GOOGLE = [
        'script' => ['https://www.googletagmanager.com'],
        'connect' => ['https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://stats.g.doubleclick.net'],
        'frame' => ['https://www.googletagmanager.com'],
    ];
    private const CSP_META = [
        'script' => ['https://connect.facebook.net'],
        'connect' => ['https://www.facebook.com', 'https://connect.facebook.net'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{ga4_id:string,gtm_id:string,meta_pixel_id:string} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT ga4_id,gtm_id,meta_pixel_id FROM mc_tracking_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }

        return is_array($row)
            ? ['ga4_id' => (string) $row['ga4_id'], 'gtm_id' => (string) $row['gtm_id'], 'meta_pixel_id' => (string) $row['meta_pixel_id']]
            : ['ga4_id' => '', 'gtm_id' => '', 'meta_pixel_id' => ''];
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $ga4 = strtoupper(trim((string) ($input['ga4_id'] ?? '')));
        $gtm = strtoupper(trim((string) ($input['gtm_id'] ?? '')));
        $pixel = trim((string) ($input['meta_pixel_id'] ?? ''));
        if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]{6,14}$/', $ga4) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.ga4'));
        }
        if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]{4,10}$/', $gtm) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.gtm'));
        }
        if ($pixel !== '' && preg_match('/^[0-9]{8,20}$/', $pixel) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.pixel'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_tracking_settings (store_id,ga4_id,gtm_id,meta_pixel_id,updated_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE ga4_id=VALUES(ga4_id),gtm_id=VALUES(gtm_id),meta_pixel_id=VALUES(meta_pixel_id),updated_at=VALUES(updated_at)',
            [$storeId, $ga4, $gtm, $pixel, $now],
        );
    }

    /**
     * @return array{ga4:string,gtm:string,pixel:string,csp:array<string,list<string>>}|null
     */
    public function storefront(int $storeId): ?array
    {
        $s = $this->get($storeId);
        if ($s['ga4_id'] === '' && $s['gtm_id'] === '' && $s['meta_pixel_id'] === '') {
            return null;
        }
        $csp = [];
        if ($s['ga4_id'] !== '' || $s['gtm_id'] !== '') {
            $csp = self::CSP_GOOGLE;
        }
        if ($s['meta_pixel_id'] !== '') {
            foreach (self::CSP_META as $k => $v) {
                $csp[$k] = [...($csp[$k] ?? []), ...$v];
            }
        }

        return ['ga4' => $s['ga4_id'], 'gtm' => $s['gtm_id'], 'pixel' => $s['meta_pixel_id'], 'csp' => $csp];
    }
}
