<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\Indexing;

final class CatalogIndexingPolicy
{
    private const TRACKING_PREFIXES = ['utm_', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid'];
    private const PRESENTATION_PARAMS = ['sort','order','limit','view','layout'];

    /**
     * @param array<string, scalar|array|null> $query
     */
    public function category(string $baseCanonical, array $query, bool $promotedFacet = false, bool $hasResults = true): IndexingDecision
    {
        if (!$hasResults) {
            return new IndexingDecision(false, false, $baseCanonical, 'empty-filter-combination', 404);
        }

        $keys = array_map('strtolower', array_keys($query));
        $hasTracking = count(array_filter($keys, fn(string $k): bool => $this->isTracking($k))) > 0;
        $nonTracking = array_values(array_filter($keys, fn(string $k): bool => !$this->isTracking($k)));
        $hasPresentation = count(array_intersect($nonTracking, self::PRESENTATION_PARAMS)) > 0;
        $hasPagination = in_array('page', $nonTracking, true) && (int)($query['page'] ?? 1) > 1;
        $facetKeys = array_values(array_diff($nonTracking, [...self::PRESENTATION_PARAMS, 'page']));

        if ($promotedFacet && $facetKeys !== []) {
            return new IndexingDecision(true, true, $baseCanonical, 'promoted-seo-facet-landing');
        }
        if ($facetKeys !== []) {
            return new IndexingDecision(false, true, $baseCanonical, 'runtime-facet');
        }
        if ($hasPresentation) {
            return new IndexingDecision(false, true, $baseCanonical, 'sorting-or-presentation-variant');
        }
        if ($hasPagination) {
            return new IndexingDecision(true, true, $this->withPage($baseCanonical, (int)$query['page']), 'pagination');
        }
        if ($hasTracking) {
            return new IndexingDecision(false, true, $baseCanonical, 'tracking-parameters');
        }
        return new IndexingDecision(true, true, $baseCanonical, 'canonical-category');
    }

    private function isTracking(string $key): bool
    {
        foreach (self::TRACKING_PREFIXES as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix)) return true;
        }
        return false;
    }

    private function withPage(string $url, int $page): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'page=' . max(2, $page);
    }
}
