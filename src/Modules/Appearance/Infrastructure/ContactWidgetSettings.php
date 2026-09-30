<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/** Floating contact buttons (call-back, write, call, Viber, Messenger, Telegram) shown on every storefront page. */
final readonly class ContactWidgetSettings
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string,mixed> raw settings as stored (for the admin form) */
    public function get(int $storeId): array
    {
        $defaults = ['enabled' => false, 'position' => 'right', 'callback_enabled' => true, 'write_enabled' => true, 'phone' => '', 'email' => '', 'viber' => '', 'messenger' => '', 'telegram' => ''];
        try {
            $row = $this->db->fetchAssociative('SELECT enabled,position,callback_enabled,write_enabled,phone,email,viber,messenger,telegram FROM mc_contact_widget WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            return $defaults;
        }
        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) $row['enabled'],
            'position' => $row['position'] === 'left' ? 'left' : 'right',
            'callback_enabled' => (bool) $row['callback_enabled'],
            'write_enabled' => (bool) $row['write_enabled'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'viber' => (string) $row['viber'],
            'messenger' => (string) $row['messenger'],
            'telegram' => (string) $row['telegram'],
        ];
    }

    /**
     * Storefront view: ready-to-render actions, or null when the widget is off or has nothing to show.
     *
     * @return array{position:string,actions:list<array{code:string,href:string,external:bool}>,callback:bool}|null
     */
    public function storefront(int $storeId): ?array
    {
        $s = $this->get($storeId);
        if (!$s['enabled']) {
            return null;
        }
        $phone = (string) $s['phone'];
        if ($phone === '') {
            try {
                $phone = (string) $this->db->fetchOne('SELECT phone FROM mc_store_profile WHERE store_id=?', [$storeId]);
            } catch (\Throwable) {
                $phone = '';
            }
        }
        $actions = [];
        if ($s['write_enabled']) {
            $mail = (string) $s['email'];
            $actions[] = ['code' => 'write', 'href' => $mail !== '' ? 'mailto:' . $mail : '/contact#contact-form', 'external' => false];
        }
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if ($digits !== '') {
            $actions[] = ['code' => 'call', 'href' => 'tel:' . $digits, 'external' => false];
        }
        if ($s['viber'] !== '') {
            $actions[] = ['code' => 'viber', 'href' => 'viber://chat?number=' . rawurlencode('+' . ltrim((string) $s['viber'], '+')), 'external' => true];
        }
        if ($s['messenger'] !== '') {
            $actions[] = ['code' => 'messenger', 'href' => 'https://m.me/' . rawurlencode((string) $s['messenger']), 'external' => true];
        }
        if ($s['telegram'] !== '') {
            $actions[] = ['code' => 'telegram', 'href' => 'https://t.me/' . rawurlencode((string) $s['telegram']), 'external' => true];
        }
        if ($actions === [] && !$s['callback_enabled']) {
            return null;
        }

        return ['position' => (string) $s['position'], 'actions' => $actions, 'callback' => (bool) $s['callback_enabled']];
    }

    /** @param array<string,mixed> $in */
    public function save(int $storeId, array $in): void
    {
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^\+?[0-9 ()\-]{5,32}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.phone'));
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.email'));
        }
        $viber = preg_replace('/[^0-9]/', '', (string) ($in['viber'] ?? '')) ?? '';
        if ($viber !== '' && (strlen($viber) < 7 || strlen($viber) > 15)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.viber'));
        }
        $messenger = self::handle((string) ($in['messenger'] ?? ''), ['m.me', 'facebook.com', 'www.facebook.com', 'fb.me']);
        $telegram = self::handle((string) ($in['telegram'] ?? ''), ['t.me', 'telegram.me']);
        if ($messenger === null) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.messenger'));
        }
        if ($telegram === null) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.cw.error.telegram'));
        }
        $row = [
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'position' => (string) ($in['position'] ?? 'right') === 'left' ? 'left' : 'right',
            'callback_enabled' => !empty($in['callback_enabled']) ? 1 : 0,
            'write_enabled' => !empty($in['write_enabled']) ? 1 : 0,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'viber' => $viber !== '' ? $viber : null,
            'messenger' => $messenger !== '' ? $messenger : null,
            'telegram' => $telegram !== '' ? $telegram : null,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ];
        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_contact_widget WHERE store_id=?', [$storeId]) > 0;
        if ($exists) {
            $this->db->update('mc_contact_widget', $row, ['store_id' => $storeId]);
        } else {
            $this->db->insert('mc_contact_widget', ['store_id' => $storeId] + $row);
        }
    }

    /** Accepts "name", "@name" or a full URL on an allowed host; returns the bare handle, '' when empty, null when invalid. @param list<string> $hosts */
    private static function handle(string $value, array $hosts): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            if (!is_array($parts) || !in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true)) {
                return null;
            }
            $value = trim((string) ($parts['path'] ?? ''), '/');
        }
        $value = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $value) === 1 ? $value : null;
    }
}
