<?php

declare(strict_types=1);
namespace Commerce\Modules\Cart\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class SavedCartService
{
    public function __construct(private readonly Connection $db,private readonly PublicIdFactory $ids,private readonly CartMutationService $cartMutations){}

    public function bindCustomer(int $cartId,int $storeId,int $customerId):void
    {
        $this->db->executeStatement("UPDATE mc_cart SET customer_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND store_id=? AND status='active'",[$customerId,$cartId,$storeId]);
    }

    public function save(int $cartId,StorefrontContext $ctx,int $customerId,string $name):string
    {
        $name=mb_substr(trim(strip_tags($name)),0,190);if($name==='')$name=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.zberezhenyi_koshyk');
        $count=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_saved_cart WHERE store_id=? AND customer_id=? AND status='active'",[$ctx->storeId,$customerId]);
        if($count>=25)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.dosiahnuto_limit_u_25_zberezhenykh_koshykiv_vydalit_'));
        $cart=$this->db->fetchAssociative("SELECT id,currency FROM mc_cart WHERE id=? AND store_id=? AND status='active' LIMIT 1",[$cartId,$ctx->storeId]);if(!is_array($cart))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.aktyvnyi_koshyk_ne_znaideno'));
        $items=$this->db->fetchAllAssociative('SELECT ci.variant_id,ci.quantity,v.product_id,v.sku FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.cart_id=? ORDER BY ci.id',[$cartId]);if($items===[])throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.porozhnii_koshyk_ne_mozhna_zberehty'));
        return $this->db->transactional(function(Connection $db)use($ctx,$customerId,$name,$cart,$items):string{$uuid=$this->ids->generate();$now=gmdate('Y-m-d H:i:s.u');$db->insert('mc_saved_cart',['public_id'=>$uuid->toBinary(),'store_id'=>$ctx->storeId,'customer_id'=>$customerId,'name'=>$name,'currency'=>(string)$cart['currency'],'status'=>'active','created_at'=>$now,'updated_at'=>$now]);$id=(int)$db->lastInsertId();foreach($items as $i){$db->insert('mc_saved_cart_item',['saved_cart_id'=>$id,'product_id'=>(int)$i['product_id'],'variant_id'=>(int)$i['variant_id'],'quantity'=>(string)$i['quantity'],'snapshot'=>json_encode(['sku'=>(string)$i['sku']],JSON_THROW_ON_ERROR)]);}return$uuid->toRfc4122();});
    }

    /** @return list<array<string,mixed>> */
    public function list(int $storeId,int $customerId):array
    {
        $rows=$this->db->fetchAllAssociative("SELECT sc.public_id,sc.name,sc.currency,sc.updated_at,COUNT(sci.id) item_count FROM mc_saved_cart sc LEFT JOIN mc_saved_cart_item sci ON sci.saved_cart_id=sc.id WHERE sc.store_id=? AND sc.customer_id=? AND sc.status='active' GROUP BY sc.id ORDER BY sc.updated_at DESC",[$storeId,$customerId]);foreach($rows as &$r){$r['public_id']=Uuid::fromBinary((string)$r['public_id'])->toRfc4122();$r['item_count']=(int)$r['item_count'];}unset($r);return$rows;
    }

    /** @return array{restored:int,skipped:int} */
    public function restore(string $publicId,StorefrontContext $ctx,int $customerId,int $cartId):array
    {
        try{$bin=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.zberezhenyi_koshyk_ne_znaideno'));}
        $saved=$this->db->fetchAssociative("SELECT id FROM mc_saved_cart WHERE public_id=? AND store_id=? AND customer_id=? AND status='active' LIMIT 1",[$bin,$ctx->storeId,$customerId]);if(!is_array($saved))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.zberezhenyi_koshyk_ne_znaideno'));
        $items=$this->db->fetchAllAssociative('SELECT sci.quantity,v.public_id FROM mc_saved_cart_item sci JOIN mc_product_variant v ON v.id=sci.variant_id WHERE sci.saved_cart_id=? ORDER BY sci.id',[(int)$saved['id']]);
        $restored=0;$skipped=0;foreach($items as $i){try{$this->cartMutations->add($ctx,$cartId,Uuid::fromBinary((string)$i['public_id'])->toRfc4122(),(string)$i['quantity']);$restored++;}catch(\DomainException){$skipped++;}}
        return ['restored'=>$restored,'skipped'=>$skipped];
    }


    public function rename(string $publicId,int $storeId,int $customerId,string $name):void
    {
        $name=mb_substr(trim(strip_tags($name)),0,190);
        if($name==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.vkazhit_nazvu_zberezhenoho_koshyka'));
        try{$bin=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.zberezhenyi_koshyk_ne_znaideno'));}
        $updated=$this->db->executeStatement("UPDATE mc_saved_cart SET name=?,updated_at=UTC_TIMESTAMP(6) WHERE public_id=? AND store_id=? AND customer_id=? AND status='active'",[$name,$bin,$storeId,$customerId]);
        if($updated!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.savedcartservice.zberezhenyi_koshyk_ne_znaideno'));
    }

    public function delete(string $publicId,int $storeId,int $customerId):void
    {
        try{$bin=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return;}$this->db->executeStatement('DELETE FROM mc_saved_cart WHERE public_id=? AND store_id=? AND customer_id=?',[$bin,$storeId,$customerId]);
    }
}
