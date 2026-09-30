<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Application;

use Commerce\Core\Store\StoreLocalizationSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Site quality monitor: a fast, read-only self-check with a score. Every check is a status (ok / warn / fail),
 * a number that explains it and a page where the merchant fixes it. Nothing here changes data.
 */
final class QualityMonitor
{
    public const GROUPS = ['security', 'reliability', 'performance', 'content', 'international', 'commerce'];

    public function __construct(
        private readonly Connection $db,
        private readonly StoreLocalizationSettings $localization,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%commerce.app_public_url%')] private readonly string $publicUrl = '',
    ) {
    }

    /**
     * @return array{score:int,level:string,counts:array{ok:int,warn:int,fail:int},groups:array<string,list<array{id:string,status:string,n:int,link:string}>>}
     */
    public function run(int $storeId): array
    {
        $checks = [];
        foreach ([
            fn () => $this->security($storeId),
            fn () => $this->reliability(),
            fn () => $this->performance(),
            fn () => $this->content($storeId),
            fn () => $this->international($storeId),
            fn () => $this->commerce($storeId),
        ] as $i => $group) {
            try {
                foreach ($group() as $check) {
                    $checks[self::GROUPS[$i]][] = $check;
                }
            } catch (\Throwable) {
                // A group that cannot be read (for example during a partial migration) is skipped, never fatal.
            }
        }

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $list) {
            foreach ($list as $check) {
                ++$counts[$check['status']];
            }
        }
        $total = array_sum($counts);
        $score = $total > 0 ? (int) round(($counts['ok'] + $counts['warn'] * 0.5) * 100 / $total) : 0;

        return ['score' => $score, 'level' => $counts['fail'] > 0 || $score < 60 ? 'bad' : ($score < 90 ? 'fair' : 'good'), 'counts' => $counts, 'groups' => $checks];
    }

    /** @return array{id:string,status:string,n:int,link:string} */
    private static function check(string $id, string $status, int $n = 0, string $link = ''): array
    {
        return ['id' => $id, 'status' => $status, 'n' => $n, 'link' => $link];
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function security(int $storeId): array
    {
        $out = [self::check('debug_off', $this->debug ? 'fail' : 'ok', 0, '/admin/system/stability')];
        $https = str_starts_with((string) $this->publicUrl, 'https://');
        $out[] = self::check('https', $https ? 'ok' : 'warn', 0, '/admin/system/site');
        $noMfa = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_admin_user u LEFT JOIN mc_admin_mfa m ON m.admin_id=u.id AND m.enabled_at IS NOT NULL WHERE u.status='active' AND m.admin_id IS NULL");
        $out[] = self::check('admin_mfa', $noMfa === 0 ? 'ok' : 'warn', $noMfa, '/admin/account/security');
        $fraud = (int) $this->db->fetchOne('SELECT fraud_enabled FROM mc_store_security_settings WHERE store_id=?', [$storeId]);
        $out[] = self::check('fraud_enabled', $fraud === 1 ? 'ok' : 'warn', 0, '/admin/system/fraud');
        $captcha = (string) $this->db->fetchOne('SELECT provider FROM mc_captcha_settings WHERE store_id=?', [$storeId]);
        $out[] = self::check('captcha', $captcha !== '' && $captcha !== 'none' ? 'ok' : 'warn', 0, '/admin/system/captcha');

        return $out;
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function reliability(): array
    {
        $out = [];
        $last = $this->db->fetchOne('SELECT MAX(last_finished_at) FROM mc_scheduled_task_state');
        $age = is_string($last) && $last !== '' ? (int) ((time() - (int) strtotime($last . ' UTC')) / 3600) : -1;
        $out[] = self::check('cron_fresh', $age < 0 ? 'fail' : ($age <= 26 ? 'ok' : 'fail'), max(0, $age), '/admin/system/cron');
        $failed = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_notification_outbox WHERE status='failed'");
        $out[] = self::check('outbox_failed', $failed === 0 ? 'ok' : 'warn', $failed, '/admin/commerce/notifications');
        $incidents = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_runtime_incident WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        $out[] = self::check('incidents', $incidents === 0 ? 'ok' : ($incidents > 20 ? 'fail' : 'warn'), $incidents, '/admin/system/stability');
        $free = @disk_free_space($this->projectDir);
        $total = @disk_total_space($this->projectDir);
        if (is_float($free) && is_float($total) && $total > 0) {
            $pct = (int) round($free * 100 / $total);
            $out[] = self::check('disk_free', $pct < 10 ? 'fail' : ($pct < 20 ? 'warn' : 'ok'), $pct, '/admin/system/data');
        }
        $pending = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_integration_sync_queue');
        $out[] = self::check('sync_queue', $pending > 1000 ? 'warn' : 'ok', $pending, '/admin/system/integrations');

        return $out;
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function performance(): array
    {
        $opcache = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
        $memory = ini_get('memory_limit');
        $bytes = $memory === '-1' ? PHP_INT_MAX : self::bytes((string) $memory);
        $dbBytes = (int) $this->db->fetchOne('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()');

        return [
            self::check('opcache', $opcache || PHP_SAPI === 'cli' ? 'ok' : 'warn', 0, '/admin/system/stability'),
            self::check('php_memory', $bytes >= 256 * 1024 * 1024 ? 'ok' : 'warn', (int) ($bytes === PHP_INT_MAX ? 0 : $bytes / 1048576), '/admin/system/stability'),
            self::check('db_size', $dbBytes > 5 * 1073741824 ? 'warn' : 'ok', (int) ($dbBytes / 1048576), '/admin/system/data'),
        ];
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function content(int $storeId): array
    {
        $locale = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        $active = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product WHERE status='active'");
        $noImage = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product p WHERE p.status='active' AND NOT EXISTS (SELECT 1 FROM mc_product_media m WHERE m.product_id=p.id)");
        $noText = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product p WHERE p.status='active' AND NOT EXISTS (SELECT 1 FROM mc_product_translation t WHERE t.product_id=p.id AND t.store_id=? AND t.locale=? AND t.description IS NOT NULL AND t.description<>'')", [$storeId, $locale]);
        $noMeta = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product p WHERE p.status='active' AND NOT EXISTS (SELECT 1 FROM mc_product_translation t WHERE t.product_id=p.id AND t.store_id=? AND t.locale=? AND t.meta_title IS NOT NULL AND t.meta_title<>'')", [$storeId, $locale]);
        $drafts = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_content_entry WHERE store_id=? AND system_key IS NOT NULL AND status='draft'", [$storeId]);
        $profile = $this->db->fetchAssociative('SELECT legal_name,email FROM mc_store_profile WHERE store_id=?', [$storeId]) ?: [];
        $profileOk = trim((string) ($profile['legal_name'] ?? '')) !== '' && trim((string) ($profile['email'] ?? '')) !== '';
        $share = static fn (int $n): string => $active === 0 || $n === 0 ? 'ok' : ($n * 100 / $active > 30 ? 'fail' : 'warn');

        return [
            self::check('product_images', $share($noImage), $noImage, '/admin/catalog/products'),
            self::check('product_texts', $share($noText), $noText, '/admin/catalog/products'),
            self::check('product_meta', $share($noMeta), $noMeta, '/admin/catalog/products'),
            self::check('legal_drafts', $drafts === 0 ? 'ok' : 'warn', $drafts, '/admin/content/pages'),
            self::check('store_profile', $profileOk ? 'ok' : 'warn', 0, '/admin/system/site'),
        ];
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function international(int $storeId): array
    {
        $overview = $this->localization->overview($storeId);
        $low = 0;
        $untranslated = 0;
        $defaultLocale = (string) $overview['default_locale'];
        $products = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product WHERE status='active'");
        foreach ($overview['locales'] as $locale) {
            if (empty($locale['attached']) || (int) ($locale['ui_coverage'] ?? 100) >= 90) {
                if (!empty($locale['attached']) && $locale['code'] !== $defaultLocale && $products > 0) {
                    $untranslated += max(0, $products - (int) $locale['content_count']);
                }
                continue;
            }
            ++$low;
        }
        $expired = 0;
        foreach ($overview['currencies'] as $currency) {
            if (!empty($currency['enabled']) && !empty($currency['auto_convert']) && !$currency['is_default'] && (($currency['rate']['expired'] ?? true) === true)) {
                ++$expired;
            }
        }

        return [
            self::check('ui_coverage', $low === 0 ? 'ok' : 'warn', $low, '/admin/system/localization'),
            self::check('content_translated', $untranslated === 0 ? 'ok' : 'warn', $untranslated, '/admin/system/localization'),
            self::check('rates_fresh', $expired === 0 ? 'ok' : 'fail', $expired, '/admin/system/localization'),
        ];
    }

    /** @return list<array{id:string,status:string,n:int,link:string}> */
    private function commerce(int $storeId): array
    {
        $countries = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_shipping_country WHERE store_id=? AND enabled=1', [$storeId]);
        $rates = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_tax_rate WHERE enabled=1');
        $soldOut = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product p WHERE p.status='active' AND NOT EXISTS (SELECT 1 FROM mc_product_variant v JOIN mc_variant_inventory_item vi ON vi.variant_id=v.id JOIN mc_stock_level s ON s.inventory_item_id=vi.inventory_item_id WHERE v.product_id=p.id AND s.stocked_quantity-s.reserved_quantity>0)");
        $stuck = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_payment WHERE status='awaiting_payment' AND created_at < (UTC_TIMESTAMP() - INTERVAL 2 DAY)");

        return [
            self::check('shipping_configured', $countries > 0 ? 'ok' : 'warn', 0, '/admin/shipments/countries'),
            self::check('tax_rates', $rates > 0 ? 'ok' : 'warn', 0, '/admin/system/store'),
            self::check('sold_out', $soldOut === 0 ? 'ok' : 'warn', $soldOut, '/admin/catalog/products'),
            self::check('stuck_payments', $stuck === 0 ? 'ok' : 'warn', $stuck, '/admin/orders'),
        ];
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
