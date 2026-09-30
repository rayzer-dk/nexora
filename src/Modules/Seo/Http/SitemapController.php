<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use Doctrine\DBAL\Connection;
use DOMDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    /** Draft and scheduled articles must never be advertised: their route rows exist before publication. */
    public const VISIBLE_ARTICLE="(sr.entity_type<>'blog_article' OR EXISTS (SELECT 1 FROM mc_content_entry pe JOIN mc_content_translation pt ON pt.content_id=pe.id AND pt.locale=sr.locale WHERE pe.public_id=sr.entity_public_id AND pe.store_id=sr.store_id AND pe.status='published' AND (pe.published_at IS NULL OR pe.published_at<=UTC_TIMESTAMP(6))))";

    private const PAGE_SIZE=20000;
    public function __construct(private readonly Connection $db,private readonly string $publicBaseUrl,private readonly SystemPageRouteCatalog $systemPages){}

    #[Route('/robots.txt',name:'public_robots_txt',methods:['GET'])]
    public function robots(): Response
    {
        $body="User-agent: *\nDisallow: /admin\nDisallow: /checkout\nDisallow: /account\nDisallow: /api\nDisallow: /graphql\nSitemap: ".$this->absolute('/sitemap.xml')."\n";
        return new Response($body,200,['Content-Type'=>'text/plain; charset=UTF-8','Cache-Control'=>'public, max-age=3600']);
    }

    #[Route('/sitemap.xml',name:'public_sitemap_index',methods:['GET'])]
    public function index(): Response
    {
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$root=$xml->createElement('sitemapindex');$root->setAttribute('xmlns','http://www.sitemaps.org/schemas/sitemap/0.9');$xml->appendChild($root);
        $contexts=$this->db->fetchAllAssociative("SELECT s.id,s.code,sl.locale_code FROM mc_store s JOIN mc_store_locale sl ON sl.store_id=s.id AND sl.enabled=1 WHERE s.status='active' ORDER BY s.id,sl.locale_code");
        foreach($contexts as $ctx){$count=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_seo_route sr WHERE sr.store_id=? AND sr.locale=? AND sr.indexable=1 AND '.self::VISIBLE_ARTICLE,[(int)$ctx['id'],(string)$ctx['locale_code']]);if($count<1)continue;$pages=(int)ceil($count/self::PAGE_SIZE);for($page=1;$page<=$pages;$page++){$s=$xml->createElement('sitemap');$root->appendChild($s);$this->el($xml,$s,'loc',$this->absolute('/sitemaps/'.rawurlencode((string)$ctx['code']).'/'.rawurlencode((string)$ctx['locale_code']).'/'.$page.'.xml'));}}
        return $this->xml($xml,600);
    }

    #[Route('/sitemaps/{storeCode}/{locale}/{page}.xml',name:'public_sitemap_page',methods:['GET'],requirements:['storeCode'=>'[A-Za-z0-9_-]+','locale'=>'[A-Za-z0-9_-]+','page'=>'\\d+'])]
    public function page(string $storeCode,string $locale,int $page): Response
    {
        if($page<1)throw $this->createNotFoundException();$storeId=(int)$this->db->fetchOne("SELECT id FROM mc_store WHERE code=? AND status='active'",[$storeCode]);if($storeId<1)throw $this->createNotFoundException();$offset=($page-1)*self::PAGE_SIZE;
        $rows=$this->db->fetchAllAssociative("SELECT sr.entity_type,sr.path,COALESCE(p.updated_at,c.updated_at,b.updated_at,ce.updated_at,sr.updated_at) lastmod
            FROM mc_seo_route sr
            LEFT JOIN mc_product p ON sr.entity_type='product' AND p.public_id=sr.entity_public_id
            LEFT JOIN mc_category c ON sr.entity_type='category' AND c.public_id=sr.entity_public_id
            LEFT JOIN mc_brand b ON sr.entity_type='brand' AND b.public_id=sr.entity_public_id
            LEFT JOIN mc_content_entry ce ON sr.entity_type IN ('cms_page','blog_article','landing_page') AND ce.public_id=sr.entity_public_id
            WHERE sr.store_id=? AND sr.locale=? AND sr.indexable=1 AND ".self::VISIBLE_ARTICLE." ORDER BY sr.id LIMIT ".self::PAGE_SIZE.' OFFSET '.$offset,[$storeId,$locale]);
        if($page===1)$rows=[...$this->systemPageRows($storeId,$locale),...$rows];
        if($rows===[]&&$page>1)throw $this->createNotFoundException();
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$root=$xml->createElement('urlset');$root->setAttribute('xmlns','http://www.sitemaps.org/schemas/sitemap/0.9');$xml->appendChild($root);foreach($rows as $row){$u=$xml->createElement('url');$root->appendChild($u);$this->el($xml,$u,'loc',$this->absolute('/'.ltrim((string)$row['path'],'/')));if(!empty($row['lastmod']))$this->el($xml,$u,'lastmod',substr((string)$row['lastmod'],0,10));}
        return $this->xml($xml,600);
    }
    /**
     * Home, catalog, blog index and published information pages are system routes, not
     * mc_seo_route rows, so they were missing from the sitemap entirely.
     *
     * @return list<array{entity_type:string,path:string,lastmod:?string}>
     */
    private function systemPageRows(int $storeId,string $locale): array
    {
        $rows=[];
        $add=function(string $key,?string $lastmod)use(&$rows,$locale):void{
            try{$route=$this->systemPages->route($key,$locale);}catch(\InvalidArgumentException){return;}
            if($route->indexable)$rows[]=['entity_type'=>'system','path'=>$route->path,'lastmod'=>$lastmod];
        };
        $add('home',null);
        $add('catalog',(string)($this->db->fetchOne('SELECT MAX(p.updated_at) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?',[$storeId])?:'')?:null);
        $latestArticle=$this->db->fetchOne("SELECT MAX(ce.updated_at) FROM mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=? WHERE ce.store_id=? AND ce.content_type='article' AND ce.status='published'",[$locale,$storeId]);
        if($latestArticle!==false&&$latestArticle!==null)$add('blog',(string)$latestArticle);
        foreach($this->db->fetchAllAssociative("SELECT c.slug,MAX(ce.updated_at) lastmod FROM mc_blog_category c JOIN mc_blog_article_meta bm ON bm.category_id=c.id JOIN mc_content_entry ce ON ce.id=bm.content_id JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=? WHERE c.store_id=? AND c.status='active' AND ce.status='published' AND (ce.published_at IS NULL OR ce.published_at<=UTC_TIMESTAMP(6)) GROUP BY c.id,c.slug ORDER BY c.sort_order,c.id",[$locale,$storeId]) as $cat)$rows[]=['entity_type'=>'system','path'=>'blog/category/'.$cat['slug'],'lastmod'=>(string)$cat['lastmod']];
        $pages=$this->db->fetchAllAssociative("SELECT ce.system_key,ce.updated_at FROM mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=? WHERE ce.store_id=? AND ce.content_type='page' AND ce.status='published' AND ce.system_key IS NOT NULL AND TRIM(COALESCE(ct.body_html,''))<>'' ORDER BY ce.id",[$locale,$storeId]);
        foreach($pages as $pageRow)$add((string)$pageRow['system_key'],(string)$pageRow['updated_at']);
        return $rows;
    }

    private function xml(DOMDocument $xml,int $maxAge):Response{return new Response($xml->saveXML()?:'',200,['Content-Type'=>'application/xml; charset=UTF-8','Cache-Control'=>'public, max-age='.$maxAge.', stale-while-revalidate=600','X-Content-Type-Options'=>'nosniff']);}
    private function el(DOMDocument $xml,\DOMElement $parent,string $name,string $value):void{$n=$xml->createElement($name);$n->appendChild($xml->createTextNode($value));$parent->appendChild($n);}private function absolute(string $path):string{return rtrim($this->publicBaseUrl,'/').'/'.ltrim($path,'/');}
}
