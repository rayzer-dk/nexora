<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\ProductPage\Domain\ProductPageBlock;
use Commerce\Modules\ProductPage\Domain\ProductPageLayout;

/**
 * Storefront product-layout loader.
 *
 * Published Builder revisions have priority. If a revision is missing or invalid,
 * the shipped config/product_page/default_layout.json remains the safe fallback.
 * Draft revisions never affect the storefront.
 */
final readonly class ProductPageLayoutLoader
{
    private const SUPPORTED_SCHEMA = [1, 2, 3];

    /** @var array<string,array{type:string,region:string}> */
    private const BUILDER_MAP = [
        'product_gallery' => ['type'=>'media_gallery','region'=>'hero_media'],
        'product_title' => ['type'=>'title_meta','region'=>'hero_summary'],
        'product_price' => ['type'=>'price','region'=>'hero_summary'],
        'product_stock' => ['type'=>'availability','region'=>'hero_summary'],
        'product_variants' => ['type'=>'variants','region'=>'hero_summary'],
        'product_buy' => ['type'=>'buy_actions','region'=>'hero_summary'],
        'product_description' => ['type'=>'description','region'=>'below_primary'],
        'product_attributes' => ['type'=>'attributes','region'=>'below_primary'],
        'product_documents' => ['type'=>'documents','region'=>'below_secondary'],
        'product_reviews' => ['type'=>'reviews','region'=>'below_secondary'],
        'related_products' => ['type'=>'related_products','region'=>'below_secondary'],
        'rich_text' => ['type'=>'custom_rich_text','region'=>'below_primary'],
        'image' => ['type'=>'custom_image','region'=>'below_primary'],
        'info_card' => ['type'=>'info_card','region'=>'below_secondary'],
    ];

    public function __construct(
        private string $defaultLayoutPath,
        private ProductBlockRegistry $registry,
        private LayoutRevisionStore $layoutRevisions,
        private ExtensionContributionRegistry $extensions,
    ) {
    }

    public function loadForStore(int $storeId): ProductPageLayout
    {
        if ($storeId > 0) {
            try {
                $builder = $this->layoutRevisions->publishedOrNull($storeId, 'product');
                if (is_array($builder)) {
                    $layout = $this->fromBuilder($builder);
                    if ($layout !== null) {
                        return $layout;
                    }
                }
            } catch (\Throwable) {
                // A Builder/repository failure must never break a product page.
            }
        }

        return $this->loadDefault();
    }

    public function loadDefault(): ProductPageLayout
    {
        try {
            $data = $this->readConfiguration();
            $blocks = $this->buildBlocks($data);
            if ($blocks === []) {
                return $this->safeFallback();
            }

            return new ProductPageLayout(
                schemaVersion: (int) $data['schema_version'],
                code: $this->safeCode((string) ($data['layout_code'] ?? 'default')),
                name: mb_substr(trim((string) ($data['name'] ?? 'Default')), 0, 190, 'UTF-8') ?: 'Default',
                blocks: $blocks,
            );
        } catch (\Throwable) {
            return $this->safeFallback();
        }
    }

    /** @param array<string,mixed> $layout */
    private function fromBuilder(array $layout): ?ProductPageLayout
    {
        if ((int) ($layout['schema_version'] ?? 0) !== 1 || !is_array($layout['blocks'] ?? null)) {
            return null;
        }

        $blocks = [];
        $order = 0;
        foreach ($layout['blocks'] as $row) {
            if (!is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }
            $component = (string) ($row['component'] ?? '');
            $mapping = self::BUILDER_MAP[$component] ?? null;
            if (!is_array($mapping)) {
                $extensionBlock = $this->extensions->block($component);
                if (!is_array($extensionBlock) || (string) ($extensionBlock['surface'] ?? '') !== 'product') {
                    continue;
                }
                $regions = array_values(array_filter((array) ($extensionBlock['regions'] ?? []), 'is_string'));
                if ($regions === []) {
                    continue;
                }
                $mapping = ['type' => $component, 'region' => $regions[0]];
            }
            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1) {
                continue;
            }
            $type = $mapping['type'];
            $defaultRegion = $mapping['region'];
            $props = is_array($row['props'] ?? null) ? $row['props'] : [];
            $style = is_array($row['style'] ?? null) ? $row['style'] : [];
            $visibility = is_array($row['visibility'] ?? null) ? $row['visibility'] : [];

            try {
                $definition = $this->registry->get($type);
                $requestedRegion = (string) ($props['region'] ?? $defaultRegion);
                $region = $definition->supportsRegion($requestedRegion) ? $requestedRegion : $defaultRegion;
                if (!$definition->supportsRegion($region)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $settings = str_starts_with($component, 'extension.') ? $this->extensionBuilderSettings($props, $style, $visibility) : $this->builderSettings($props, $style, $visibility);
            $blocks[] = new ProductPageBlock(
                id: $id,
                type: $type,
                region: $region,
                order: $order,
                mobileOrder: $order,
                settings: $settings,
            );
            $order += 10;
        }

        return $blocks === [] ? null : new ProductPageLayout(1, 'builder-published', 'Published Product Builder', $blocks);
    }


    /** @param array<string,mixed> $props @param array<string,mixed> $style @param array<string,mixed> $visibility @return array<string,mixed> */
    private function extensionBuilderSettings(array $props, array $style, array $visibility): array
    {
        $settings = $props;
        $settings['builder_style'] = $style;
        $settings['builder_visibility'] = [
            'desktop' => ($visibility['desktop'] ?? true) !== false,
            'tablet' => ($visibility['tablet'] ?? true) !== false,
            'mobile' => ($visibility['mobile'] ?? true) !== false,
        ];
        return $settings;
    }

    /** @param array<string,mixed> $props @param array<string,mixed> $style @param array<string,mixed> $visibility @return array<string,mixed> */
    private function builderSettings(array $props, array $style, array $visibility): array
    {
        $settings = [];
        foreach (['title','text','image','url','button_label','button_url','data_source'] as $key) {
            if (isset($props[$key]) && is_scalar($props[$key])) {
                $settings[$key] = mb_substr(trim((string) $props[$key]), 0, $key === 'text' ? 4000 : 1000, 'UTF-8');
            }
        }
        $settings['builder_visibility'] = [
            'desktop' => ($visibility['desktop'] ?? true) !== false,
            'tablet' => ($visibility['tablet'] ?? true) !== false,
            'mobile' => ($visibility['mobile'] ?? true) !== false,
        ];
        $align = (string) ($style['text_align'] ?? '');
        if (in_array($align, ['left','center','right'], true)) {
            $settings['builder_text_align'] = $align;
        }
        foreach (['padding','margin'] as $spacingKey) {
            $value = trim((string) ($style[$spacingKey] ?? ''));
            if (preg_match('/^\d{1,3}(?:\.\d{1,2})?(?:px|rem|em|%)$/D', $value) === 1) {
                $settings['builder_' . $spacingKey] = $value;
            }
        }
        foreach (['background','color'] as $colorKey) {
            $value = trim((string) ($style[$colorKey] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/D', $value) === 1) {
                $settings['builder_' . $colorKey] = strtolower($value);
            }
        }
        return $settings;
    }

    /** @return array<string,mixed> */
    private function readConfiguration(): array
    {
        if (!is_file($this->defaultLayoutPath) || !is_readable($this->defaultLayoutPath)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1265f2f01253'));
        }
        $raw = @file_get_contents($this->defaultLayoutPath);
        if (!is_string($raw) || strlen($raw) > 2 * 1024 * 1024) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.83ae8a8826ab'));
        }
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !in_array(($data['schema_version'] ?? null), self::SUPPORTED_SCHEMA, true) || !is_array($data['regions'] ?? null)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6c9fdfc2cae8'));
        }
        return $data;
    }

    /** @param array<string,mixed> $data @return list<ProductPageBlock> */
    private function buildBlocks(array $data): array
    {
        $blocks = [];
        $ids = [];
        foreach ((array) $data['regions'] as $region => $regionBlocks) {
            if (!is_string($region) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $region) !== 1 || !is_array($regionBlocks)) {
                continue;
            }
            foreach ($regionBlocks as $blockData) {
                if (!is_array($blockData)) {
                    continue;
                }
                $id = trim((string) ($blockData['id'] ?? ''));
                $type = trim((string) ($blockData['type'] ?? ''));
                if ($id === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1 || isset($ids[$id]) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) !== 1) {
                    continue;
                }
                try {
                    $definition = $this->registry->get($type);
                    if (!$definition->supportsRegion($region)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
                $ids[$id] = true;
                $settings = is_array($blockData['settings'] ?? null) ? $blockData['settings'] : [];
                $blocks[] = new ProductPageBlock(
                    id: $id,
                    type: $type,
                    region: $region,
                    order: max(-10000, min(10000, (int) ($blockData['order'] ?? 0))),
                    mobileOrder: max(-10000, min(10000, (int) ($blockData['mobile_order'] ?? ($blockData['order'] ?? 0)))),
                    settings: $settings,
                );
            }
        }
        return $blocks;
    }

    private function safeFallback(): ProductPageLayout
    {
        $candidates = [
            ['gallery', 'media_gallery', 'hero_media', 10],
            ['title', 'title_meta', 'hero_summary', 10],
            ['price', 'price', 'hero_summary', 20],
            ['availability', 'availability', 'hero_summary', 30],
            ['variants', 'variants', 'hero_summary', 40],
            ['buy', 'buy_actions', 'hero_summary', 50],
            ['description', 'description', 'below_primary', 10],
            ['attributes', 'attributes', 'below_primary', 20],
        ];
        $blocks = [];
        foreach ($candidates as [$id, $type, $region, $order]) {
            try {
                $definition = $this->registry->get($type);
                if (!$definition->supportsRegion($region)) {
                    continue;
                }
                $blocks[] = new ProductPageBlock($id, $type, $region, $order, $order, []);
            } catch (\Throwable) {
            }
        }
        return new ProductPageLayout(3, 'safe-fallback', 'Safe fallback', $blocks);
    }

    private function safeCode(string $code): string
    {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) === 1 ? $code : 'default';
    }
}
