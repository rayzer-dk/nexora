<?php

declare(strict_types=1);

namespace Commerce\Modules\Push\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/** Per-store Web Push configuration. The VAPID private key is generated here and stored encrypted. */
final readonly class PushSettings
{
    private const CONTEXT = 'push.vapid';

    public function __construct(private Connection $db, private SecretVault $vault)
    {
    }

    /** @return array{enabled:bool,public:string,subject:string,has_keys:bool} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT enabled,vapid_public,vapid_private_enc,subject FROM mc_push_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }
        if (!is_array($row)) {
            return ['enabled' => false, 'public' => '', 'subject' => '', 'has_keys' => false];
        }
        $hasKeys = (string) $row['vapid_public'] !== '' && (string) ($row['vapid_private_enc'] ?? '') !== '';

        return ['enabled' => (bool) $row['enabled'] && $hasKeys, 'public' => (string) $row['vapid_public'], 'subject' => (string) $row['subject'], 'has_keys' => $hasKeys];
    }

    /** @return array{private_pem:string,public_raw:string,subject:string}|null */
    public function signingMaterial(int $storeId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT vapid_public,vapid_private_enc,subject FROM mc_push_settings WHERE store_id=? AND enabled=1', [$storeId]);
        if (!is_array($row) || (string) ($row['vapid_private_enc'] ?? '') === '' || (string) $row['vapid_public'] === '') {
            return null;
        }
        try {
            $pem = $this->vault->decrypt((string) $row['vapid_private_enc'], self::CONTEXT);
        } catch (\Throwable) {
            return null;
        }

        return ['private_pem' => $pem, 'public_raw' => WebPushCrypto::b64uDecode((string) $row['vapid_public']), 'subject' => (string) $row['subject']];
    }

    public function save(int $storeId, bool $enabled, string $subject): void
    {
        $subject = trim($subject);
        $valid = preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/', $subject) === 1 || (preg_match('#^https://[^\s/]+#', $subject) === 1 && strlen($subject) <= 190);
        if (!$valid) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.push.error_subject'));
        }
        $current = $this->get($storeId);
        $now = gmdate('Y-m-d H:i:s.u');
        if (!$current['has_keys']) {
            $this->storeKeys($storeId, $subject, $enabled, $now);

            return;
        }
        $this->db->executeStatement('UPDATE mc_push_settings SET enabled=?,subject=?,updated_at=? WHERE store_id=?', [$enabled ? 1 : 0, mb_substr($subject, 0, 190), $now, $storeId]);
    }

    /** New key pair. Existing subscriptions are bound to the old key and are removed. */
    public function regenerate(int $storeId): void
    {
        $current = $this->get($storeId);
        $this->storeKeys($storeId, $current['subject'] !== '' ? $current['subject'] : 'mailto:admin@localhost.invalid', $current['enabled'], gmdate('Y-m-d H:i:s.u'));
        $this->db->executeStatement('DELETE FROM mc_push_subscription WHERE store_id=?', [$storeId]);
    }

    private function storeKeys(int $storeId, string $subject, bool $enabled, string $now): void
    {
        $pair = WebPushCrypto::generateKeyPair();
        $this->db->executeStatement(
            'INSERT INTO mc_push_settings (store_id,enabled,vapid_public,vapid_private_enc,subject,updated_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),vapid_public=VALUES(vapid_public),vapid_private_enc=VALUES(vapid_private_enc),subject=VALUES(subject),updated_at=VALUES(updated_at)',
            [$storeId, $enabled ? 1 : 0, WebPushCrypto::b64uEncode($pair['public']), $this->vault->encrypt($pair['private_pem'], self::CONTEXT), mb_substr($subject, 0, 190), $now],
        );
    }
}
