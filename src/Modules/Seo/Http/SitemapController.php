<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Doctrine\DBAL\Connection;
use DOMDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    private const PAGE_SIZE=20000;
    public function __construct(private readonly Connection $db,private readonly string $publicBaseUrl){}

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
        foreach($contexts as $ctx){$count=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_seo_route WHERE store_id=? AND locale=? AND indexable=1',[(int)$ctx['id'],(string)$ctx['locale_code']]);if($count<1)continue;$pages=(int)ceil($count/self::PAGE_SIZE);for($page=1;$page<=$pages;$page++){$s=$xml->createElement('sitemap');$root->appendChild($s);$this->el($xml,$s,'loc',$this->absolute('/sitemaps/'.rawurlencode((string)$ctx['code']).'/'.rawurlencode((string)$ctx['locale_code']).'/'.$page.'.xml'));}}
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
            WHERE sr.store_id=? AND sr.locale=? AND sr.indexable=1 ORDER BY sr.id LIMIT ".self::PAGE_SIZE.' OFFSET '.$offset,[$storeId,$locale]);
        if($rows===[]&&$page>1)throw $this->createNotFoundException();
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$root=$xml->createElement('urlset');$root->setAttribute('xmlns','http://www.sitemaps.org/schemas/sitemap/0.9');$xml->appendChild($root);foreach($rows as $row){$u=$xml->createElement('url');$root->appendChild($u);$this->el($xml,$u,'loc',$this->absolute('/'.ltrim((string)$row['path'],'/')));if(!empty($row['lastmod']))$this->el($xml,$u,'lastmod',substr((string)$row['lastmod'],0,10));}
        return $this->xml($xml,600);
    }
    private function xml(DOMDocument $xml,int $maxAge):Response{return new Response($xml->saveXML()?:'',200,['Content-Type'=>'application/xml; charset=UTF-8','Cache-Control'=>'public, max-age='.$maxAge.', stale-while-revalidate=600','X-Content-Type-Options'=>'nosniff']);}
    private function el(DOMDocument $xml,\DOMElement $parent,string $name,string $value):void{$n=$xml->createElement($name);$n->appendChild($xml->createTextNode($value));$parent->appendChild($n);}private function absolute(string $path):string{return rtrim($this->publicBaseUrl,'/').'/'.ltrim($path,'/');}
}
