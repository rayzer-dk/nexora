<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Http;

use Commerce\Modules\Feeds\Application\FeedStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicFeedController extends AbstractController
{
    public function __construct(private readonly Connection $db,private readonly FeedStorageService $storage){}

    #[Route('/feeds/{storeCode}/{platform}',name:'public_product_feed',methods:['GET'],requirements:['storeCode'=>'[A-Za-z0-9_-]+','platform'=>'google|meta|facebook|pinterest|tiktok|rozetka|prom|csv|json|agentic'])]
    public function feed(string $storeCode,string $platform,Request $request): Response
    {
        $platform=$platform==='facebook'?'meta':$platform;
        $store=$this->db->fetchAssociative("SELECT id,default_locale,default_currency FROM mc_store WHERE code=? AND status='active' LIMIT 1",[$storeCode]);if(!is_array($store))throw $this->createNotFoundException();
        $locale=trim((string)$request->query->get('locale',(string)$store['default_locale']));
        $currency=strtoupper(trim((string)$request->query->get('currency','')));if(preg_match('/^[A-Z]{3}$/D',$currency)!==1||$currency===strtoupper((string)$store['default_currency']))$currency='';$stored=$this->storage->latest($storeCode,$platform,$locale,$currency);if($stored===null){$response=new Response('Feed is not generated yet.',Response::HTTP_SERVICE_UNAVAILABLE);$response->headers->set('Retry-After','60');$response->headers->set('Cache-Control','no-store');return $response;}
        $response=new BinaryFileResponse($stored['path']);$response->headers->set('Content-Type',(string)($stored['meta']['content_type']??'application/octet-stream'));$response->headers->set('Cache-Control','public, max-age=300, stale-while-revalidate=600');$response->headers->set('X-Feed-Items',(string)($stored['meta']['count']??0));$response->headers->set('X-Feed-Skipped',(string)($stored['meta']['skipped']??0));$response->headers->set('X-Content-Type-Options','nosniff');return $response;
    }
}
