<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use Commerce\Modules\Storefront\Domain\StorefrontContext;

/**
 * /llms.txt (llmstxt.org): a plain Markdown map of the shop for AI assistants - who we are, where the catalogue, the articles and the rules are.
 * The full version adds the product list with prices. Only public, published content is listed; each block fails on its own.
 */
final class LlmsTxtBuilder
{
    public function __construct(
        private readonly LlmsSettings $settings,
        private readonly DbalStorefrontCatalogQuery $catalog,
        private readonly DbalBlogQuery $blog,
        private readonly string $publicBaseUrl,
    ) {
    }

    public function build(StorefrontContext $context, bool $full, array $features): string
    {
        $config = $this->settings->all();
        $base = rtrim($this->publicBaseUrl, '/');
        $out = ['# ' . $this->line($context->storeName), ''];
        $out[] = '> ' . ($config['description'] !== '' ? $this->line(strtok($config['description'], "\n") ?: $context->storeName) : $this->line($context->storeName) . ' — online store: catalogue, delivery and payment information.');
        $out[] = '';
        if ($config['description'] !== '' && str_contains($config['description'], "\n")) {
            $out[] = trim(substr($config['description'], (int) strpos($config['description'], "\n")));
            $out[] = '';
        }
        if ($config['instructions'] !== '') {
            $out[] = '## Instructions for AI assistants';
            $out[] = $config['instructions'];
            $out[] = '';
        }

        $links = [];
        if (($features['catalog'] ?? false) === true) {
            $links[] = ['Catalogue', $base . '/catalog', 'All products'];
        }
        if (($features['blog'] ?? false) === true) {
            $links[] = ['Blog', $base . '/blog', 'Articles and guides'];
        }
        if (($features['content'] ?? false) === true) {
            $links[] = ['Contacts', $base . '/contact', 'How to reach the shop'];
        }
        $links[] = ['Sitemap', $base . '/sitemap.xml', 'Every public page'];
        $this->section($out, 'Main sections', $links);

        if (($features['catalog'] ?? false) === true && $config['categories'] > 0) {
            try {
                $rows = array_map(fn (array $c): array => [(string) $c['name'], $base . (string) $c['url'], ''], array_filter($this->catalog->topCategories($context, $config['categories']), static fn (array $c): bool => ($c['url'] ?? '') !== ''));
                $this->section($out, 'Product categories', $rows);
            } catch (\Throwable) {
                // a missing block must not break the file
            }
        }
        if (($features['catalog'] ?? false) === true && $config['products'] > 0) {
            try {
                $limit = $full ? $config['products'] : min(20, $config['products']);
                $items = $this->catalog->products($context, null, 1, min(60, $limit))['items'];
                $rows = [];
                foreach ($items as $p) {
                    $rows[] = [(string) $p['name'], $base . (string) $p['url'], $config['prices'] && ($p['price'] ?? '') !== '' ? (string) $p['price'] : ''];
                }
                $this->section($out, $full ? 'Products' : 'Popular products', $rows);
            } catch (\Throwable) {
            }
        }
        if (($features['blog'] ?? false) === true && $config['articles'] > 0) {
            try {
                $rows = [];
                foreach ($this->blog->latest($context->storeId, $context->locale, $config['articles']) as $a) {
                    $rows[] = [(string) $a['title'], $base . (string) $a['url'], (string) ($a['excerpt'] ?? '')];
                }
                $this->section($out, 'Articles', $rows);
            } catch (\Throwable) {
            }
        }
        $custom = [];
        foreach (preg_split('/\R/u', $config['links']) ?: [] as $row) {
            $parts = array_map('trim', explode('|', $row, 3));
            if (count($parts) >= 2 && $parts[0] !== '' && preg_match('~^(https?://|/)~', $parts[1]) === 1) {
                $custom[] = [$parts[0], str_starts_with($parts[1], '/') ? $base . $parts[1] : $parts[1], $parts[2] ?? ''];
            }
        }
        $this->section($out, 'More', $custom);

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $out @param list<array{0:string,1:string,2:string}> $rows */
    private function section(array &$out, string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $out[] = '## ' . $title;
        foreach ($rows as [$name, $url, $note]) {
            $out[] = '- [' . $this->line($name) . '](' . $url . ')' . ($note !== '' ? ': ' . $this->line($note) : '');
        }
        $out[] = '';
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']'], ['(', ')'], strip_tags($text))));
    }
}
