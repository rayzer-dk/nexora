<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;

final class OrganizationCommerceBuilder
{
    public function build(array $merchant): array
    {
        $data=['@type'=>'Organization','@id'=>rtrim((string)$merchant['url'],'/').'#organization','name'=>(string)$merchant['name'],'url'=>(string)$merchant['url']];
        if(!empty($merchant['logo'])) $data['logo']=['@type'=>'ImageObject','url'=>$merchant['logo']];
        if(!empty($merchant['same_as'])) $data['sameAs']=array_values((array)$merchant['same_as']);
        if(!empty($merchant['return_policy'])) $data['hasMerchantReturnPolicy']=$merchant['return_policy'];
        if(!empty($merchant['shipping_services'])) $data['hasShippingService']=array_values((array)$merchant['shipping_services']);
        if(!empty($merchant['loyalty_program'])) $data['hasMemberProgram']=$merchant['loyalty_program'];
        return $data;
    }
}
