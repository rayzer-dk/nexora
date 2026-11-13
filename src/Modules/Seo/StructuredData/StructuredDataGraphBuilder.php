<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\StructuredData;

/**
 * One graph per rendered page. Ignore duplicate semantic identities, not all
 * nodes of the same type: two distinct Products can coexist legitimately.
 */
final class StructuredDataGraphBuilder
{
    public function build(array ...$nodes): array
    {
        $graph = [];
        $seen = [];
        foreach ($nodes as $node) {
            if ($node === [] || !isset($node['@type'])) {
                continue;
            }
            unset($node['@context']);
            $identity = trim((string) ($node['@id'] ?? ''));
            if ($identity === '') {
                $identity = (string) $node['@type'] . ':' . (string) ($node['url'] ?? '');
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $graph[] = $node;
        }
        return $graph === [] ? [] : ['@context' => 'https://schema.org', '@graph' => $graph];
    }
}
