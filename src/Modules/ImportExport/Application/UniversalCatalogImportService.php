<?php

declare(strict_types=1);

namespace Commerce\Modules\ImportExport\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use RuntimeException;
use ZipArchive;

final readonly class UniversalCatalogImportService
{
    private const MAX_BYTES = 20971520;
    private const MAX_ROWS = 10000;
    private const TARGET_FIELDS = ['sku','name','price','currency','stock_quantity','unit_code','gtin','mpn','brand_id','brand','category_ids','slug','short_description','description','status','product_type'];

    public function __construct(private CatalogCsvService $catalogCsv, private CatalogTranslationImportService $translations, private Connection $db, private string $projectDir) {}

    /** @return list<array<string,mixed>> */
    public function profiles(int $storeId): array
    {
        try {
            if (!$this->db->createSchemaManager()->tablesExist(['mc_import_profile'])) return [];
            return $this->db->fetchAllAssociative('SELECT id,name,source_format,mapping_json,updated_at FROM mc_import_profile WHERE store_id=? ORDER BY name',[$storeId]);
        } catch (\Throwable) { return []; }
    }

    /** @return array<string,string> */
    public function profileMapping(int $storeId,int $id): array
    {
        if($id<1)return [];
        $raw=$this->db->fetchOne('SELECT mapping_json FROM mc_import_profile WHERE id=? AND store_id=?',[$id,$storeId]);
        if(!is_string($raw)||$raw==='')return [];
        try{$map=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return [];}
        if(!is_array($map))return [];$out=[];foreach($map as $k=>$v)if(is_string($k)&&is_string($v)&&in_array($k,self::TARGET_FIELDS,true))$out[$k]=$v;return $out;
    }

    /** @param array<string,string> $mapping */
    public function saveProfile(int $storeId,string $name,string $format,array $mapping): void
    {
        $name=trim($name);if($name===''||mb_strlen($name)>190)return;
        $clean=[];foreach($mapping as $k=>$v)if(in_array($k,self::TARGET_FIELDS,true)&&is_string($v)&&$v!=='')$clean[$k]=$v;
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $existing=$this->db->fetchOne('SELECT id FROM mc_import_profile WHERE store_id=? AND name=?',[$storeId,$name]);
        $data=['source_format'=>in_array($format,['csv','xlsx'],true)?$format:'csv','mapping_json'=>json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'updated_at'=>$now];
        if($existing!==false){$this->db->update('mc_import_profile',$data,['id'=>(int)$existing]);return;}
        $this->db->insert('mc_import_profile',['store_id'=>$storeId,'name'=>$name,...$data,'created_at'=>$now]);
    }

    /** @return array{token:string,format:string,headers:list<string>,sample:list<array<string,string>>,suggestions:array<string,string>,multilingual:array<string,array<string,string>>} */
    public function stage(string $path, string $originalName): array
    {
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) throw new \DomainException(CanonicalUiText::get('import.universal.error.file_size'));
        $format = $this->format($originalName);
        $rows = $format === 'xlsx' ? $this->readXlsx($path, 8) : $this->readCsv($path, 8);
        if ($rows === []) throw new \DomainException(CanonicalUiText::get('import.universal.error.empty'));
        $headers = array_values(array_map(static fn($v)=>trim((string)$v), array_shift($rows)));
        if ($headers === [] || count(array_filter($headers, static fn($v)=>$v!=='')) === 0) throw new \DomainException(CanonicalUiText::get('import.universal.error.no_headers'));
        $token = bin2hex(random_bytes(20));
        $dir = rtrim($this->projectDir, '/\\') . '/var/import-staging';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException(CanonicalUiText::get('import.universal.error.staging_create'));
        $target = $dir . '/' . $token . '.' . $format;
        if (!@copy($path, $target)) throw new RuntimeException(CanonicalUiText::get('import.universal.error.staging_copy'));
        @chmod($target, 0600);
        file_put_contents($dir . '/' . $token . '.json', json_encode(['name'=>$originalName,'format'=>$format,'created'=>time()], JSON_THROW_ON_ERROR));
        @chmod($dir . '/' . $token . '.json', 0600);
        $sample = [];
        foreach ($rows as $row) {
            $assoc=[];foreach($headers as $i=>$header){if($header!=='')$assoc[$header]=(string)($row[$i]??'');}
            $sample[]=$assoc;
        }
        return ['token'=>$token,'format'=>$format,'headers'=>$headers,'sample'=>$sample,'suggestions'=>$this->suggestions($headers),'multilingual'=>$this->multilingualHeaders($headers)];
    }

    /** @param array<string,string> $mapping @return array<string,mixed> */
    public function process(string $token, array $mapping, int $storeId, int $marketId, string $locale, bool $apply, int $offset = 0, ?int $limit = null): array
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $token)!==1) throw new \DomainException(CanonicalUiText::get('import.universal.error.token'));
        $dir=rtrim($this->projectDir,'/\\').'/var/import-staging';
        $metaPath=$dir.'/'.$token.'.json';
        if(!is_file($metaPath))throw new \DomainException(CanonicalUiText::get('import.universal.error.session_expired'));
        $meta=json_decode((string)file_get_contents($metaPath),true,16,JSON_THROW_ON_ERROR);
        $format=(string)($meta['format']??'');$path=$dir.'/'.$token.'.'.$format;
        if(!is_file($path)||filemtime($path)<time()-7200)throw new \DomainException(CanonicalUiText::get('import.universal.error.session_expired'));
        $clean=[];foreach($mapping as $target=>$source){if(in_array($target,self::TARGET_FIELDS,true)&&is_string($source)&&$source!=='')$clean[$target]=$source;}
        foreach(['sku','price'] as $required){if(!isset($clean[$required]))throw new \DomainException(CanonicalUiText::get('import.universal.error.required_mapping', ['field' => $required]));}
        $rows=$format==='xlsx'?$this->readXlsx($path,self::MAX_ROWS+1):$this->readCsv($path,self::MAX_ROWS+1);
        $headers=array_values(array_map(static fn($v)=>trim((string)$v),array_shift($rows)??[]));
        // Large files are applied in steps (the page shows a progress bar): this call takes one slice of the data rows.
        $totalRows=count($rows);$lastStep=$limit===null||$offset+$limit>=$totalRows;
        if($limit!==null)$rows=array_slice($rows,max(0,$offset),max(1,$limit));
        $index=[];foreach($headers as $i=>$h){if($h!=='')$index[$h]=$i;}
        foreach($clean as $target=>$source){if(!isset($index[$source]))throw new \DomainException(CanonicalUiText::get('import.universal.error.column_missing', ['column' => $source]));}
        $ml=$this->multilingualHeaders($headers);
        $baseLocaleFields=$ml[$locale]??[];
        if(!isset($clean['name'])&&!isset($baseLocaleFields['name'])){foreach($ml as $fields){if(isset($fields['name'])){$baseLocaleFields=$fields;break;}}}
        if(!isset($clean['name'])&&!isset($baseLocaleFields['name']))throw new \DomainException(CanonicalUiText::get('import.universal.error.required_mapping', ['field' => 'name']));
        $tmp=tempnam(sys_get_temp_dir(),'nexora-import-');if($tmp===false)throw new RuntimeException(CanonicalUiText::get('import.universal.error.temp_create'));
        $fh=fopen($tmp,'wb');if($fh===false)throw new RuntimeException(CanonicalUiText::get('import.universal.error.temp_open'));
        $targets=array_keys($clean);if(!in_array('name',$targets,true))$targets[]='name';
        foreach(['slug','short_description','description'] as $field)if(!isset($clean[$field])&&isset($baseLocaleFields[$field]))$targets[]=$field;
        fputcsv($fh,$targets,',','"','');
        $count=0;foreach($rows as $row){if(++$count>self::MAX_ROWS)break;$out=[];foreach($targets as $target){if(isset($clean[$target])){$source=$clean[$target];$out[]=(string)($row[$index[$source]]??'');continue;}$source=$baseLocaleFields[$target]??'';$out[]=$source!==''?(string)($row[$index[$source]]??''):'';}fputcsv($fh,$out,',','"','');}fclose($fh);
        try{
            $result=$apply?$this->catalogCsv->import($tmp,$storeId,$marketId,$locale):$this->catalogCsv->preview($tmp,$storeId,$marketId,$locale);
            if($apply && $ml!==[]){
                foreach($rows as $row){
                    $skuSource=$clean['sku']??''; if($skuSource===''||!isset($index[$skuSource]))continue;
                    $sku=trim((string)($row[$index[$skuSource]]??'')); if($sku==='')continue;
                    foreach($ml as $loc=>$fields){
                        $data=[]; foreach($fields as $field=>$header){$data[$field]=(string)($row[$index[$header]]??'');}
                        if(trim((string)($data['name']??''))==='')continue;
                        try{$this->translations->upsertBySku($storeId,$sku,$loc,$data);}catch(\Throwable $e){$result['failed']=((int)($result['failed']??0))+1;if(count($result['errors']??[])<50)$result['errors'][]=$sku.' ['.$loc.']: '.$e->getMessage();}
                    }
                }
            }
            $result['total']=$totalRows;$result['offset']=max(0,$offset);$result['step']=count($rows);$result['done']=$lastStep;
            return $result;
        } finally {@unlink($tmp);if($apply&&$lastStep){@unlink($path);@unlink($metaPath);}}
    }

    /** @return list<list<string>> */
    private function readCsv(string $path,int $limit): array
    {
        $fh=fopen($path,'rb');if($fh===false)throw new RuntimeException(CanonicalUiText::get('import.universal.error.csv_open'));
        $first=fgets($fh);if($first===false){fclose($fh);return [];}$delimiter=$this->detectDelimiter($first);rewind($fh);
        $rows=[];while(count($rows)<$limit&&($row=fgetcsv($fh,0,$delimiter,'"',''))!==false)$rows[]=array_map('strval',$row);fclose($fh);return $rows;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path,int $limit): array
    {
        if(!class_exists(ZipArchive::class))throw new RuntimeException(CanonicalUiText::get('import.universal.error.xlsx_zip'));
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_invalid'));
        try{
            $shared=[];$sharedXml=$zip->getFromName('xl/sharedStrings.xml');if(is_string($sharedXml)){$xml=@simplexml_load_string($sharedXml);if($xml!==false)foreach($xml->si as $si){$parts=[];if(isset($si->t))$parts[]=(string)$si->t;foreach($si->r as $r)$parts[]=(string)$r->t;$shared[]=implode('',$parts);}}
            $sheetName=null;for($i=0;$i<$zip->numFiles;$i++){$name=(string)$zip->getNameIndex($i);if(preg_match('#^xl/worksheets/sheet\d+\.xml$#',$name)){$sheetName=$name;break;}}
            if($sheetName===null)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_sheet'));
            $sheetRaw=$zip->getFromName($sheetName);if(!is_string($sheetRaw))throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_read'));
            $xml=@simplexml_load_string($sheetRaw);if($xml===false)throw new \DomainException(CanonicalUiText::get('import.universal.error.xlsx_structure'));
            $rows=[];foreach($xml->sheetData->row as $rowNode){if(count($rows)>=$limit)break;$row=[];foreach($rowNode->c as $cell){$ref=(string)$cell['r'];$col=$this->columnIndex($ref);$type=(string)$cell['t'];$value='';if($type==='inlineStr')$value=(string)$cell->is->t;else{$raw=(string)$cell->v;$value=$type==='s'&&isset($shared[(int)$raw])?$shared[(int)$raw]:$raw;}$row[$col]=$value;}if($row!==[]){$max=max(array_keys($row));$dense=[];for($i=0;$i<=$max;$i++)$dense[]=(string)($row[$i]??'');$rows[]=$dense;}}
            return $rows;
        }finally{$zip->close();}
    }

    private function columnIndex(string $reference): int{preg_match('/^([A-Z]+)/i',$reference,$m);$letters=strtoupper($m[1]??'A');$n=0;foreach(str_split($letters) as $ch)$n=$n*26+(ord($ch)-64);return max(0,$n-1);}
    private function format(string $name): string{$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['csv','xlsx'],true))throw new \DomainException(CanonicalUiText::get('import.universal.error.format'));return $ext;}
    private function detectDelimiter(string $line): string{$scores=[];foreach([','=>',',';'=>';',"\t"=>"\t"] as $k=>$d)$scores[$k]=substr_count($line,$d);arsort($scores);$key=(string)array_key_first($scores);return $key==="\t"?"\t":$key;}
    /** @param list<string> $headers @return array<string,array<string,string>> */
    private function multilingualHeaders(array $headers): array
    {
        $out=[];
        foreach($headers as $header){
            if(preg_match('/^(name|slug|short_description|description|meta_title|meta_description)\[([a-z]{2}(?:-[A-Z]{2})?)\]$/D',trim($header),$m)!==1)continue;
            $out[$m[2]][$m[1]]=$header;
        }
        return $out;
    }

    /** @param list<string> $headers @return array<string,string> */
    private function suggestions(array $headers): array
    {
        $aliases = require $this->projectDir . '/resources/import/column_aliases.php';
        $norm=[];foreach($headers as $h)$norm[mb_strtolower(trim($h))]=$h;$out=[];foreach($aliases as $target=>$names)foreach($names as $alias)if(isset($norm[$alias])){$out[$target]=$norm[$alias];break;}return $out;
    }
}
