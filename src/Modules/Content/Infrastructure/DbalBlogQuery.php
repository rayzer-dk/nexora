<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalBlogQuery
{
    public function __construct(private Connection $connection){}

    /** @return list<array<string,mixed>> */
    public function latest(int $storeId,string $locale,int $limit=12): array
    {
        $limit=max(1,min(50,$limit));
        $rows=$this->connection->fetchAllAssociative(
            "SELECT ce.id,ce.public_id,ce.published_at,ct.title,ct.excerpt,sr.path,
                    em.value_json AS image_meta
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=? AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
             LEFT JOIN mc_entity_metadata em ON em.entity_type='article' AND em.entity_public_id=ce.public_id AND em.namespace=? AND em.meta_key='image'
             WHERE ce.store_id=? AND ce.content_type='article' AND ce.status='published' AND (ce.published_at IS NULL OR ce.published_at<=UTC_TIMESTAMP(6))
             ORDER BY ce.published_at DESC,ce.id DESC LIMIT {$limit}",
            [$locale,$locale,'demo-'.$storeId,$storeId],
        );
        return array_map(function(array $r):array{
            $meta=is_string($r['image_meta']??null)?json_decode($r['image_meta'],true):null;
            return ['public_id'=>Uuid::fromBinary((string)$r['public_id'])->toRfc4122(),'title'=>(string)$r['title'],'excerpt'=>(string)($r['excerpt']??''),'url'=>'/'.ltrim((string)$r['path'],'/'),'date'=>$r['published_at']?date('d.m.Y',strtotime((string)$r['published_at'])):'','image'=>is_array($meta)&&isset($meta['path'])?'/media/'.ltrim((string)$meta['path'],'/'):''];
        },$rows);
    }

    /** @return array<string,mixed>|null */
    public function byPublicId(int $storeId,string $locale,string $publicId): ?array
    {
        $row=$this->connection->fetchAssociative(
            "SELECT ce.public_id,ce.published_at,ce.updated_at,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description,sr.path,
                    em.value_json AS image_meta
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=? AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
             LEFT JOIN mc_entity_metadata em ON em.entity_type='article' AND em.entity_public_id=ce.public_id AND em.namespace=? AND em.meta_key='image'
             WHERE ce.store_id=? AND ce.content_type='article' AND ce.status='published' AND ce.public_id=? LIMIT 1",
            [$locale,$locale,'demo-'.$storeId,$storeId,Uuid::fromString($publicId)->toBinary()],
        );
        if(!is_array($row)){return null;}
        $meta=is_string($row['image_meta']??null)?json_decode($row['image_meta'],true):null;
        return ['public_id'=>$publicId,'title'=>(string)$row['title'],'excerpt'=>(string)($row['excerpt']??''),'body_html'=>(string)($row['body_html']??''),'meta_title'=>(string)($row['meta_title']?:$row['title']),'meta_description'=>(string)($row['meta_description']?:$row['excerpt']),'url'=>'/'.ltrim((string)$row['path'],'/'),'published_at'=>(string)$row['published_at'],'updated_at'=>(string)$row['updated_at'],'image'=>is_array($meta)&&isset($meta['path'])?'/media/'.ltrim((string)$meta['path'],'/'):''];
    }
}
