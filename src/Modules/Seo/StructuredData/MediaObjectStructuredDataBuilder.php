<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;
final class MediaObjectStructuredDataBuilder
{
    public function image(string $url, ?string $caption=null, ?int $width=null, ?int $height=null): array
    {
        $data=['@type'=>'ImageObject','url'=>$url,'contentUrl'=>$url];
        if($caption) $data['caption']=$caption; if($width) $data['width']=$width; if($height) $data['height']=$height; return $data;
    }
    public function video(array $video): array
    {
        foreach(['name','thumbnailUrl','uploadDate'] as $key){if(empty($video[$key])) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.00bcb96565e4').$key);}
        $data=['@type'=>'VideoObject','name'=>(string)$video['name'],'thumbnailUrl'=>(array)$video['thumbnailUrl'],'uploadDate'=>(string)$video['uploadDate']];
        foreach(['description','contentUrl','embedUrl','duration'] as $key){if(!empty($video[$key]))$data[$key]=(string)$video[$key];}
        return $data;
    }
}
