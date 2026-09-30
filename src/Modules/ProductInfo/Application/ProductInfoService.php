<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductInfo\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Extra purchase-information blocks of a product: rich text, bullet lists and tables (size charts).
 * Stored in an additive table, independent from attributes and options; sanitised on save.
 */
final class ProductInfoService
{
    public const TYPES = ['info', 'list', 'table'];
    public const MAX_BLOCKS = 12;
    private const MAX_ITEMS = 50;
    private const MAX_COLUMNS = 8;
    private const MAX_ROWS = 50;

    /** @var array<string,list<array<string,mixed>>> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
        #[Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /** @return list<array{type:string,title:string,locale:?string,html?:string,items?:list<string>,columns?:list<string>,rows?:list<list<string>>}> */
    public function forStorefront(int $productId, string $locale): array
    {
        $key = $productId . '|' . $locale;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT block_type,title,locale,payload FROM mc_product_info_block WHERE product_id=? AND (locale IS NULL OR locale=?) ORDER BY position,id',
            [$productId, $locale],
        );

        return $this->cache[$key] = array_map(fn (array $r): array => $this->hydrate($r), $rows);
    }

    /** @return list<array{type:string,title:string,locale:string,text:string}> block rows in the textual form used by the editor */
    public function forEdit(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT block_type,title,locale,payload FROM mc_product_info_block WHERE product_id=? ORDER BY position,id', [$productId]);
        $out = [];
        foreach ($rows as $r) {
            $b = $this->hydrate($r);
            $text = match ($b['type']) {
                'list' => implode("\n", $b['items'] ?? []),
                'table' => implode("\n", array_merge([implode(' | ', $b['columns'] ?? [])], array_map(static fn (array $row): string => implode(' | ', $row), $b['rows'] ?? []))),
                default => (string) ($b['html'] ?? ''),
            };
            $out[] = ['type' => $b['type'], 'title' => $b['title'], 'locale' => (string) ($b['locale'] ?? ''), 'text' => $text];
        }

        return $out;
    }

    /** @param array<int|string,mixed> $blocks editor rows: type,title,locale,text */
    public function save(int $productId, array $blocks): void
    {
        $prepared = [];
        foreach ($blocks as $b) {
            if (!is_array($b) || count($prepared) >= self::MAX_BLOCKS) {
                continue;
            }
            $type = in_array($b['type'] ?? '', self::TYPES, true) ? (string) $b['type'] : 'info';
            $title = mb_substr(trim(strip_tags((string) ($b['title'] ?? ''))), 0, 190);
            $text = trim((string) ($b['text'] ?? ''));
            if ($title === '' && $text === '') {
                continue;
            }
            $locale = trim((string) ($b['locale'] ?? ''));
            if ($locale !== '' && preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})?$/D', $locale) !== 1) {
                $locale = '';
            }
            $payload = $this->payload($type, $text);
            if ($payload === null) {
                continue;
            }
            $prepared[] = [$type, $title, $locale !== '' ? $locale : null, $payload];
        }
        $this->db->transactional(function () use ($productId, $prepared): void {
            $this->db->executeStatement('DELETE FROM mc_product_info_block WHERE product_id=?', [$productId]);
            foreach ($prepared as $position => [$type, $title, $locale, $payload]) {
                $this->db->insert('mc_product_info_block', [
                    'product_id' => $productId, 'locale' => $locale, 'position' => $position, 'block_type' => $type,
                    'title' => $title, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
        $this->cache = [];
    }

    /** @return array<string,mixed>|null */
    private function payload(string $type, string $text): ?array
    {
        if ($type === 'info') {
            $html = trim($this->sanitizer->sanitize(mb_substr($text, 0, 100000)));

            return $html === '' ? null : ['html' => $html];
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return null;
        }
        if ($type === 'list') {
            return ['items' => array_map(static fn (string $l): string => mb_substr(strip_tags($l), 0, 190), array_slice($lines, 0, self::MAX_ITEMS))];
        }
        $split = static fn (string $line): array => array_map(static fn (string $c): string => mb_substr(trim(strip_tags($c)), 0, 128), explode('|', $line));
        $columns = array_slice($split($lines[0]), 0, self::MAX_COLUMNS);
        $rows = [];
        foreach (array_slice($lines, 1, self::MAX_ROWS) as $line) {
            $cells = array_slice($split($line), 0, count($columns));
            $rows[] = array_pad($cells, count($columns), '');
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function hydrate(array $r): array
    {
        $payload = json_decode((string) $r['payload'], true);
        $payload = is_array($payload) ? $payload : [];

        return ['type' => (string) $r['block_type'], 'title' => (string) $r['title'], 'locale' => $r['locale'] !== null ? (string) $r['locale'] : null] + $payload;
    }
}
