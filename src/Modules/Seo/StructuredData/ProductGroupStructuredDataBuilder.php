<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;

final class ProductGroupStructuredDataBuilder
{
    public function __construct(private readonly ProductMerchantListingBuilder $productBuilder) {}
    public function build(array $group): array
    {
        $data=['@type'=>'ProductGroup','name'=>(string)$group['name'],'productGroupID'=>(string)$group['group_id'],'variesBy'=>array_values((array)($group['varies_by']??[]))];
        if(!empty($group['brand'])) $data['brand']=['@type'=>'Brand','name'=>(string)$group['brand']];
        if(!empty($group['url'])) $data['url']=(string)$group['url'];
        $variants=[];
        foreach((array)($group['variants']??[]) as $variant){$v=$this->productBuilder->build($variant); unset($v['@context']); $variants[]=$v;}
        if($variants!==[]) $data['hasVariant']=$variants;
        return $data;
    }
}
