<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

final class SafeLayoutProvider
{
    /** @return array<string,mixed> */
    public function storefront(): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['id'=>'hero','component'=>'hero','enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]],
            ['id'=>'categories','component'=>'category_grid','enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]],
            ['id'=>'products','component'=>'product_grid','enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]],
            ['id'=>'articles','component'=>'article_grid','enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]],
        ]];
    }

    /** @return array<string,mixed> */
    public function cart(): array
    {
        $blocks = [];
        foreach (['cart_heading','cart_lines','cart_summary','cart_saved','cart_recent'] as $component) {
            $blocks[] = ['id'=>$component,'component'=>$component,'enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]];
        }
        return ['schema_version' => 1, 'blocks' => $blocks];
    }

    /** @return array<string,mixed> */
    public function category(): array
    {
        $blocks = [];
        foreach (['category_heading','category_filters','category_toolbar','category_recommended','category_grid','category_description'] as $component) {
            $blocks[] = ['id'=>$component,'component'=>$component,'enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]];
        }
        return ['schema_version' => 1, 'blocks' => $blocks];
    }

    /** @return array<string,mixed> */
    public function checkout(): array
    {
        $components = ['checkout_contact','checkout_shipping','checkout_company','checkout_comment','checkout_payment','checkout_coupon','checkout_summary','checkout_consent'];
        $blocks = [];
        foreach ($components as $component) {
            $blocks[] = ['id'=>$component,'component'=>$component,'enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]];
        }
        return ['schema_version' => 1, 'blocks' => $blocks];
    }

    /** @return array<string,mixed> */
    public function product(): array
    {
        $components = ['product_gallery','product_title','product_price','product_stock','product_variants','product_buy','product_description','product_attributes','product_documents','product_reviews','related_products'];
        $blocks = [];
        foreach ($components as $component) {
            $blocks[] = ['id'=>$component,'component'=>$component,'enabled'=>true,'props'=>[],'style'=>[],'visibility'=>[]];
        }
        return ['schema_version' => 1, 'blocks' => $blocks];
    }
}
