<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;
final class WebSiteBuilder
{
    public function build(string $name,string $url): array
    {
        return ['@type'=>'WebSite','@id'=>rtrim($url,'/').'#website','url'=>$url,'name'=>$name];
    }
}
