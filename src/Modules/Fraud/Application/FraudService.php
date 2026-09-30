<?php

declare(strict_types=1);

namespace Commerce\Modules\Fraud\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Rule-based order risk scoring and a per-store blocklist.
 * Deterministic and explainable: every point of score has a reason code. Orders are flagged for
 * review, never silently dropped; only blocklist hits are rejected at checkout.
 */
final class FraudService
{
    public const KINDS = ['email', 'domain', 'phone', 'ip'];
    public const DEFAULTS = ['enabled' => true, 'review_score' => 40, 'high_score' => 80, 'velocity_limit' => 3, 'high_value_minor' => 5000000, 'device_confirm' => false];
    private const DISPOSABLE = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{enabled:bool,review_score:int,high_score:int,velocity_limit:int,high_value_minor:int,device_confirm:bool} */
    public function settings(int $storeId): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM mc_store_security_settings WHERE store_id=?', [$storeId]);
        if (!is_array($row)) {
            return self::DEFAULTS;
        }

        return [
            'enabled' => (bool) $row['fraud_enabled'],
            'review_score' => (int) $row['fraud_review_score'],
            'high_score' => (int) $row['fraud_high_score'],
            'velocity_limit' => (int) $row['fraud_velocity_limit'],
            'high_value_minor' => (int) $row['fraud_high_value_minor'],
            'device_confirm' => (bool) $row['device_confirm_enabled'],
        ];
    }

    /** @param array<string,mixed> $input */
    public function saveSettings(int $storeId, array $input): void
    {
        $review = max(10, min(99, (int) ($input['review_score'] ?? 40)));
        $high = max($review + 1, min(100, (int) ($input['high_score'] ?? 80)));
        $this->db->executeStatement(
            'INSERT INTO mc_store_security_settings (store_id,fraud_enabled,fraud_review_score,fraud_high_score,fraud_velocity_limit,fraud_high_value_minor,device_confirm_enabled,updated_at)'
            . ' VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE fraud_enabled=VALUES(fraud_enabled),fraud_review_score=VALUES(fraud_review_score),fraud_high_score=VALUES(fraud_high_score),'
            . 'fraud_velocity_limit=VALUES(fraud_velocity_limit),fraud_high_value_minor=VALUES(fraud_high_value_minor),device_confirm_enabled=VALUES(device_confirm_enabled),updated_at=VALUES(updated_at)',
            [
                $storeId,
                empty($input['enabled']) ? 0 : 1,
                $review,
                $high,
                max(1, min(50, (int) ($input['velocity_limit'] ?? 3))),
                max(0, min(9_000_000_000, (int) round(((float) ($input['high_value'] ?? 50000)) * 100))),
                empty($input['device_confirm']) ? 0 : 1,
            ],
        );
    }

    /** Rejects checkout for blocklisted contacts. Generic message: the reason is never disclosed. */
    public function preflight(int $storeId, string $email, string $phone, ?string $ip): void
    {
        if (!$this->settings($storeId)['enabled']) {
            return;
        }
        $candidates = [];
        $email = mb_strtolower(trim($email));
        if ($email !== '') {
            $candidates[] = ['email', $email];
            $at = strrpos($email, '@');
            if ($at !== false) {
                $candidates[] = ['domain', substr($email, $at + 1)];
            }
        }
        $digits = self::normalizeValue('phone', $phone);
        if ($digits !== '') {
            $candidates[] = ['phone', $digits];
        }
        if ($ip !== null && $ip !== '') {
            $candidates[] = ['ip', $ip];
        }
        foreach ($candidates as [$kind, $value]) {
            if ($this->db->fetchOne('SELECT 1 FROM mc_fraud_blocklist WHERE store_id=? AND kind=? AND value=? LIMIT 1', [$storeId, $kind, $value]) !== false) {
                throw new \DomainException(CanonicalUiText::get('checkout.error.fraud_blocked'));
            }
        }
    }

    /** Scores a freshly placed order and stores the result. Idempotent per order. */
    public function assess(int $storeId, string $orderPublicId, ?string $ip): void
    {
        $settings = $this->settings($storeId);
        if (!$settings['enabled']) {
            return;
        }
        $order = $this->db->fetchAssociative(
            'SELECT o.id,o.customer_id,o.total_minor,o.customer_email_normalized email,o.customer_phone phone,p.provider_code FROM mc_sales_order o LEFT JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? AND o.store_id=? ORDER BY p.id DESC LIMIT 1',
            [Uuid::fromString($orderPublicId)->toBinary(), $storeId],
        );
        if (!is_array($order)) {
            return;
        }
        $orderId = (int) $order['id'];
        $ipHash = ($ip !== null && $ip !== '') ? hash('sha256', $ip, true) : null;
        $email = (string) ($order['email'] ?? '');
        $phone = self::normalizeValue('phone', (string) ($order['phone'] ?? ''));

        $signals = [
            'ip_orders_1h' => $ipHash === null ? 0 : (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_order_fraud WHERE ip_hash=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR) AND order_id<>?', [$ipHash, $orderId]),
            'ip_failed_24h' => $ipHash === null ? 0 : (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_order_fraud f JOIN mc_sales_order so ON so.id=f.order_id WHERE f.ip_hash=? AND f.created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR) AND so.payment_status IN ('failed','expired') AND f.order_id<>?", [$ipHash, $orderId]),
            'email_orders_24h' => $email === '' ? 0 : (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND customer_email_normalized=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR) AND id<>?', [$storeId, $email, $orderId]),
            'phone_emails_7d' => $phone === '' ? 0 : (int) $this->db->fetchOne("SELECT COUNT(DISTINCT customer_email_normalized) FROM mc_sales_order WHERE store_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(customer_phone,'+',''),' ',''),'-',''),'(','')=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)", [$storeId, $phone]),
            'disposable_email' => $email !== '' && in_array(substr(strrchr($email, '@') ?: '', 1), self::DISPOSABLE, true),
            'total_minor' => (int) $order['total_minor'],
            'guest' => $order['customer_id'] === null,
            'cod' => (string) ($order['provider_code'] ?? '') === 'cash_on_delivery',
        ];
        $result = self::evaluate($signals, $settings);

        $this->db->executeStatement(
            'INSERT IGNORE INTO mc_order_fraud (order_id,store_id,score,level,reasons,ip_hash,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$orderId, $storeId, $result['score'], $result['level'], json_encode($result['reasons'], JSON_THROW_ON_ERROR), $ipHash],
        );
        if ($result['level'] !== 'low') {
            $next = (int) $this->db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM mc_order_event WHERE order_id=?', [$orderId]);
            $this->db->insert('mc_order_event', [
                'order_id' => $orderId, 'sequence_no' => $next, 'event_type' => 'fraud.flagged',
                'payload' => json_encode(['score' => $result['score'], 'level' => $result['level'], 'reasons' => $result['reasons']], JSON_THROW_ON_ERROR),
                'actor_type' => 'system', 'actor_subject' => 'fraud', 'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $s
     * @param array{review_score:int,high_score:int,velocity_limit:int,high_value_minor:int}&array<string,mixed> $settings
     * @return array{score:int,level:string,reasons:list<string>}
     */
    public static function evaluate(array $s, array $settings): array
    {
        $score = 0;
        $reasons = [];
        $add = static function (int $points, string $code) use (&$score, &$reasons): void {
            $score += $points;
            $reasons[] = $code;
        };
        $limit = max(1, (int) $settings['velocity_limit']);
        if ((int) ($s['ip_orders_1h'] ?? 0) >= $limit) {
            $add(30, 'velocity_ip');
        }
        if ((int) ($s['email_orders_24h'] ?? 0) >= $limit) {
            $add(25, 'velocity_email');
        }
        if ((int) ($s['ip_failed_24h'] ?? 0) >= 2) {
            $add(30, 'failed_payments');
        }
        if ((int) ($s['phone_emails_7d'] ?? 0) >= 3) {
            $add(20, 'phone_many_emails');
        }
        if (!empty($s['disposable_email'])) {
            $add(25, 'disposable_email');
        }
        $highValue = (int) $settings['high_value_minor'];
        if ($highValue > 0 && (int) ($s['total_minor'] ?? 0) >= $highValue) {
            $add(20, 'high_value');
            if (!empty($s['guest']) && !empty($s['cod'])) {
                $add(15, 'high_value_guest_cod');
            }
        }
        $score = min(100, $score);
        $level = $score >= (int) $settings['high_score'] ? 'high' : ($score >= (int) $settings['review_score'] ? 'review' : 'low');

        return ['score' => $score, 'level' => $level, 'reasons' => $reasons];
    }

    public static function normalizeValue(string $kind, string $value): string
    {
        $value = trim($value);

        return match ($kind) {
            'email', 'domain' => mb_strtolower(ltrim($value, '@')),
            'phone' => (string) preg_replace('/\D+/', '', $value),
            default => $value,
        };
    }

    /** @return list<array<string,mixed>> */
    public function blocklist(int $storeId): array
    {
        return $this->db->fetchAllAssociative('SELECT id,kind,value,note,created_at FROM mc_fraud_blocklist WHERE store_id=? ORDER BY id DESC LIMIT 500', [$storeId]);
    }

    public function addBlock(int $storeId, string $kind, string $value, string $note = ''): void
    {
        $value = self::normalizeValue($kind, $value);
        if (!in_array($kind, self::KINDS, true) || $value === '' || mb_strlen($value) > 190) {
            throw new \InvalidArgumentException('fraud_block_invalid');
        }
        if ($kind === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('fraud_block_invalid');
        }
        if ($kind === 'ip' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('fraud_block_invalid');
        }
        try {
            $this->db->insert('mc_fraud_blocklist', ['store_id' => $storeId, 'kind' => $kind, 'value' => $value, 'note' => $note !== '' ? mb_substr($note, 0, 190) : null, 'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
        } catch (UniqueConstraintViolationException) {
            // Already listed: idempotent.
        }
    }

    public function removeBlock(int $storeId, int $id): void
    {
        $this->db->delete('mc_fraud_blocklist', ['store_id' => $storeId, 'id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function review(int $storeId, int $limit = 100): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT f.order_id,f.score,f.level,f.reasons,f.decision,f.created_at,o.public_id,o.order_number,o.total_minor,o.currency,o.customer_email_normalized email,o.customer_phone phone,o.status,o.payment_status
             FROM mc_order_fraud f JOIN mc_sales_order o ON o.id=f.order_id
             WHERE f.store_id=? AND f.level<>'low' ORDER BY (f.decision IS NULL) DESC,f.created_at DESC LIMIT " . max(1, min(500, $limit)),
            [$storeId],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $decoded = json_decode((string) $row['reasons'], true);
            $row['reasons'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);

        return $rows;
    }

    /** @param 'clear'|'fraud' $decision */
    public function decide(int $storeId, int $orderId, string $decision, string $actor, bool $blockContacts): void
    {
        if (!in_array($decision, ['clear', 'fraud'], true)) {
            throw new \InvalidArgumentException('fraud_decision_invalid');
        }
        $updated = $this->db->executeStatement(
            'UPDATE mc_order_fraud SET decision=?,decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE order_id=? AND store_id=?',
            [$decision, mb_substr($actor, 0, 190), $orderId, $storeId],
        );
        if ($updated === 0 || $decision !== 'fraud' || !$blockContacts) {
            return;
        }
        $order = $this->db->fetchAssociative('SELECT customer_email_normalized email,customer_phone phone FROM mc_sales_order WHERE id=? AND store_id=?', [$orderId, $storeId]);
        if (is_array($order)) {
            foreach (['email' => (string) ($order['email'] ?? ''), 'phone' => (string) ($order['phone'] ?? '')] as $kind => $value) {
                try {
                    if (trim($value) !== '') {
                        $this->addBlock($storeId, $kind, $value, 'fraud');
                    }
                } catch (\InvalidArgumentException) {
                }
            }
        }
    }
}
