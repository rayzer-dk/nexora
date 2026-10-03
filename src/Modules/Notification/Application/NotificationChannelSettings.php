<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/**
 * SMTP server and the order-alert Telegram bot, edited in the admin. Passwords and tokens are encrypted at rest.
 * Values left empty here fall back to the MAILER_DSN / TELEGRAM_* environment variables of the installation.
 */
final readonly class NotificationChannelSettings
{
    private const CTX_SMTP = 'notification.smtp_password';
    private const CTX_TG = 'notification.telegram_token';

    public function __construct(private Connection $db, private SecretVault $vault)
    {
    }

    /** @return array{smtp_enabled:bool,smtp_host:string,smtp_port:int,smtp_encryption:string,smtp_user:string,smtp_pass:string,has_smtp_pass:bool,from_address:string,from_name:string,tg_enabled:bool,tg_token:string,has_tg_token:bool,tg_chat_id:string} */
    public function get(int $storeId): array
    {
        return $this->hydrate($this->row($storeId));
    }

    /** The settings that are switched on, from the first store that has any (the shop sends from one place). */
    public function active(): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM mc_notification_channel_settings WHERE smtp_enabled=1 OR tg_enabled=1 ORDER BY store_id LIMIT 1');
        } catch (\Throwable) {
            $row = false;
        }

        return $this->hydrate(is_array($row) ? $row : null);
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $cur = $this->get($storeId);
        $host = trim((string) ($input['smtp_host'] ?? ''));
        if ($host !== '' && !preg_match('/^[A-Za-z0-9._-]{1,190}$/D', $host)) {
            throw new \InvalidArgumentException('host');
        }
        $port = (int) ($input['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port');
        }
        $enc = (string) ($input['smtp_encryption'] ?? 'tls');
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
            $enc = 'tls';
        }
        $from = trim((string) ($input['from_address'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('from');
        }
        $token = trim((string) ($input['tg_token'] ?? ''));
        if ($token !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new \InvalidArgumentException('token');
        }
        $chat = trim((string) ($input['tg_chat_id'] ?? ''));
        if ($chat !== '' && !preg_match('/^(-?\d{5,20}|@[A-Za-z0-9_]{4,64})$/D', $chat)) {
            throw new \InvalidArgumentException('chat');
        }
        $pass = (string) ($input['smtp_pass'] ?? '');
        $pass = $pass !== '' ? $pass : $cur['smtp_pass'];
        $token = $token !== '' ? $token : $cur['tg_token'];
        $row = [
            'smtp_enabled' => !empty($input['smtp_enabled']) ? 1 : 0,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $enc,
            'smtp_user' => mb_substr(trim((string) ($input['smtp_user'] ?? '')), 0, 190),
            'smtp_pass_enc' => $pass !== '' ? $this->vault->encrypt($pass, self::CTX_SMTP) : null,
            'from_address' => $from,
            'from_name' => mb_substr(trim(strip_tags((string) ($input['from_name'] ?? ''))), 0, 120),
            'tg_enabled' => !empty($input['tg_enabled']) ? 1 : 0,
            'tg_token_enc' => $token !== '' ? $this->vault->encrypt($token, self::CTX_TG) : null,
            'tg_chat_id' => $chat,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->row($storeId) !== null) {
            $this->db->update('mc_notification_channel_settings', $row, ['store_id' => $storeId]);
        } else {
            $this->db->insert('mc_notification_channel_settings', ['store_id' => $storeId] + $row);
        }
    }

    /** DSN for the configured SMTP server, or null when the admin has not switched it on. */
    public function smtpDsn(array $s): ?string
    {
        if (!$s['smtp_enabled'] || $s['smtp_host'] === '') {
            return null;
        }
        $scheme = $s['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp';
        $auth = $s['smtp_user'] !== '' ? rawurlencode($s['smtp_user']) . ($s['smtp_pass'] !== '' ? ':' . rawurlencode($s['smtp_pass']) : '') . '@' : '';
        $query = $s['smtp_encryption'] === 'none' ? '?auto_tls=false' : '';

        return sprintf('%s://%s%s:%d%s', $scheme, $auth, $s['smtp_host'], $s['smtp_port'], $query);
    }

    /** @return array<string,mixed>|null */
    private function row(int $storeId): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM mc_notification_channel_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): array
    {
        $dec = function (string $col, string $ctx) use ($row): string {
            if (!is_array($row) || (string) ($row[$col] ?? '') === '') {
                return '';
            }
            try {
                return $this->vault->decrypt((string) $row[$col], $ctx);
            } catch (\Throwable) {
                return '';
            }
        };
        $pass = $dec('smtp_pass_enc', self::CTX_SMTP);
        $token = $dec('tg_token_enc', self::CTX_TG);

        return [
            'smtp_enabled' => is_array($row) && (int) $row['smtp_enabled'] === 1,
            'smtp_host' => is_array($row) ? (string) $row['smtp_host'] : '',
            'smtp_port' => is_array($row) ? (int) $row['smtp_port'] : 587,
            'smtp_encryption' => is_array($row) ? (string) $row['smtp_encryption'] : 'tls',
            'smtp_user' => is_array($row) ? (string) $row['smtp_user'] : '',
            'smtp_pass' => $pass,
            'has_smtp_pass' => $pass !== '',
            'from_address' => is_array($row) ? (string) $row['from_address'] : '',
            'from_name' => is_array($row) ? (string) $row['from_name'] : '',
            'tg_enabled' => is_array($row) && (int) $row['tg_enabled'] === 1,
            'tg_token' => $token,
            'has_tg_token' => $token !== '',
            'tg_chat_id' => is_array($row) ? (string) $row['tg_chat_id'] : '',
        ];
    }
}
