<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Application;

use RuntimeException;

final readonly class FeedStorageService
{
    public function __construct(private ProductFeedGenerator $generator, private string $projectDir) {}

    /** @return array{path:string,meta:array<string,mixed>} */
    public function generate(string $storeCode,string $platform,int $storeId,int $marketId,string $locale,string $currency,bool $inStockOnly=false): array
    {
        $this->assertKey($storeCode);$this->assertKey($platform);$this->assertKey(str_replace('-','_',$locale));
        $result=$this->generator->generate($platform,$storeId,$marketId,$locale,$currency,$inStockOnly);
        $dir=$this->directory($storeCode,$locale);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.dba2c877b181'));
        $path=$dir.'/'.$platform.'.'.$result['extension'];$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,$result['content'],LOCK_EX)===false)throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4a9c6d0b2089'));@chmod($tmp,0640);if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1c4a6cba7aee'));}
        $meta=['platform'=>$platform,'store_code'=>$storeCode,'locale'=>$locale,'currency'=>$currency,'count'=>$result['count'],'skipped'=>$result['skipped'],'warnings'=>$result['warnings'],'generated_at'=>gmdate('c'),'sha256'=>hash_file('sha256',$path),'bytes'=>(int)filesize($path),'content_type'=>$result['content_type'],'extension'=>$result['extension']];
        $metaPath=$dir.'/'.$platform.'.json';$metaTmp=$metaPath.'.tmp-'.bin2hex(random_bytes(4));file_put_contents($metaTmp,json_encode($meta,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);@chmod($metaTmp,0640);@rename($metaTmp,$metaPath);
        return ['path'=>$path,'meta'=>$meta];
    }

    /** @return array{path:string,meta:array<string,mixed>}|null */
    public function latest(string $storeCode,string $platform,string $locale): ?array
    {
        $this->assertKey($storeCode);$this->assertKey($platform);$dir=$this->directory($storeCode,$locale);$metaPath=$dir.'/'.$platform.'.json';if(!is_file($metaPath))return null;$raw=@file_get_contents($metaPath);if(!is_string($raw))return null;try{$meta=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){return null;}if(!is_array($meta)||empty($meta['extension']))return null;$path=$dir.'/'.$platform.'.'.$meta['extension'];if(!is_file($path))return null;return ['path'=>$path,'meta'=>$meta];
    }

    private function directory(string $storeCode,string $locale): string{return rtrim($this->projectDir,'/\\').'/var/feeds/'.$storeCode.'/'.str_replace('-','_',$locale);}
    private function assertKey(string $value): void{if(preg_match('/^[A-Za-z0-9_-]{1,96}$/D',$value)!==1)throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.22f5c49b27e1'));}
}
