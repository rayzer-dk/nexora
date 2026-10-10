<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Notification\Domain\SmsText;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SMS to customers: automatic messages on order events, manual messages from the order page and a test message.
 * Every attempt is written to mc_sms_log. The transport is the SMS sender of the notification dispatcher, so a gateway
 * added by a module (capability provider.notification_sender) is used the same way as the built-in HTTP gateway.
 * An automatic SMS is sent at most once per order and event.
 */
final readonly class SmsService
{
    public const MAX_LENGTH = 640;

    public function __construct(
        private Connection $db,
        private SmsSettings $settings,
        private NotificationDispatcher $dispatcher,
        private StorefrontUiTranslator $translator,
        private bool $envEnabled,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** True when the gateway is switched on in the admin, or by the environment of an installation that never opened the SMS page. */
    public function enabled(int $storeId): bool
    {
        $s = $this->settings->get($storeId);

        return $s['configured'] ? $s['enabled'] : $this->envEnabled;
    }

    public function autoEnabled(int $storeId, string $event): bool
    {
        $s = $this->settings->get($storeId);
        if ($s['configured']) {
            return $s['enabled'] && ($s['auto'][$event] ?? false);
        }

        return $this->envEnabled && $event === 'placed';
    }

    /** @return array{ok:bool,error:string} */
    public function sendManual(int $storeId, ?int $orderId, string $phone, string $text, ?int $adminId): array
    {
        return $this->deliver($storeId, $orderId, $phone, $text, 'manual', '', $adminId);
    }

    /** @return array{ok:bool,error:string} */
    public function sendTest(int $storeId, string $phone, string $text, ?int $adminId): array
    {
        return $this->deliver($storeId, null, $phone, $text, 'test', '', $adminId);
    }

    /** Sends the automatic SMS of an event if it is switched on and was not sent before. Never throws: an SMS outage must not touch an order. */
    public function sendAuto(int $orderId, string $event): void
    {
        try {
            $order = $this->db->fetchAssociative(
                'SELECT o.id,o.store_id,o.order_number,o.customer_phone,o.customer_name,o.total_minor,o.currency,o.locale,
                        (SELECT f.tracking_number FROM mc_fulfillment f WHERE f.order_id=o.id ORDER BY f.id DESC LIMIT 1) AS tracking_number
                 FROM mc_sales_order o WHERE o.id=?',
                [$orderId],
            );
            if (!is_array($order) || !in_array($event, SmsSettings::EVENTS, true) || !$this->autoEnabled((int) $order['store_id'], $event)) {
                return;
            }
            if (trim((string) ($order['customer_phone'] ?? '')) === '') {
                return;
            }
            $sent = $this->db->fetchOne("SELECT 1 FROM mc_sms_log WHERE order_id=? AND event=? AND mode='auto' AND status='sent' LIMIT 1", [$orderId, $event]);
            if ($sent !== false) {
                return;
            }
            $this->deliver((int) $order['store_id'], $orderId, (string) $order['customer_phone'], $this->textFor((int) $order['store_id'], $event, $order), 'auto', $event, null);
        } catch (\Throwable $e) {
            $this->logger?->warning('Automatic SMS failed', ['order' => $orderId, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public function sendAutoByPublicId(string $orderPublicId, string $event): void
    {
        try {
            $id = $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=?', [Uuid::fromString($orderPublicId)->toBinary()]);
        } catch (\Throwable) {
            return;
        }
        if ($id !== false) {
            $this->sendAuto((int) $id, $event);
        }
    }

    /**
     * The text of every automatic SMS for an order, as the customer would get it now (used as ready answers on the order page).
     *
     * @param array<string,mixed> $order
     * @return array<string,string>
     */
    public function previews(int $storeId, array $order): array
    {
        $out = [];
        foreach (SmsSettings::EVENTS as $event) {
            $out[$event] = $this->textFor($storeId, $event, $order);
        }

        return $out;
    }

    /** @param array<string,mixed> $order */
    public function textFor(int $storeId, string $event, array $order): string
    {
        $template = trim($this->settings->get($storeId)['tpl'][$event] ?? '');
        $vars = [
            'order_number' => (string) ($order['order_number'] ?? ''),
            'customer_name' => (string) ($order['customer_name'] ?? ''),
            'total' => number_format(((int) ($order['total_minor'] ?? 0)) / 100, 2, '.', '') . ' ' . (string) ($order['currency'] ?? ''),
            'tracking' => (string) ($order['tracking_number'] ?? ''),
        ];
        $locale = trim((string) ($order['locale'] ?? '')) ?: 'en-US';
        $vars['tracking_line'] = $vars['tracking'] !== '' ? $this->translator->translate('sms_tracking_line', $locale, ['number' => $vars['tracking']]) : '';
        if ($template === '') {
            $template = $this->translator->translate('sms_order_' . $event . '_tpl', $locale);
        }
        $text = strtr($template, array_combine(array_map(static fn (string $k): string => '{' . $k . '}', array_keys($vars)), array_values($vars)));

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    /** @return list<array<string,mixed>> newest first */
    public function log(int $storeId, ?int $orderId = null, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            if ($orderId !== null) {
                return $this->db->fetchAllAssociative('SELECT * FROM mc_sms_log WHERE store_id=? AND order_id=? ORDER BY id DESC LIMIT ' . $limit, [$storeId, $orderId]);
            }

            return $this->db->fetchAllAssociative('SELECT * FROM mc_sms_log WHERE store_id=? ORDER BY id DESC LIMIT ' . $limit, [$storeId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Sent / failed messages and their parts over the last days, plus a count of failures that can still be retried. @return array{sent:int,failed:int,parts:int,retryable:int} */
    public function stats(int $storeId, int $days = 30): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        try {
            $rows = $this->db->fetchAllAssociative('SELECT status, COUNT(*) n, SUM(segments) parts FROM mc_sms_log WHERE store_id=? AND created_at>=? GROUP BY status', [$storeId, $since]);
        } catch (\Throwable) {
            return ['sent' => 0, 'failed' => 0, 'parts' => 0, 'retryable' => 0];
        }
        $out = ['sent' => 0, 'failed' => 0, 'parts' => 0, 'retryable' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'sent') {
                $out['sent'] = (int) $row['n'];
                $out['parts'] = (int) $row['parts'];
            } elseif ($row['status'] === 'failed') {
                $out['failed'] = (int) $row['n'];
            }
        }
        $out['retryable'] = $out['failed'];

        return $out;
    }

    /** Sends a failed message again; the old row is marked "retried" when the new attempt succeeds. @return array{ok:bool,error:string} */
    public function retry(int $storeId, int $logId, ?int $adminId): array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM mc_sms_log WHERE id=? AND store_id=? AND status='failed'", [$logId, $storeId]);
        if ($row === false) {
            return ['ok' => false, 'error' => 'missing'];
        }
        $result = $this->deliver($storeId, $row['order_id'] !== null ? (int) $row['order_id'] : null, (string) $row['recipient'], (string) $row['body'], (string) $row['mode'], (string) $row['event'], $adminId);
        if ($result['ok']) {
            $this->db->update('mc_sms_log', ['status' => 'retried'], ['id' => $logId]);
        }

        return $result;
    }

    /** Phone as the gateway expects it: digits with a leading +; a Ukrainian national number (0XXXXXXXXX) gets +38. */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (preg_match('/^0\d{9}$/D', $digits) === 1) {
            $digits = '38' . $digits;
        }

        return preg_match('/^[1-9]\d{7,14}$/D', $digits) === 1 ? '+' . $digits : null;
    }

    /** @return array{ok:bool,error:string} */
    private function deliver(int $storeId, ?int $orderId, string $phone, string $text, string $mode, string $event, ?int $adminId): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        $normalized = self::normalizePhone($phone);
        $error = '';
        if ($normalized === null) {
            $error = 'phone';
        } elseif ($text === '' || mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $error = 'text';
        } elseif (!$this->enabled($storeId)) {
            $error = 'disabled';
        } else {
            try {
                $this->dispatcher->send(NotificationChannel::Sms, new NotificationMessage('sms.' . $mode, '', $text, []), $normalized);
            } catch (\Throwable $e) {
                $error = mb_substr(strtok($e->getMessage(), "\n") ?: $e::class, 0, 300, 'UTF-8');
            }
        }
        try {
            $this->db->insert('mc_sms_log', [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'recipient' => $normalized ?? mb_substr(trim($phone), 0, 20, 'UTF-8'),
                'mode' => $mode,
                'event' => $event,
                'body' => $text,
                'segments' => max(1, SmsText::analyze($text)['segments']),
                'status' => $error === '' ? 'sent' : 'failed',
                'error' => $error,
                'admin_id' => $adminId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('SMS log write failed', ['error' => $e->getMessage()]);
        }

        return ['ok' => $error === '', 'error' => $error];
    }
}
