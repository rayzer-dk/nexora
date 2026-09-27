<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;

final class ArticleStructuredDataBuilder
{
    public function build(array $article): array
    {
        $data=['@type'=>'BlogPosting','headline'=>(string)$article['title'],'url'=>(string)$article['url'],'datePublished'=>(string)$article['published_at'],'dateModified'=>(string)($article['modified_at']??$article['published_at'])];
        if(!empty($article['image'])) $data['image']=(array)$article['image'];
        if(!empty($article['author'])) $data['author']=['@type'=>'Person','name'=>(string)$article['author']];
        if(!empty($article['publisher_id'])) $data['publisher']=['@id'=>(string)$article['publisher_id']];
        if(!empty($article['description'])) $data['description']=(string)$article['description'];
        return $data;
    }
}
