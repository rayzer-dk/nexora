<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Store/locale scoped managed synonyms. Search remains fully functional when
 * there are no configured synonym rows; this is an optional relevance layer.
 */
final readonly class SearchSynonymService
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
    ) {}

    /** @param list<string> $tokens @return list<list<string>> */
    public function expandTokenGroups(int $storeId, string $locale, array $tokens): array
    {
        $groups = [];
        foreach (array_slice($tokens, 0, 8) as $token) {
            $term = $this->normalizeTerm($token);
            if ($term === '') {
                continue;
            }
            $alternatives = [$term => true];
            try {
                $rows = $this->db->fetchFirstColumn(
                    "SELECT DISTINCT t2.term
                     FROM mc_search_synonym_term t1
                     JOIN mc_search_synonym_group g ON g.id=t1.group_id AND g.store_id=? AND g.locale=? AND g.status='active'
                     JOIN mc_search_synonym_term t2 ON t2.group_id=g.id
                     WHERE t1.term_hash=?
                     ORDER BY t2.sort_order,t2.id
                     LIMIT 24",
                    [$storeId, $locale, hash('sha256', $term, true)],
                );
                foreach ($rows as $row) {
                    $synonym = $this->normalizeTerm((string) $row);
                    if ($synonym !== '') {
                        $alternatives[$synonym] = true;
                    }
                }
            } catch (\Throwable) {
                // Synonyms are an optional relevance layer. Missing migration or a
                // damaged synonym table must never take catalog search down.
            }
            // Numeric terms ("14", "256") become int array keys in PHP; cast back so callers always get strings.
            $groups[] = array_map('strval', array_slice(array_keys($alternatives), 0, 12));
        }
        return $groups;
    }

    /** @return list<array{id:int,label:string,status:string,terms:list<string>,created_at:string}> */
    public function groups(int $storeId, string $locale): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT g.id,g.label,g.status,g.created_at,t.term
                 FROM mc_search_synonym_group g
                 LEFT JOIN mc_search_synonym_term t ON t.group_id=g.id
                 WHERE g.store_id=? AND g.locale=?
                 ORDER BY g.id DESC,t.sort_order,t.id",
                [$storeId, $locale],
            );
        } catch (\Throwable) {
            return [];
        }
        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'id' => $id,
                    'label' => (string) $row['label'],
                    'status' => (string) $row['status'],
                    'terms' => [],
                    'created_at' => (string) $row['created_at'],
                ];
            }
            if ($row['term'] !== null) {
                $grouped[$id]['terms'][] = (string) $row['term'];
            }
        }
        return array_values($grouped);
    }

    public function createGroup(int $storeId, string $locale, string $label, string $rawTerms): void
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label, 'UTF-8') > 190) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.vkazhit_korotku_nazvu_hrupy_synonimiv'));
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.nekorektna_lokal'));
        }

        $parts = preg_split('/[\r\n,;|]+/u', $rawTerms, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $term = $this->normalizeTerm($part);
            if ($term === '') continue;
            if (mb_strlen($term, 'UTF-8') > 80) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.okremyi_synonim_ne_mozhe_perevyshchuvaty_80_symvoliv'));
            }
            $terms[$term] = true;
        }
        $terms = array_keys($terms);
        if (count($terms) < 2) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.dodaite_shchonaimenshe_dva_rizni_synonimy'));
        }
        if (count($terms) > 20) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.odna_hrupa_mozhe_mistyty_ne_bilshe_20_synonimiv'));
        }

        $this->db->transactional(function (Connection $db) use ($storeId, $locale, $label, $terms): void {
            if ((int) $db->fetchOne("SELECT COUNT(*) FROM mc_store WHERE id=? AND status='active'", [$storeId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.aktyvnyi_mahazyn_ne_znaideno'));
            }
            $now = $this->now();
            $db->insert('mc_search_synonym_group', [
                'public_id' => $this->ids->binary(),
                'store_id' => $storeId,
                'locale' => $locale,
                'label' => $label,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupId = (int) $db->lastInsertId();
            foreach ($terms as $index => $term) {
                $db->insert('mc_search_synonym_term', [
                    'group_id' => $groupId,
                    'term' => $term,
                    'term_hash' => hash('sha256', $term, true),
                    'sort_order' => $index,
                ]);
            }
        });
    }

    public function deleteGroup(int $storeId, string $locale, int $groupId): void
    {
        if ($groupId < 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.nekorektna_hrupa_synonimiv'));
        }
        $deleted = $this->db->executeStatement(
            'DELETE FROM mc_search_synonym_group WHERE id=? AND store_id=? AND locale=?',
            [$groupId, $storeId, $locale],
        );
        if ($deleted !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.search.application.searchsynonymservice.hrupu_synonimiv_ne_znaideno'));
        }
    }

    private function normalizeTerm(string $term): string
    {
        $term = mb_strtolower(trim($term), 'UTF-8');
        $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
        $term = trim($term, " \t\n\r\0\x0B,.;:!?\"'`()[]{}<>");
        return $term;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
