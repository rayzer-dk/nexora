<?php

declare(strict_types=1);
namespace Commerce\Modules\Navigation\Application;

use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;

final class NavigationManager
{
    public function __construct(private readonly Connection $db,private readonly PublicIdFactory $ids){}

    /** @return list<array<string,mixed>> */
    public function items(int $storeId,string $menuCode,string $locale):array
    {
        $rows=$this->db->fetchAllAssociative("SELECT n.id,n.parent_id,n.item_type,n.target_ref,n.url,n.open_new_tab,COALESCE(t.label,tf.label,'') label,COALESCE(t.badge,tf.badge) badge FROM mc_navigation_item n LEFT JOIN mc_navigation_item_translation t ON t.navigation_item_id=n.id AND t.locale=? LEFT JOIN mc_navigation_item_translation tf ON tf.navigation_item_id=n.id AND tf.locale=(SELECT default_locale FROM mc_store WHERE id=n.store_id) WHERE n.store_id=? AND n.menu_code=? AND n.status='active' ORDER BY n.parent_id IS NOT NULL,n.sort_order,n.id",[$locale,$storeId,$menuCode]);
        $byParent=[];foreach($rows as $r){$r['id']=(int)$r['id'];$r['parent_id']=$r['parent_id']!==null?(int)$r['parent_id']:null;$r['url']=$this->resolveUrl($storeId,$locale,$r);$r['children']=[];$byParent[$r['parent_id']??0][]=$r;}
        $build=function(int $parent,int $depth=0)use(&$build,&$byParent):array{if($depth>4)return[];$out=[];foreach($byParent[$parent]??[] as $r){$r['children']=$build((int)$r['id'],$depth+1);$out[]=$r;}return$out;};return$build(0);
    }

    /** @param array<string,mixed> $data @param array<string,string> $labels */
    public function save(int $storeId,?int $id,array $data,array $labels):int
    {
        $menu=in_array((string)($data['menu_code']??''),['header','footer','utility'],true)?(string)$data['menu_code']:'header';$type=in_array((string)($data['item_type']??''),['custom','category','product','page'],true)?(string)$data['item_type']:'custom';$url=$this->cleanUrl((string)($data['url']??''));$target=mb_substr(trim((string)($data['target_ref']??'')),0,255);$parent=max(0,(int)($data['parent_id']??0));$sort=(int)($data['sort_order']??0);$now=gmdate('Y-m-d H:i:s.u');
        if($parent>0){$parentRow=$this->db->fetchAssociative('SELECT id,parent_id FROM mc_navigation_item WHERE id=? AND store_id=? AND menu_code=?',[$parent,$storeId,$menu]);if(!$parentRow)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.batkivskyi_punkt_ne_nalezhyt_tsomu_meniu'));if($id&&$this->wouldCreateCycle($storeId,$id,$parent))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.nemozhlyvo_stvoryty_tsyklichnu_vkladenist_meniu'));}
        return$this->db->transactional(function(Connection $db)use($storeId,$id,$menu,$type,$url,$target,$parent,$sort,$now,$labels,$data):int{$payload=['store_id'=>$storeId,'menu_code'=>$menu,'parent_id'=>$parent>0?$parent:null,'item_type'=>$type,'target_ref'=>$target!==''?$target:null,'url'=>$url!==''?$url:null,'status'=>($data['status']??'active')==='disabled'?'disabled':'active','sort_order'=>$sort,'open_new_tab'=>!empty($data['open_new_tab'])?1:0,'updated_at'=>$now];if($id&&$db->fetchOne('SELECT id FROM mc_navigation_item WHERE id=? AND store_id=?',[$id,$storeId])!==false){if($parent===$id)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.punkt_meniu_ne_mozhe_buty_batkom_samoho_sebe'));$db->update('mc_navigation_item',$payload,['id'=>$id]);}else{$payload['public_id']=$this->ids->binary();$payload['created_at']=$now;$db->insert('mc_navigation_item',$payload);$id=(int)$db->lastInsertId();}foreach($labels as $locale=>$label){$label=mb_substr(trim(strip_tags($label)),0,190);if($label==='')continue;$exists=$db->fetchOne('SELECT navigation_item_id FROM mc_navigation_item_translation WHERE navigation_item_id=? AND locale=?',[$id,$locale]);$row=['label'=>$label,'badge'=>null];if($exists!==false)$db->update('mc_navigation_item_translation',$row,['navigation_item_id'=>$id,'locale'=>$locale]);else$db->insert('mc_navigation_item_translation',['navigation_item_id'=>$id,'locale'=>$locale]+$row);}return$id;});
    }
    public function delete(int $storeId,int $id):void{$this->db->delete('mc_navigation_item',['id'=>$id,'store_id'=>$storeId]);}

    private function wouldCreateCycle(int $storeId,int $id,int $parentId): bool
    {
        $seen=[];$cursor=$parentId;$depth=0;
        while($cursor>0&&$depth++<20){if($cursor===$id)return true;if(isset($seen[$cursor]))return true;$seen[$cursor]=true;$next=$this->db->fetchOne('SELECT parent_id FROM mc_navigation_item WHERE id=? AND store_id=?',[$cursor,$storeId]);if($next===false||$next===null)return false;$cursor=(int)$next;}
        return $cursor>0;
    }
    private function cleanUrl(string $url):string{$url=trim($url);if($url==='')return'';if(str_starts_with($url,'/'))return mb_substr($url,0,1000);if(filter_var($url,FILTER_VALIDATE_URL)&&preg_match('#^https://#i',$url)===1)return mb_substr($url,0,1000);throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.url_maie_buty_vnutrishnim_shliakhom_abo_https_posyla'));}
    /** @param array<string,mixed> $row */ private function resolveUrl(int $storeId,string $locale,array $row):string{$type=(string)$row['item_type'];$ref=(string)($row['target_ref']??'');if($type==='custom')return(string)($row['url']??'#');if($type==='page'&&$ref!=='')return'/'.ltrim($ref,'/');if(in_array($type,['category','product'],true)&&$ref!==''){try{$bin=\Symfony\Component\Uid\Uuid::fromString($ref)->toBinary();$path=$this->db->fetchOne('SELECT path FROM mc_seo_route WHERE store_id=? AND locale=? AND entity_type=? AND entity_public_id=? LIMIT 1',[$storeId,$locale,$type,$bin]);if($path!==false)return'/'.ltrim((string)$path,'/');}catch(\Throwable){}}return(string)($row['url']??'#');}
}
