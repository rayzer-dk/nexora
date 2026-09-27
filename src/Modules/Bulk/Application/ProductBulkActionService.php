<?php

declare(strict_types=1);

namespace Commerce\Modules\Bulk\Application;

use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Application\ProductEditQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class ProductBulkActionService
{
    public function __construct(
        private ProductEditQueryInterface $query,
        private ProductWriter $writer,
        private Connection $db,
    ) {}

    /** @param list<string> $publicIds @return array{requested:int,updated:int,failed:int,errors:list<string>} */
    public function setStatus(int $storeId,int $marketId,string $locale,array $publicIds,string $status): array
    {
        if (!in_array($status,['draft','published','archived'],true)) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5a4ea61f1106'));
        $publicIds=array_values(array_unique(array_filter(array_map('trim',$publicIds))));
        if (count($publicIds)>200) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.bulk.application.productbulkactionservice.za_odyn_zapusk_mozhna_zminyty_ne_bilshe_200_tovariv'));
        $stats=['requested'=>count($publicIds),'updated'=>0,'failed'=>0,'errors'=>[]];
        foreach($publicIds as $publicId){
            try{
                $p=$this->query->productForEdit($storeId,$marketId,$locale,$publicId);
                $this->writer->update(new UpdateProductCommand(
                    productId:(int)$p['id'],storeId:$storeId,marketId:$marketId,locale:$locale,name:(string)$p['name'],sku:(string)$p['sku'],priceMinor:(int)($p['amount_minor']??0),currency:(string)($p['currency']??$this->marketCurrency($storeId,$marketId)),stockQuantity:(string)($p['stock_quantity']??'0.000000'),unitCode:(string)($p['sale_unit_code']??'item'),categoryIds:$p['category_ids'],manualSlug:(string)($p['slug']??''),shortDescription:$p['short_description']!==null?(string)$p['short_description']:null,description:$p['description']!==null?(string)$p['description']:null,gtin:$p['gtin']!==null?(string)$p['gtin']:null,mpn:$p['mpn']!==null?(string)$p['mpn']:null,status:$status,brandId:$p['brand_id']!==null?(int)$p['brand_id']:null,
                )); ++$stats['updated'];
            } catch(\Throwable $e){ ++$stats['failed']; if(count($stats['errors'])<20)$stats['errors'][]=$publicId.': '.$e->getMessage(); }
        }
        return $stats;
    }

    private function marketCurrency(int $storeId,int $marketId):string
    {
        $currency=strtoupper((string)$this->db->fetchOne('SELECT default_currency FROM mc_market WHERE id=? AND store_id=? LIMIT 1',[$marketId,$storeId]));
        if(preg_match('/^[A-Z]{3}$/D',$currency)!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fd304e05df9f'));
        return $currency;
    }
}
