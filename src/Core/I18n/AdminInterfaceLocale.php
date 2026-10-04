<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Language of the back office UI. Deliberately separate from:
 *  - the storefront language (visitor cookie / store locales), and
 *  - the content locale an administrator is editing (admin context switcher).
 * Resolution: session -> administrator preference -> uk-UA.
 */
final class AdminInterfaceLocale
{
    public const DEFAULT = 'uk-UA';
    public const SESSION_KEY = 'admin_ui.locale';
    public const REQUEST_ATTRIBUTE = '_ui_locale';

    /** @var array<string,string>|null */
    private ?array $available = null;

    public function __construct(private readonly string $projectDir, private readonly Connection $db)
    {
    }

    /**
     * Interface languages that ship a back-office catalog (resources/translations/<locale>/admin.php) or have an admin language pack.
     *
     * @return array<string,string> code => native name
     */
    public function available(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $names = ['uk-UA' => 'Українська', 'en-US' => 'English', 'en-GB' => 'English (UK)', 'pl-PL' => 'Polski', 'de-DE' => 'Deutsch', 'da-DK' => 'Dansk', 'cs-CZ' => 'Čeština', 'ro-RO' => 'Română'];
        $out = [];
        foreach (glob(rtrim($this->projectDir, '/') . '/resources/translations/*/admin.php') ?: [] as $file) {
            $code = basename(dirname($file));
            $out[$code] = $names[$code] ?? $code;
        }
        // Languages added by the store owner with an admin language pack (var/translations/<locale>/admin.json).
        foreach (glob(rtrim($this->projectDir, '/') . '/var/translations/*/admin.json') ?: [] as $file) {
            $code = basename(dirname($file));
            if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $code) === 1) {
                $out[$code] ??= $names[$code] ?? $code;
            }
        }
        uksort($out, static fn (string $a, string $b): int => $a === self::DEFAULT ? -1 : ($b === self::DEFAULT ? 1 : strcmp($a, $b)));

        return $this->available = $out === [] ? [self::DEFAULT => $names[self::DEFAULT]] : $out;
    }

    /**
     * Language of an administrator who has not chosen one: the site setting (System → Languages and currencies), else the
     * language of the store when the admin speaks it, else English, else the base language.
     */
    public function siteDefault(): string
    {
        $available = $this->available();
        try {
            $configured = (string) $this->db->fetchOne("SELECT setting_value FROM mc_system_setting WHERE setting_key='admin.default_locale'");
        } catch (\Throwable) {
            $configured = '';
        }
        if (isset($available[$configured])) {
            return $configured;
        }
        try {
            $store = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store ORDER BY id LIMIT 1');
        } catch (\Throwable) {
            $store = '';
        }
        if (isset($available[$store])) {
            return $store;
        }

        return isset($available['en-US']) ? 'en-US' : self::DEFAULT;
    }

    public function resolve(Request $request, ?int $adminUserId): string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $candidate = $session !== null ? (string) $session->get(self::SESSION_KEY, '') : '';
        if ($candidate === '' && $adminUserId !== null) {
            try {
                $candidate = (string) ($this->db->fetchOne('SELECT ui_locale FROM mc_admin_user WHERE id=?', [$adminUserId]) ?: '');
            } catch (\Throwable) {
                $candidate = '';
            }
            if ($candidate !== '' && $session !== null) {
                $session->set(self::SESSION_KEY, $candidate);
            }
        }

        return isset($this->available()[$candidate]) ? $candidate : $this->siteDefault();
    }

    public function remember(Request $request, ?int $adminUserId, string $locale): string
    {
        $locale = isset($this->available()[$locale]) ? $locale : $this->siteDefault();
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $locale);
        }
        if ($adminUserId !== null) {
            $this->db->executeStatement('UPDATE mc_admin_user SET ui_locale=? WHERE id=?', [$locale, $adminUserId]);
        }

        return $locale;
    }
}
