<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;
final class StructuredDataGraphBuilder
{
    public function build(array ...$nodes): array
    {
        $graph=[];
        foreach($nodes as $node){ if(isset($node['@context'])) unset($node['@context']); if($node!==[]) $graph[]=$node; }
        return ['@context'=>'https://schema.org','@graph'=>$graph];
    }
}
