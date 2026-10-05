<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;

/** The morning letter for the owner: how yesterday went, what needs attention and what may go wrong soon. */
final class DailyDigestService
{
    private const KEY = 'digest.settings';

    public function __construct(
        private readonly Connection $db,
        private readonly SystemSettingStore $store,
        private readonly NotificationOutbox $outbox,
        private readonly EarlyWarningService $warnings,
        private readonly string $publicUrl,
    ) {
    }

    /** @return array{enabled:bool,hour:int,recipients:list<string>,only_problems:bool,last_sent:string} */
    public function settings(): array
    {
        $s = $this->store->getArray(self::KEY) ?? [];
        $recipients = array_values(array_filter(array_map('trim', (array) ($s['recipients'] ?? [])), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));

        return ['enabled' => (bool) ($s['enabled'] ?? false), 'hour' => max(0, min(23, (int) ($s['hour'] ?? 8))), 'recipients' => $recipients, 'only_problems' => (bool) ($s['only_problems'] ?? false), 'last_sent' => (string) ($s['last_sent'] ?? '')];
    }

    /** @param list<string> $recipients */
    public function save(bool $enabled, int $hour, array $recipients, bool $onlyProblems): void
    {
        $clean = [];
        foreach ($recipients as $e) {
            $e = mb_strtolower(trim($e));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($e) <= 190) {
                $clean[$e] = $e;
            }
        }
        if ($enabled && $clean === []) {
            throw new \DomainException(CanonicalUiText::get('admin.digest.need_recipient'));
        }
        $old = $this->settings();
        $this->store->setArray(self::KEY, ['enabled' => $enabled, 'hour' => max(0, min(23, $hour)), 'recipients' => array_slice(array_values($clean), 0, 10), 'only_problems' => $onlyProblems, 'last_sent' => $old['last_sent']]);
    }

    /** Called every hour by cron: sends once a day after the chosen hour of the shop's time. @return bool whether a letter was queued */
    public function sendIfDue(bool $force = false): bool
    {
        $s = $this->settings();
        if (!$force && (!$s['enabled'] || $s['recipients'] === [])) {
            return false;
        }
        $tz = $this->timezone();
        $now = new \DateTimeImmutable('now', $tz);
        if (!$force && ($now->format('Y-m-d') === $s['last_sent'] || (int) $now->format('G') < $s['hour'])) {
            return false;
        }
        $this->warnings->refreshCertificate();
        $report = $this->build();
        if (!$force && $s['only_problems'] && $report['attention'] === [] && $report['warnings'] === []) {
            $this->markSent($now);

            return false;
        }
        $recipients = $s['recipients'] !== [] ? $s['recipients'] : $this->adminEmails();
        $subject = CanonicalUiText::get('admin.digest.subject', ['store' => $report['store'], 'date' => $report['date']]);
        foreach ($recipients as $email) {
            $this->outbox->enqueue(NotificationChannel::Email, new NotificationMessage('health.digest', $subject, $subject, ['store_name' => $report['store'], 'report' => $report], 'health_digest'), $email, null, 'digest:' . $now->format('Y-m-d') . ':' . sha1($email) . ($force ? ':' . bin2hex(random_bytes(3)) : ''));
        }
        if (!$force) {
            $this->markSent($now);
        }

        return true;
    }

    /** @return array{store:string,date:string,kpi:array<string,mixed>,attention:list<array{label:string,count:int,url:string}>,warnings:list<array{level:string,text:string,url:string}>,waiting:int} */
    public function build(): array
    {
        $store = $this->db->fetchAssociative("SELECT id,name FROM mc_store WHERE status='active' ORDER BY id LIMIT 1") ?: ['id' => 0, 'name' => 'Nexora'];
        $storeId = (int) $store['id'];
        $tz = $this->timezone();
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $yFrom = $today->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $yTo = $today->setTimezone($utc)->format('Y-m-d H:i:s');
        $pFrom = $today->modify('-2 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $day = fn (string $a, string $b): array => $this->db->fetchAssociative("SELECT COUNT(*) orders,COALESCE(SUM(CASE WHEN payment_status IN ('paid','partially_refunded') THEN total_minor ELSE 0 END),0) revenue FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<? AND status NOT IN ('cancelled','expired')", [$storeId, $a, $b]) ?: ['orders' => 0, 'revenue' => 0];
        $y = $day($yFrom, $yTo);
        $p = $day($pFrom, $yFrom);
        $currency = (string) ($this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]) ?: '');
        $sessions = 0;
        try {
            $sessions = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<?', [$storeId, $yFrom, $yTo]);
        } catch (\Throwable) {
        }

        return [
            'store' => (string) $store['name'],
            'date' => $today->modify('-1 day')->format('d.m.Y'),
            'kpi' => ['orders' => (int) $y['orders'], 'orders_prev' => (int) $p['orders'], 'revenue' => number_format(((int) $y['revenue']) / 100, 2, ',', ' ') . ' ' . $currency, 'revenue_prev' => number_format(((int) $p['revenue']) / 100, 2, ',', ' ') . ' ' . $currency, 'sessions' => $sessions],
            'attention' => $this->attention($storeId),
            'warnings' => array_map(static fn (array $w): array => ['level' => $w['level'], 'text' => $w['text'], 'url' => $w['url']], $this->warnings->warnings($storeId)),
            'waiting' => $this->count('SELECT COUNT(DISTINCT CONCAT(product_id,\':\',email_normalized)) FROM mc_stock_notification_request WHERE store_id=? AND status IN (\'pending\',\'active\')', [$storeId]),
        ];
    }

    /** @return list<array{label:string,count:int,url:string}> */
    private function attention(int $storeId): array
    {
        $q = [
            ['admin.dashboard.unpaid_old', "SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND status='awaiting_payment' AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)", [$storeId], '/admin/orders'],
            ['admin.dashboard.returns_open', "SELECT COUNT(*) FROM mc_return_request WHERE store_id=? AND status='requested'", [$storeId], '/admin/customer-experience'],
            ['admin.dashboard.moderation', "SELECT (SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND status='pending')+(SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND status='pending')", [$storeId, $storeId], '/admin/customer-experience'],
            ['admin.dashboard.b2b_pending', "SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND b2b_approval_status='pending'", [$storeId], '/admin/b2b'],
            ['admin.dashboard.leads_unreminded', "SELECT COUNT(*) FROM mc_checkout_lead l JOIN mc_cart c ON c.id=l.cart_id WHERE l.store_id=? AND c.status='active' AND l.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) AND NOT EXISTS(SELECT 1 FROM mc_marketing_automation_delivery d WHERE d.cart_id=c.id)", [$storeId], '/admin/commerce/automation'],
            ['admin.dashboard.zero_searches', "SELECT COUNT(DISTINCT query_hash) FROM mc_search_query_log WHERE store_id=? AND result_count=0 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)", [$storeId], '/admin/analytics'],
            ['admin.dashboard.missing_pages', 'SELECT COUNT(*) FROM mc_not_found_log WHERE hit_count>=3', [], '/admin/system/seo-custom-redirects'],
            ['admin.dashboard.broken_links', 'SELECT COUNT(*) FROM mc_link_check_issue WHERE ignored=0', [], '/admin/system/link-check'],
            ['admin.dashboard.cards_expiring', "SELECT COUNT(*) FROM mc_gift_card WHERE store_id=? AND status='active' AND balance_minor>0 AND expires_at IS NOT NULL AND expires_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 14 DAY)", [$storeId], '/admin/rewards'],
            ['admin.dashboard.cron_failed', "SELECT COUNT(*) FROM mc_scheduled_task_state WHERE last_status='failed'", [], '/admin/system/cron'],
        ];
        $out = [];
        $base = rtrim($this->publicUrl, '/');
        foreach ($q as [$key, $sql, $params, $url]) {
            $n = $this->count($sql, $params);
            if ($n > 0) {
                $out[] = ['label' => CanonicalUiText::get($key), 'count' => $n, 'url' => $base . $url];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function adminEmails(): array
    {
        return array_map('strval', $this->db->fetchFirstColumn("SELECT email FROM mc_admin_user WHERE status='active' ORDER BY id LIMIT 1"));
    }

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) ($this->db->fetchOne("SELECT timezone FROM mc_store WHERE status='active' ORDER BY id LIMIT 1") ?: 'UTC'));
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    private function markSent(\DateTimeImmutable $now): void
    {
        $s = $this->store->getArray(self::KEY) ?? [];
        $s['last_sent'] = $now->format('Y-m-d');
        $this->store->setArray(self::KEY, $s);
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        try {
            return (int) $this->db->fetchOne($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }
}
