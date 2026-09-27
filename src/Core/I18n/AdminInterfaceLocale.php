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
     * Interface languages that ship a back-office catalog (resources/translations/<locale>/admin.php).
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
        uksort($out, static fn (string $a, string $b): int => $a === self::DEFAULT ? -1 : ($b === self::DEFAULT ? 1 : strcmp($a, $b)));

        return $this->available = $out === [] ? [self::DEFAULT => $names[self::DEFAULT]] : $out;
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

        return isset($this->available()[$candidate]) ? $candidate : self::DEFAULT;
    }

    public function remember(Request $request, ?int $adminUserId, string $locale): string
    {
        $locale = isset($this->available()[$locale]) ? $locale : self::DEFAULT;
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $locale);
        }
        if ($adminUserId !== null) {
            $this->db->executeStatement('UPDATE mc_admin_user SET ui_locale=? WHERE id=?', [$locale, $adminUserId]);
        }

        return $locale;
    }
}
