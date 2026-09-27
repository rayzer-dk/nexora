<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;

final class BreadcrumbListBuilder
{
    /** @param list<array{name:string,url?:string}> $items */
    public function build(array $items): array
    {
        $elements=[];
        foreach($items as $i=>$item){
            $row=['@type'=>'ListItem','position'=>$i+1,'name'=>(string)$item['name']];
            if(!empty($item['url'])) $row['item']=(string)$item['url'];
            $elements[]=$row;
        }
        return ['@type'=>'BreadcrumbList','itemListElement'=>$elements];
    }
}
