<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Application;

use Doctrine\DBAL\Connection;

/**
 * The language tabs every multilingual edit form shows: one tab per enabled store language, the default language first,
 * with a completeness marker so the merchant sees at a glance which languages still lack text.
 */
final readonly class ContentLanguageTabs
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array<string,bool> $doneByLocale locale => has the required texts
     * @return list<array{code:string,name:string,short:string,is_default:bool,current:bool,done:bool}>
     */
    public function tabs(int $storeId, string $currentLocale, array $doneByLocale = []): array
    {
        $default = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        $rows = $this->db->fetchAllAssociative(
            'SELECT l.code,l.native_name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.sort_order,l.code',
            [$storeId],
        );
        $tabs = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $tabs[] = [
                'code' => $code,
                'name' => self::plainName((string) $row['native_name'], $code),
                'short' => strtoupper(explode('-', $code)[0]),
                'is_default' => $code === $default,
                'current' => $code === $currentLocale,
                'done' => $doneByLocale[$code] ?? false,
            ];
        }
        usort($tabs, static fn (array $a, array $b): int => (int) $b['is_default'] <=> (int) $a['is_default']);

        return $tabs;
    }

    /** Native names are stored like "Language (Country)"; the tab only needs the language part. */
    public static function plainName(string $native, string $code): string
    {
        $name = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $native));

        return $name !== '' ? $name : $code;
    }
}
