<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Application;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Built-in search index for installations without an external search engine.
 *
 * Every product gets one normalized document per store language containing its names in all store
 * languages, brand, category path names (all languages), SKU/GTIN/MPN and attribute values, so a shopper
 * finds "headphones" in a Ukrainian storefront and "навушники" in an English one. Queries are stemmed
 * (uk/ru/en/pl/de/da endings) and unknown words are corrected against the store vocabulary
 * (Damerau-free Levenshtein, 1 edit up to 6 letters, 2 edits for longer words).
 */
final class SqlSearchIndex
{
    private const MIN_TERM = 3;

    /** @var array<string,bool> */
    private array $available = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function isAvailable(int $storeId, string $locale): bool
    {
        $key = $storeId . '|' . $locale;
        if (!isset($this->available[$key])) {
            try {
                $this->available[$key] = (bool) $this->db->fetchOne('SELECT 1 FROM mc_search_document WHERE store_id=? AND locale=? LIMIT 1', [$storeId, $locale]);
            } catch (Throwable) {
                $this->available[$key] = false; // table missing on a partially migrated installation
            }
        }

        return $this->available[$key];
    }

    /**
     * Token groups ready for LIKE matching against documents: every group is a list of alternatives
     * (stems of the token and its synonyms, plus a spelling correction when the word is unknown).
     *
     * @param list<list<string>> $tokenGroups
     * @return array{groups:list<list<string>>,corrections:array<string,string>}
     */
    public function prepare(int $storeId, string $locale, array $tokenGroups): array
    {
        $groups = [];
        $corrections = [];
        foreach ($tokenGroups as $alternatives) {
            $out = [];
            foreach ($alternatives as $token) {
                $normalized = self::normalize((string) $token);
                foreach (preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $out[] = self::stem($word);
                }
            }
            $out = array_values(array_unique(array_filter($out, static fn (string $t): bool => $t !== '')));
            if ($out === []) {
                continue;
            }
            if (!$this->anyDocumentContains($storeId, $locale, $out)) {
                $original = (string) ($alternatives[0] ?? '');
                $normalizedOriginal = self::normalize($original);
                $fixed = $this->correct($storeId, $locale, $normalizedOriginal);
                if ($fixed === null) {
                    // Typed in the other script or with the wrong keyboard layout: "самсунг" for Samsung, "ыфьыгтп" for samsung.
                    foreach (self::scriptVariants($normalizedOriginal) as $variant) {
                        if ($this->anyDocumentContains($storeId, $locale, [self::stem($variant)])) {
                            $fixed = $variant;
                            break;
                        }
                        $near = $this->correct($storeId, $locale, $variant);
                        if ($near !== null) {
                            $fixed = $near;
                            break;
                        }
                    }
                }
                if ($fixed !== null) {
                    $corrections[$original] = $fixed;
                    $out[] = self::stem($fixed);
                }
            }
            $groups[] = array_values(array_unique($out));
        }

        return ['groups' => $groups, 'corrections' => $corrections];
    }

    public function rebuildProduct(int $productId): void
    {
        $stores = $this->db->fetchAllAssociative(
            'SELECT sp.store_id, sl.locale_code FROM mc_store_product sp JOIN mc_store_locale sl ON sl.store_id=sp.store_id AND sl.enabled=1 WHERE sp.product_id=?',
            [$productId],
        );
        $this->db->executeStatement('DELETE FROM mc_search_document WHERE product_id=?', [$productId]);
        foreach ($stores as $row) {
            $document = $this->document((int) $row['store_id'], (string) $row['locale_code'], $productId);
            if ($document === '') {
                continue;
            }
            $this->write((int) $row['store_id'], (string) $row['locale_code'], $productId, $document);
            $this->addTerms((int) $row['store_id'], (string) $row['locale_code'], $document);
        }
    }

    /** @return array{documents:int,terms:int} */
    public function rebuildAll(int $batch = 500): array
    {
        $documents = 0;
        $pairs = $this->db->fetchAllAssociative('SELECT s.id store_id, sl.locale_code FROM mc_store s JOIN mc_store_locale sl ON sl.store_id=s.id AND sl.enabled=1');
        $this->db->executeStatement(
            'DELETE sd FROM mc_search_document sd LEFT JOIN mc_store_locale sl ON sl.store_id=sd.store_id AND sl.locale_code=sd.locale AND sl.enabled=1 WHERE sl.store_id IS NULL',
        );
        foreach ($pairs as $pair) {
            $storeId = (int) $pair['store_id'];
            $locale = (string) $pair['locale_code'];
            $counts = [];
            $lastId = 0;
            do {
                $ids = array_map('intval', $this->db->fetchFirstColumn(
                    'SELECT product_id FROM mc_store_product WHERE store_id=? AND product_id>? ORDER BY product_id LIMIT ' . max(50, $batch),
                    [$storeId, $lastId],
                ));
                foreach ($ids as $productId) {
                    $lastId = $productId;
                    $document = $this->document($storeId, $locale, $productId);
                    if ($document === '') {
                        continue;
                    }
                    $this->write($storeId, $locale, $productId, $document);
                    ++$documents;
                    foreach (self::terms($document) as $term) {
                        $counts[$term] = ($counts[$term] ?? 0) + 1;
                    }
                }
            } while (count($ids) >= max(50, $batch));
            $this->db->executeStatement(
                'DELETE sd FROM mc_search_document sd LEFT JOIN mc_store_product sp ON sp.store_id=sd.store_id AND sp.product_id=sd.product_id WHERE sd.store_id=? AND sd.locale=? AND sp.product_id IS NULL',
                [$storeId, $locale],
            );
            $this->replaceTerms($storeId, $locale, $counts);
        }
        $terms = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_search_term');

        return ['documents' => $documents, 'terms' => $terms];
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
        $text = strtr($text, ['ё' => 'е', 'ʼ' => '', '’' => '', "'" => '', '`' => '', 'ß' => 'ss']);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Other spellings of a word typed in a single script: the same letters on the other keyboard layout, then the
     * sound written in the other alphabet (самсунг -> samsung, samsung -> самсунг).
     *
     * @return list<string>
     */
    public static function scriptVariants(string $word): array
    {
        if ($word === '' || str_contains($word, ' ') || preg_match('/\d/', $word) === 1) {
            return [];
        }
        $cyrillic = preg_match('/^\p{Cyrillic}+$/u', $word) === 1;
        $latin = preg_match('/^[a-z]+$/', $word) === 1;
        if (!$cyrillic && !$latin) {
            return [];
        }
        $layoutCyr = ['й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p', 'х' => '[', 'ъ' => ']', 'ф' => 'a', 'ы' => 's', 'і' => 's', 'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l', 'ж' => ';', 'э' => "'", 'є' => "'", 'я' => 'z', 'ч' => 'x', 'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm', 'б' => ',', 'ю' => '.'];
        $layoutLat = [];
        foreach ($layoutCyr as $cyr => $lat) {
            if (!isset($layoutLat[$lat]) && $cyr !== 'і') {
                $layoutLat[$lat] = $cyr;
            }
        }
        $toLat = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
        $toCyr = ['shch' => 'щ', 'sch' => 'щ', 'kh' => 'х', 'zh' => 'ж', 'ts' => 'ц', 'ch' => 'ч', 'sh' => 'ш', 'yu' => 'ю', 'ya' => 'я', 'ye' => 'є', 'ck' => 'к', 'ph' => 'ф', 'ee' => 'і', 'oo' => 'у', 'a' => 'а', 'b' => 'б', 'c' => 'к', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г', 'h' => 'х', 'i' => 'і', 'j' => 'дж', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'к', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс', 'y' => 'и', 'z' => 'з'];
        $variants = [];
        if ($cyrillic) {
            $variants[] = strtr($word, $layoutCyr);
            $variants[] = strtr($word, $toLat);
        } else {
            $variants[] = strtr($word, $layoutLat);
            $variants[] = strtr($word, $toCyr);
        }

        return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== '' && $v !== $word && mb_strlen($v, 'UTF-8') >= 3)));
    }

    /** Light suffix stripping so that word forms (ноутбуки/ноутбук, smartphones/smartphone) match. */
    public static function stem(string $word): string
    {
        $length = mb_strlen($word, 'UTF-8');
        if ($length < 5 || preg_match('/\d/', $word) === 1) {
            return $word;
        }
        static $suffixes = null;
        $suffixes ??= self::suffixes();
        $cyrillic = preg_match('/\p{Cyrillic}/u', $word) === 1;
        foreach ($suffixes[$cyrillic ? 'cyr' : 'lat'] as $suffix) {
            $suffixLength = mb_strlen($suffix, 'UTF-8');
            if ($length - $suffixLength >= 4 && str_ends_with($word, $suffix)) {
                return mb_substr($word, 0, $length - $suffixLength, 'UTF-8');
            }
        }

        return $word;
    }

    /** @return list<string> distinct words suitable for the vocabulary */
    public static function terms(string $document): array
    {
        $words = [];
        foreach (explode(' ', $document) as $word) {
            $length = mb_strlen($word, 'UTF-8');
            if ($length >= self::MIN_TERM && $length <= 64 && preg_match('/^\d+$/', $word) !== 1) {
                $words[$word] = true;
            }
        }

        return array_keys($words);
    }

    public function correct(int $storeId, string $locale, string $word): ?string
    {
        $length = mb_strlen($word, 'UTF-8');
        if ($length < 4 || $length > 64 || preg_match('/\d/', $word) === 1 || str_contains($word, ' ')) {
            return null;
        }
        $maxDistance = $length <= 6 ? 1 : 2;
        try {
            $candidates = $this->db->fetchAllAssociative(
                'SELECT term, weight FROM mc_search_term WHERE store_id=? AND locale=? AND term_length BETWEEN ? AND ? ORDER BY weight DESC LIMIT 20000',
                [$storeId, $locale, $length - $maxDistance, $length + $maxDistance],
            );
        } catch (Throwable) {
            return null;
        }
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $term = (string) $candidate['term'];
            $distance = self::distance($word, $term);
            if ($distance > $maxDistance) {
                continue;
            }
            // Prefer fewer edits, then a shared first letter, then more frequent words.
            $score = $distance * 1_000_000 + (mb_substr($term, 0, 1) === mb_substr($word, 0, 1) ? 0 : 500_000) - min(499_999, (int) $candidate['weight']);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $term;
            }
        }

        return $best !== null && $best !== $word ? $best : null;
    }

    /** Levenshtein distance on Unicode characters (PHP's levenshtein() counts bytes). */
    public static function distance(string $a, string $b): int
    {
        $map = [];
        $encode = static function (string $s) use (&$map): string {
            $out = '';
            foreach (mb_str_split($s, 1, 'UTF-8') as $char) {
                $map[$char] ??= chr(count($map) % 256);
                $out .= $map[$char];
            }
            return $out;
        };
        $x = $encode($a);
        $y = $encode($b);

        return count($map) <= 256 ? levenshtein($x, $y) : levenshtein($a, $b);
    }

    /** @param list<string> $stems */
    private function anyDocumentContains(int $storeId, string $locale, array $stems): bool
    {
        $sql = [];
        $params = [$storeId, $locale];
        foreach ($stems as $stem) {
            $sql[] = "document LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($stem, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }

        return (bool) $this->db->fetchOne('SELECT 1 FROM mc_search_document WHERE store_id=? AND locale=? AND (' . implode(' OR ', $sql) . ') LIMIT 1', $params);
    }

    private function document(int $storeId, string $locale, int $productId): string
    {
        $parts = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT locale, name, short_description FROM mc_product_translation WHERE product_id=? AND store_id=?',
            [$productId, $storeId],
        ) as $row) {
            $parts[] = (string) $row['name'];
            if ((string) $row['locale'] === $locale) {
                $parts[] = (string) ($row['short_description'] ?? '');
            }
        }
        if ($parts === []) {
            return '';
        }
        $parts[] = (string) ($this->db->fetchOne('SELECT b.name FROM mc_product p JOIN mc_brand b ON b.id=p.brand_id WHERE p.id=?', [$productId]) ?: '');
        foreach ($this->db->fetchAllAssociative("SELECT sku, gtin, mpn FROM mc_product_variant WHERE product_id=? AND status='active'", [$productId]) as $variant) {
            array_push($parts, (string) $variant['sku'], (string) ($variant['gtin'] ?? ''), (string) ($variant['mpn'] ?? ''));
        }
        $categoryIds = array_map('intval', $this->db->fetchFirstColumn('SELECT category_id FROM mc_product_category WHERE product_id=?', [$productId]));
        $seen = [];
        for ($depth = 0; $categoryIds !== [] && $depth < 8; ++$depth) {
            $categoryIds = array_values(array_diff($categoryIds, array_keys($seen)));
            if ($categoryIds === []) {
                break;
            }
            foreach ($categoryIds as $id) {
                $seen[$id] = true;
            }
            foreach ($this->db->fetchFirstColumn('SELECT name FROM mc_category_translation WHERE store_id=? AND category_id IN (?)', [$storeId, $categoryIds], [1 => ArrayParameterType::INTEGER]) as $name) {
                $parts[] = (string) $name;
            }
            $categoryIds = array_map('intval', array_filter($this->db->fetchFirstColumn('SELECT parent_id FROM mc_category WHERE id IN (?)', [$categoryIds], [ArrayParameterType::INTEGER])));
        }
        foreach ($this->db->fetchFirstColumn(
            'SELECT value_text FROM mc_product_attribute_value WHERE product_id=? AND value_text IS NOT NULL AND (locale IS NULL OR locale=?) LIMIT 200',
            [$productId, $locale],
        ) as $value) {
            $parts[] = (string) $value;
        }
        $normalized = self::normalize(implode(' ', $parts));
        $unique = implode(' ', array_keys(array_flip(explode(' ', $normalized))));

        return mb_substr($unique, 0, 60000, 'UTF-8');
    }

    private function write(int $storeId, string $locale, int $productId, string $document): void
    {
        $this->db->executeStatement(
            'INSERT INTO mc_search_document (store_id,locale,product_id,document,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE document=VALUES(document),updated_at=VALUES(updated_at)',
            [$storeId, $locale, $productId, $document],
        );
    }

    private function addTerms(int $storeId, string $locale, string $document): void
    {
        foreach (array_chunk(self::terms($document), 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $term) {
                $values[] = '(?,?,?,?,1)';
                array_push($params, $storeId, $locale, $term, mb_strlen($term, 'UTF-8'));
            }
            $this->db->executeStatement(
                'INSERT INTO mc_search_term (store_id,locale,term,term_length,weight) VALUES ' . implode(',', $values) . ' ON DUPLICATE KEY UPDATE weight=weight',
                $params,
            );
        }
    }

    /** @param array<string,int> $counts */
    private function replaceTerms(int $storeId, string $locale, array $counts): void
    {
        $this->db->transactional(function (Connection $db) use ($storeId, $locale, $counts): void {
            $db->executeStatement('DELETE FROM mc_search_term WHERE store_id=? AND locale=?', [$storeId, $locale]);
            foreach (array_chunk($counts, 500, true) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $term => $weight) {
                    $values[] = '(?,?,?,?,?)';
                    array_push($params, $storeId, $locale, (string) $term, mb_strlen((string) $term, 'UTF-8'), $weight);
                }
                $db->executeStatement('INSERT IGNORE INTO mc_search_term (store_id,locale,term,term_length,weight) VALUES ' . implode(',', $values), $params);
            }
        });
    }

    /** @return array{cyr:list<string>,lat:list<string>} longest suffixes first */
    private static function suffixes(): array
    {
        $cyr = ['ями', 'ами', 'ові', 'еві', 'ого', 'ому', 'ими', 'іми', 'ого', 'его', 'ему', 'ыми', 'ях', 'ах', 'ів', 'їв', 'ов', 'ев', 'ам', 'ям', 'ом', 'ем', 'ою', 'ею', 'ий', 'ій', 'ої', 'ей', 'ой', 'ый', 'их', 'іх', 'ых', 'ые', 'ие', 'ую', 'юю', 'а', 'я', 'и', 'і', 'ї', 'ы', 'у', 'ю', 'о', 'е', 'ь', 'й'];
        $lat = ['ingen', 'ernes', 'ies', 'ing', 'ers', 'ene', 'erne', 'ami', 'ach', 'ów', 'om', 'es', 'en', 'er', 'et', 'em', 'ed', 's', 'e', 'n', 'y', 'a'];
        $sort = static function (array $list): array {
            $list = array_values(array_unique($list));
            usort($list, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
            return $list;
        };

        return ['cyr' => $sort($cyr), 'lat' => $sort($lat)];
    }
}
