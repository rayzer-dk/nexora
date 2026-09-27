<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalPublicApiQuery
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function customers(int $storeId, int $page, int $limit, string $search): array
    {
        $where = ['EXISTS (SELECT 1 FROM mc_sales_order so WHERE so.customer_id=c.id AND so.store_id=?)']; $params = [$storeId];
        if ($search !== '') { $where[] = '(c.email_normalized LIKE ? OR c.phone_e164 LIKE ? OR c.display_name LIKE ?)'; $needle='%'.mb_strtolower($search,'UTF-8').'%'; array_push($params,$needle,$needle,'%'.$search.'%'); }
        $sqlWhere=implode(' AND ',$where); $total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_customer c WHERE '.$sqlWhere,$params);
        $offset=($page-1)*$limit;
        $rows=$this->db->fetchAllAssociative('SELECT c.public_id,c.email,c.phone_e164,c.display_name,c.locale,c.status,c.created_at,c.updated_at FROM mc_customer c WHERE '.$sqlWhere.' ORDER BY c.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        foreach($rows as &$row){$row['public_id']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();}
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))];
    }

    /** @return array<string,mixed>|null */
    public function customer(int $storeId,string $publicId): ?array
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return null;}
        $row=$this->db->fetchAssociative('SELECT c.public_id,c.email,c.phone_e164,c.display_name,c.locale,c.status,c.created_at,c.updated_at FROM mc_customer c WHERE c.public_id=? AND EXISTS (SELECT 1 FROM mc_sales_order so WHERE so.customer_id=c.id AND so.store_id=?) LIMIT 1',[$binary,$storeId]);
        if(!is_array($row))return null; $row['public_id']=$publicId; return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function orders(int $storeId,int $page,int $limit,string $status,string $search): array
    {
        $where=['so.store_id=?'];$params=[$storeId];
        if($status!==''){$where[]='so.status=?';$params[]=$status;}
        if($search!==''){$where[]='(so.order_number LIKE ? OR so.customer_email_normalized LIKE ? OR so.customer_phone LIKE ?)';$needle='%'.$search.'%';array_push($params,$needle,mb_strtolower($needle,'UTF-8'),$needle);}
        $sqlWhere=implode(' AND ',$where);$total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_sales_order so WHERE '.$sqlWhere,$params);$offset=($page-1)*$limit;
        $rows=$this->db->fetchAllAssociative('SELECT so.public_id,so.order_number,so.status,so.payment_status,so.fulfillment_status,so.currency,so.subtotal_minor,so.discount_minor,so.shipping_minor,so.tax_minor,so.total_minor,so.customer_email,so.customer_phone,so.customer_name,so.locale,so.created_at,so.updated_at FROM mc_sales_order so WHERE '.$sqlWhere.' ORDER BY so.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        foreach($rows as &$row){$row['public_id']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$this->ints($row,['subtotal_minor','discount_minor','shipping_minor','tax_minor','total_minor']);}
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))];
    }

    /** @return array<string,mixed>|null */
    public function order(int $storeId,string $publicId): ?array
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return null;}
        $row=$this->db->fetchAssociative('SELECT id,public_id,order_number,status,payment_status,fulfillment_status,currency,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor,customer_email,customer_phone,customer_name,locale,created_at,updated_at FROM mc_sales_order WHERE store_id=? AND public_id=? LIMIT 1',[$storeId,$binary]);
        if(!is_array($row))return null;$id=(int)$row['id'];unset($row['id']);$row['public_id']=$publicId;$this->ints($row,['subtotal_minor','discount_minor','shipping_minor','tax_minor','total_minor']);
        $items=$this->db->fetchAllAssociative('SELECT sku,name,quantity,unit_price_minor,line_total_minor,tax_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id',[$id]);foreach($items as &$item){$this->ints($item,['quantity','unit_price_minor','line_total_minor','tax_minor']);}$row['items']=$items;return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function carts(int $storeId,int $page,int $limit,string $status): array
    {
        $where=['c.store_id=?'];$params=[$storeId];if($status!==''){$where[]='c.status=?';$params[]=$status;}$sqlWhere=implode(' AND ',$where);$total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_cart c WHERE '.$sqlWhere,$params);$offset=($page-1)*$limit;
        $rows=$this->db->fetchAllAssociative('SELECT c.public_id,c.currency,c.status,c.created_at,c.updated_at,c.expires_at,COUNT(ci.id) item_count,COALESCE(SUM(ci.quantity*ci.unit_price_minor),0) total_minor FROM mc_cart c LEFT JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE '.$sqlWhere.' GROUP BY c.id ORDER BY c.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        foreach($rows as &$row){$row['public_id']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$this->ints($row,['item_count','total_minor']);}
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))];
    }

    /** @return array<string,mixed>|null */
    public function cart(int $storeId,string $publicId): ?array
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return null;}
        $row=$this->db->fetchAssociative('SELECT id,public_id,currency,status,created_at,updated_at,expires_at FROM mc_cart WHERE store_id=? AND public_id=? LIMIT 1',[$storeId,$binary]);if(!is_array($row))return null;$id=(int)$row['id'];unset($row['id']);$row['public_id']=$publicId;
        $items=$this->db->fetchAllAssociative('SELECT pv.public_id,ci.quantity,ci.unit_price_minor,ci.metadata FROM mc_cart_item ci JOIN mc_product_variant pv ON pv.id=ci.variant_id WHERE ci.cart_id=? ORDER BY ci.id',[$id]);$total=0;foreach($items as &$item){$item['variant_public_id']=Uuid::fromBinary((string)$item['public_id'])->toRfc4122();unset($item['public_id']);$item['quantity']=(int)$item['quantity'];$item['unit_price_minor']=(int)$item['unit_price_minor'];$item['line_total_minor']=$item['quantity']*$item['unit_price_minor'];$total+=$item['line_total_minor'];$meta=json_decode((string)($item['metadata']??''),true);$item['metadata']=is_array($meta)?$meta:null;}$row['items']=$items;$row['total_minor']=$total;return $row;
    }


    public function cartInternalId(int $storeId, string $publicId): ?int
    {
        try { $binary=Uuid::fromString($publicId)->toBinary(); } catch (\Throwable) { return null; }
        $id=$this->db->fetchOne("SELECT id FROM mc_cart WHERE store_id=? AND public_id=? AND status='active' AND expires_at>UTC_TIMESTAMP(6) LIMIT 1",[$storeId,$binary]);
        return $id===false?null:(int)$id;
    }

    public function cartPublicIdByInternalId(int $storeId, int $cartId): ?string
    {
        $binary=$this->db->fetchOne('SELECT public_id FROM mc_cart WHERE store_id=? AND id=? LIMIT 1',[$storeId,$cartId]);
        if(!is_string($binary)||$binary==='')return null;
        return Uuid::fromBinary($binary)->toRfc4122();
    }

    public function cartItemIdByVariant(int $storeId, int $cartId, string $variantPublicId): ?int
    {
        try{$variant=Uuid::fromString($variantPublicId)->toBinary();}catch(\Throwable){return null;}
        $id=$this->db->fetchOne('SELECT ci.id FROM mc_cart_item ci JOIN mc_cart c ON c.id=ci.cart_id JOIN mc_product_variant pv ON pv.id=ci.variant_id WHERE c.store_id=? AND c.id=? AND pv.public_id=? LIMIT 1',[$storeId,$cartId,$variant]);
        return $id===false?null:(int)$id;
    }

    public function customerInternalId(int $storeId, string $publicId): ?int
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return null;}
        $id=$this->db->fetchOne('SELECT c.id FROM mc_customer c WHERE c.public_id=? AND c.status=\'active\' AND EXISTS (SELECT 1 FROM mc_sales_order so WHERE so.customer_id=c.id AND so.store_id=?) LIMIT 1',[$binary,$storeId]);
        return $id===false?null:(int)$id;
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function ints(array &$row,array $keys):void{foreach($keys as $key){if(array_key_exists($key,$row))$row[$key]=(int)$row[$key];}}
}
