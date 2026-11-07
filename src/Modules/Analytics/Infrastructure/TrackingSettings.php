<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Infrastructure;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Client-side measurement tags (Google Analytics 4, Google Tag Manager, Google Ads, Meta Pixel) and the Google Customer Reviews opt-in. Only validated IDs are stored —
 * never free-form script — and the storefront loads each tag only after the matching cookie consent category.
 */
final readonly class TrackingSettings
{
    private const CSP_GOOGLE = [
        'script' => ['https://www.googletagmanager.com'],
        'connect' => ['https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://stats.g.doubleclick.net'],
        'frame' => ['https://www.googletagmanager.com'],
    ];
    private const CSP_ADS = [
        'script' => ['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net'],
        'connect' => ['https://www.google.com', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net', 'https://stats.g.doubleclick.net'],
        'frame' => ['https://td.doubleclick.net'],
    ];
    private const CSP_GCR = [
        'script' => ['https://apis.google.com'],
        'connect' => ['https://apis.google.com'],
        'frame' => ['https://www.google.com', 'https://apis.google.com'],
    ];
    private const CSP_META = [
        'script' => ['https://connect.facebook.net'],
        'connect' => ['https://www.facebook.com', 'https://connect.facebook.net'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{ga4_id:string,gtm_id:string,meta_pixel_id:string,google_ads_id:string,google_ads_purchase_label:string,gcr_merchant_id:string,gcr_delivery_days:int,loyalty_program_label:string} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT ga4_id,gtm_id,meta_pixel_id,google_ads_id,google_ads_purchase_label,gcr_merchant_id,gcr_delivery_days,loyalty_program_label FROM mc_tracking_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }
        $row = is_array($row) ? $row : [];

        return [
            'ga4_id' => (string) ($row['ga4_id'] ?? ''),
            'gtm_id' => (string) ($row['gtm_id'] ?? ''),
            'meta_pixel_id' => (string) ($row['meta_pixel_id'] ?? ''),
            'google_ads_id' => (string) ($row['google_ads_id'] ?? ''),
            'google_ads_purchase_label' => (string) ($row['google_ads_purchase_label'] ?? ''),
            'gcr_merchant_id' => (string) ($row['gcr_merchant_id'] ?? ''),
            'gcr_delivery_days' => (int) ($row['gcr_delivery_days'] ?? 3),
            'loyalty_program_label' => (string) ($row['loyalty_program_label'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $ga4 = strtoupper(trim((string) ($input['ga4_id'] ?? '')));
        $gtm = strtoupper(trim((string) ($input['gtm_id'] ?? '')));
        $pixel = trim((string) ($input['meta_pixel_id'] ?? ''));
        $ads = strtoupper(trim((string) ($input['google_ads_id'] ?? '')));
        $adsLabel = trim((string) ($input['google_ads_purchase_label'] ?? ''));
        $gcr = trim((string) ($input['gcr_merchant_id'] ?? ''));
        $days = (int) ($input['gcr_delivery_days'] ?? 3);
        $loyalty = trim(strip_tags((string) ($input['loyalty_program_label'] ?? '')));
        if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]{6,14}$/', $ga4) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.ga4'));
        }
        if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]{4,10}$/', $gtm) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.gtm'));
        }
        if ($pixel !== '' && preg_match('/^[0-9]{8,20}$/', $pixel) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.pixel'));
        }
        if ($ads !== '' && preg_match('/^AW-[0-9]{6,12}$/', $ads) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.ads'));
        }
        if ($adsLabel !== '' && ($ads === '' || preg_match('/^[A-Za-z0-9_-]{6,40}$/', $adsLabel) !== 1)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.ads_label'));
        }
        if ($gcr !== '' && preg_match('/^[0-9]{5,15}$/', $gcr) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.gcr'));
        }
        if ($days < 0 || $days > 60) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.gcr_days'));
        }
        if (mb_strlen($loyalty) > 60) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.tracking.error.loyalty'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_tracking_settings (store_id,ga4_id,gtm_id,meta_pixel_id,google_ads_id,google_ads_purchase_label,gcr_merchant_id,gcr_delivery_days,loyalty_program_label,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE ga4_id=VALUES(ga4_id),gtm_id=VALUES(gtm_id),meta_pixel_id=VALUES(meta_pixel_id),google_ads_id=VALUES(google_ads_id),google_ads_purchase_label=VALUES(google_ads_purchase_label),gcr_merchant_id=VALUES(gcr_merchant_id),gcr_delivery_days=VALUES(gcr_delivery_days),loyalty_program_label=VALUES(loyalty_program_label),updated_at=VALUES(updated_at)',
            [$storeId, $ga4, $gtm, $pixel, $ads, $adsLabel, $gcr, $days, $loyalty, $now],
        );
    }

    /**
     * @return array{ga4:string,gtm:string,pixel:string,ads:string,ads_label:string,gcr:string,gcr_days:int,csp:array<string,list<string>>}|null
     */
    public function storefront(int $storeId): ?array
    {
        $s = $this->get($storeId);
        if ($s['ga4_id'] === '' && $s['gtm_id'] === '' && $s['meta_pixel_id'] === '' && $s['google_ads_id'] === '' && $s['gcr_merchant_id'] === '') {
            return null;
        }
        $csp = [];
        $sets = [
            [$s['ga4_id'] !== '' || $s['gtm_id'] !== '', self::CSP_GOOGLE],
            [$s['google_ads_id'] !== '', self::CSP_ADS],
            [$s['gcr_merchant_id'] !== '', self::CSP_GCR],
            [$s['meta_pixel_id'] !== '', self::CSP_META],
        ];
        foreach ($sets as [$used, $rules]) {
            if (!$used) {
                continue;
            }
            foreach ($rules as $k => $v) {
                $csp[$k] = array_values(array_unique([...($csp[$k] ?? []), ...$v]));
            }
        }

        return [
            'ga4' => $s['ga4_id'], 'gtm' => $s['gtm_id'], 'pixel' => $s['meta_pixel_id'],
            'ads' => $s['google_ads_id'], 'ads_label' => $s['google_ads_purchase_label'],
            'gcr' => $s['gcr_merchant_id'], 'gcr_days' => $s['gcr_delivery_days'], 'csp' => $csp,
        ];
    }
}
