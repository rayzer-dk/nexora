<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * The store's glossary for every translation. A "keep" term (a brand, a model name) is swapped for a marker ⟦T1⟧ before the
 * text leaves the shop and put back word for word afterwards, so neither a model nor a translation service can change it.
 * A "fixed" term has one translation per language: a model gets it as an instruction, a translation service gets a marker
 * that comes back as that translation.
 */
final class AiGlossary
{
    public const MAX_TERMS = 300;

    /** @var array<int,list<array{id:int,term:string,mode:'keep'|'fixed',translations:array<string,string>}>> */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{id:int,term:string,mode:'keep'|'fixed',translations:array<string,string>}> */
    public function terms(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id,term,mode,translations_json FROM mc_ai_glossary WHERE store_id=? ORDER BY id', [$storeId]);
        } catch (\Throwable) {
            return []; // the table does not exist before the migration ran
        }
        $out = [];
        foreach ($rows as $row) {
            $translations = json_decode((string) ($row['translations_json'] ?? ''), true);
            $out[] = [
                'id' => (int) $row['id'],
                'term' => (string) $row['term'],
                'mode' => (string) $row['mode'] === 'fixed' ? 'fixed' : 'keep',
                'translations' => is_array($translations) ? array_filter(array_map('strval', $translations), static fn (string $v): bool => $v !== '') : [],
            ];
        }

        return $this->cache[$storeId] = $out;
    }

    /**
     * Replaces the whole glossary of the store. A row without a term is dropped; the same term twice (any letter case) is kept once.
     * A fixed translation of a language the row does not mention at all (a language switched off, so the form did not show it)
     * is kept from the saved glossary; a language sent empty is cleared.
     *
     * @param list<array{term?:mixed,mode?:mixed,translations?:mixed}> $rows
     */
    public function save(int $storeId, array $rows): void
    {
        $saved = [];
        foreach ($this->terms($storeId) as $term) {
            $saved[mb_strtolower($term['term'])] = $term['translations'];
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = mb_substr(trim(strip_tags((string) ($row['term'] ?? ''))), 0, 190);
            $key = mb_strtolower($term);
            if ($term === '' || isset($clean[$key])) {
                continue;
            }
            $translations = [];
            foreach (is_array($row['translations'] ?? null) ? $row['translations'] : [] as $locale => $value) {
                $value = mb_substr(trim(strip_tags((string) $value)), 0, 190);
                if (is_string($locale) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', $locale) === 1 && $value !== '') {
                    $translations[$locale] = $value;
                }
            }
            $sent = is_array($row['translations'] ?? null) ? $row['translations'] : [];
            foreach ($saved[$key] ?? [] as $locale => $value) {
                if (!array_key_exists($locale, $sent)) {
                    $translations[$locale] = $value;
                }
            }
            $clean[$key] = ['term' => $term, 'mode' => ($row['mode'] ?? '') === 'fixed' ? 'fixed' : 'keep', 'translations' => $translations];
            if (count($clean) >= self::MAX_TERMS) {
                break;
            }
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->transactional(function (Connection $db) use ($storeId, $clean, $now): void {
            $db->executeStatement('DELETE FROM mc_ai_glossary WHERE store_id=?', [$storeId]);
            foreach ($clean as $row) {
                $db->insert('mc_ai_glossary', [
                    'store_id' => $storeId, 'term' => $row['term'], 'mode' => $row['mode'],
                    'translations_json' => json_encode((object) $row['translations'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        unset($this->cache[$storeId]);
    }

    /**
     * Swaps glossary terms in the text (never inside HTML tags) for markers ⟦T1⟧, ⟦T2⟧… numbered in reading order.
     * With $withFixed, a term that has a fixed translation for the target language becomes a marker too (for translation services).
     *
     * @return array{0:string,1:array<string,string>} the text to send and marker => text to put back
     */
    public function protect(int $storeId, string $text, string $targetLocale, bool $withFixed = false): array
    {
        $replacements = []; // lower-case term => fixed translation, or null to keep the original words
        foreach ($this->terms($storeId) as $row) {
            if ($row['mode'] === 'keep') {
                $replacements[mb_strtolower($row['term'])] = null;
            } elseif ($withFixed && ($fixed = $this->translationFor($row['translations'], $targetLocale)) !== null) {
                $replacements[mb_strtolower($row['term'])] = $fixed;
            }
        }
        if ($replacements === [] || trim($text) === '') {
            return [$text, []];
        }
        $terms = array_keys($replacements);
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a)); // "Nexora Pro Max" before "Nexora Pro"
        $pattern = '/(?<![\p{L}\p{N}_])(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms)) . ')(?![\p{L}\p{N}_])/iu';

        $map = [];
        $byText = [];
        $parts = preg_split('/(<[^>]*>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                continue;
            }
            $parts[$i] = (string) preg_replace_callback($pattern, static function (array $m) use (&$map, &$byText, $replacements): string {
                $original = $m[1];
                $restore = $replacements[mb_strtolower($original)] ?? $original;
                if (!isset($byText[$restore])) {
                    $byText[$restore] = '⟦T' . (count($map) + 1) . '⟧';
                    $map[$byText[$restore]] = $restore;
                }

                return $byText[$restore];
            }, $part);
        }

        return [implode('', $parts), $map];
    }

    /** Puts the protected words back. A marker the translation lost or broke makes the whole result unusable. */
    public function restore(string $text, array $map): string
    {
        if ($map === []) {
            return $text;
        }
        $text = (string) preg_replace('/⟦\s*T\s*(\d+)\s*⟧/u', '⟦T$1⟧', $text);
        foreach ($map as $marker => $original) {
            if (!str_contains($text, (string) $marker)) {
                throw new \DomainException(CanonicalUiText::get('admin.ai.glossary_broken'));
            }
            $text = str_replace((string) $marker, (string) $original, $text);
        }

        return $text;
    }

    /** Lines for a model prompt: how fixed terms are translated into the target language ('' when none apply). */
    public function promptHint(int $storeId, string $targetLocale): string
    {
        $lines = [];
        foreach ($this->terms($storeId) as $row) {
            if ($row['mode'] === 'fixed' && ($fixed = $this->translationFor($row['translations'], $targetLocale)) !== null) {
                $lines[] = '- ' . json_encode($row['term'], JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($fixed, JSON_UNESCAPED_UNICODE);
            }
        }

        return $lines === [] ? '' : "Glossary: always translate these terms exactly like this (any grammatical form):\n" . implode("\n", $lines);
    }

    /** @param array<string,string> $translations locale or language => text */
    private function translationFor(array $translations, string $locale): ?string
    {
        $locale = str_replace('_', '-', trim($locale));
        $base = strtolower(substr($locale, 0, (int) (strpos($locale, '-') ?: strlen($locale))));
        foreach ($translations as $code => $value) {
            if (strcasecmp((string) $code, $locale) === 0) {
                return $value;
            }
        }
        foreach ($translations as $code => $value) {
            if (strtolower((string) $code) === $base) {
                return $value;
            }
        }

        return null;
    }
}
