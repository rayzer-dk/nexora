<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/**
 * The SMS gateway and the automatic SMS of a store, edited in the admin. The token is encrypted at rest. A store that
 * never saved the page keeps working from the SMS_* environment variables (only the "order placed" SMS is automatic then).
 */
final readonly class SmsSettings
{
    /** json: any gateway that takes {to, from, message}; smsfly: the built-in SMS-fly driver. */
    public const DRIVERS = ['json', 'smsfly'];
    public const EVENTS = ['placed', 'shipped', 'ready', 'cancelled'];
    private const CTX = 'notification.sms_token';

    public function __construct(private Connection $db, private SecretVault $vault)
    {
    }

    /** @return array{configured:bool,enabled:bool,driver:string,endpoint:string,token:string,has_token:bool,sender:string,auto:array<string,bool>,tpl:array<string,string>} */
    public function get(int $storeId): array
    {
        return $this->hydrate($this->row($storeId));
    }

    /** Settings that are switched on, from the first store that has any (the shop sends from one gateway). */
    public function active(): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM mc_sms_settings WHERE enabled=1 ORDER BY store_id LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $current = $this->get($storeId);
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '' && (!str_starts_with($endpoint, 'https://') || filter_var($endpoint, FILTER_VALIDATE_URL) === false || strlen($endpoint) > 500)) {
            throw new \InvalidArgumentException('endpoint');
        }
        $sender = trim((string) ($input['sender'] ?? ''));
        if ($sender !== '' && preg_match('/^[A-Za-z0-9 ._+-]{1,32}$/D', $sender) !== 1) {
            throw new \InvalidArgumentException('sender');
        }
        $token = trim((string) ($input['token'] ?? ''));
        $token = $token !== '' ? $token : $current['token'];
        if (strlen($token) > 2000) {
            throw new \InvalidArgumentException('token');
        }
        $driver = (string) ($input['driver'] ?? 'json');
        if (!in_array($driver, self::DRIVERS, true)) {
            $driver = 'json';
        }
        $row = [
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'driver' => $driver,
            'endpoint' => $endpoint,
            'token_enc' => $token !== '' ? $this->vault->encrypt($token, self::CTX) : null,
            'sender' => $sender,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        foreach (self::EVENTS as $event) {
            $row['auto_' . $event] = !empty($input['auto_' . $event]) ? 1 : 0;
            $text = trim(str_replace("\r\n", "\n", (string) ($input['tpl_' . $event] ?? '')));
            $row['tpl_' . $event] = mb_substr(strip_tags($text), 0, 500, 'UTF-8');
        }
        if ($this->row($storeId) !== null) {
            $this->db->update('mc_sms_settings', $row, ['store_id' => $storeId]);
        } else {
            $this->db->insert('mc_sms_settings', ['store_id' => $storeId] + $row);
        }
    }

    /** @return array<string,mixed>|null */
    private function row(int $storeId): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM mc_sms_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): array
    {
        $token = '';
        if (is_array($row) && (string) ($row['token_enc'] ?? '') !== '') {
            try {
                $token = $this->vault->decrypt((string) $row['token_enc'], self::CTX);
            } catch (\Throwable) {
                $token = '';
            }
        }
        $auto = [];
        $tpl = [];
        foreach (self::EVENTS as $event) {
            $auto[$event] = is_array($row) && (int) $row['auto_' . $event] === 1;
            $tpl[$event] = is_array($row) ? (string) $row['tpl_' . $event] : '';
        }

        return [
            'configured' => is_array($row),
            'enabled' => is_array($row) && (int) $row['enabled'] === 1,
            'driver' => is_array($row) && in_array((string) ($row['driver'] ?? ''), self::DRIVERS, true) ? (string) $row['driver'] : 'json',
            'endpoint' => is_array($row) ? (string) $row['endpoint'] : '',
            'token' => $token,
            'has_token' => $token !== '',
            'sender' => is_array($row) ? (string) $row['sender'] : '',
            'auto' => $auto,
            'tpl' => $tpl,
        ];
    }
}
