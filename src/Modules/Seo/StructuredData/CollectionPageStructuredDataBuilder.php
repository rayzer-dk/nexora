<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;
final class CollectionPageStructuredDataBuilder
{
    /** @param list<array{name:string,url:string}> $items */
    public function build(string $name,string $url,array $items): array
    {
        $list=[];foreach($items as $i=>$item){$list[]=['@type'=>'ListItem','position'=>$i+1,'name'=>$item['name'],'url'=>$item['url']];}
        return ['@type'=>'CollectionPage','name'=>$name,'url'=>$url,'mainEntity'=>['@type'=>'ItemList','itemListElement'=>$list]];
    }
}
