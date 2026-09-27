<?php

declare(strict_types=1);
namespace Commerce\Modules\Appearance\Builder;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class LayoutSnippetStore
{
    public function __construct(private Connection $db, private LayoutSchemaValidator $validator) {}

    /** @return list<array<string,mixed>> */
    public function all(int $storeId, string $type): array
    {
        $rows=$this->db->fetchAllAssociative('SELECT id,name,layout_type,payload,updated_at FROM mc_layout_snippet WHERE store_id=? AND (layout_type=? OR layout_type=?) ORDER BY name,id',[$storeId,$type,'any']);
        $out=[];
        foreach($rows as $row){
            try{$payload=json_decode((string)$row['payload'],true,16,JSON_THROW_ON_ERROR);if(!is_array($payload))continue;$layout=$this->validator->validate(['schema_version'=>1,'blocks'=>[$payload]]);$row['block']=$layout['blocks'][0]??null;if($row['block'])$out[]=$row;}catch(\Throwable){}
        }
        return$out;
    }

    /** @param array<string,mixed> $block */
    public function save(int $storeId,string $type,string $name,array $block,?int $adminId): int
    {
        $name=mb_substr(trim(strip_tags($name)),0,190);
        if($name==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.builder.layoutsnippetstore.vkazhit_nazvu_sektsii'));
        $validated=$this->validator->validate(['schema_version'=>1,'blocks'=>[$block]]);
        $safe=$validated['blocks'][0]??null;if(!is_array($safe))throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.builder.layoutsnippetstore.sektsiia_nekorektna'));
        $now=gmdate('Y-m-d H:i:s.u');$json=json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $id=$this->db->fetchOne('SELECT id FROM mc_layout_snippet WHERE store_id=? AND name=?',[$storeId,$name]);
        if($id!==false){$this->db->update('mc_layout_snippet',['layout_type'=>$type,'payload'=>$json,'updated_at'=>$now],['id'=>(int)$id]);return(int)$id;}
        $this->db->insert('mc_layout_snippet',['store_id'=>$storeId,'name'=>$name,'layout_type'=>$type,'payload'=>$json,'created_by'=>$adminId,'created_at'=>$now,'updated_at'=>$now]);
        return(int)$this->db->lastInsertId();
    }

    public function delete(int $storeId,int $id): void {$this->db->delete('mc_layout_snippet',['id'=>$id,'store_id'=>$storeId]);}
}
