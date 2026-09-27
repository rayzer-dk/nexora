<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

/**
 * Admin schema is configuration, never a hard runtime dependency. If a broken
 * schema is deployed, administrators still get a minimal editor instead of a
 * white screen and can repair/rollback the configuration.
 */
final readonly class ProductEditorSchema
{
    private const SUPPORTED_SCHEMA = [1, 2, 3];

    public function __construct(private string $schemaPath)
    {
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        try {
            if (!is_file($this->schemaPath) || !is_readable($this->schemaPath)) {
                return $this->fallback();
            }
            $json = @file_get_contents($this->schemaPath);
            if (!is_string($json) || $json === '' || strlen($json) > 2 * 1024 * 1024) {
                return $this->fallback();
            }
            $schema = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || !in_array(($schema['schema_version'] ?? null), self::SUPPORTED_SCHEMA, true) || !is_array($schema['groups'] ?? null)) {
                return $this->fallback();
            }

            $groups = [];
            $seen = [];
            foreach ($schema['groups'] as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $code = strtolower(trim((string) ($group['code'] ?? '')));
                $labelKey = trim((string) ($group['label_key'] ?? ''));
                $label = $labelKey !== '' ? \Commerce\Core\I18n\CanonicalUiText::get($labelKey) : trim((string) ($group['label'] ?? ''));
                if ($code === '' || $label === '' || isset($seen[$code]) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
                    continue;
                }
                $fields = [];
                foreach ((array) ($group['fields'] ?? []) as $field) {
                    $field = trim((string) $field);
                    if ($field !== '' && preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $field) === 1) {
                        $fields[$field] = true;
                    }
                }
                if ($fields === []) {
                    continue;
                }
                $seen[$code] = true;
                $groups[] = ['code'=>$code,'label'=>mb_substr($label,0,190,'UTF-8'),'fields'=>array_keys($fields)];
            }
            if ($groups === []) {
                return $this->fallback();
            }
            $schema['groups'] = $groups;
            return $schema;
        } catch (\Throwable) {
            return $this->fallback();
        }
    }

    /** @return array{schema_version:int,groups:list<array{code:string,label:string,fields:list<string>}>} */
    private function fallback(): array
    {
        return [
            'schema_version' => 3,
            'groups' => [
                ['code'=>'identity','label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.osnovne'),'fields'=>['product_type','status','brand_id','sku','gtin','mpn']],
                ['code'=>'content','label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.kontent'),'fields'=>['name','short_description','description']],
                ['code'=>'pricing_tax','label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.tsina'),'fields'=>['price_lists','tax_class_id']],
                ['code'=>'inventory','label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.zalyshky'),'fields'=>['manage_inventory','inventory_items']],
                ['code'=>'media','label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.media'),'fields'=>['gallery','alt_text']],
                ['code'=>'seo','label'=>'SEO','fields'=>['seo_slug','meta_title','meta_description','indexing']],
            ],
        ];
    }
}
