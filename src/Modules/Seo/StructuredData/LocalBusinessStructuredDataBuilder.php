<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\StructuredData;
final class LocalBusinessStructuredDataBuilder
{
    public function build(array $business): array
    {
        if (empty($business['name']) || empty($business['url']) || empty($business['address'])) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.484b21d14d2d'));
        $data=['@type'=>(string)($business['type']??'LocalBusiness'),'name'=>(string)$business['name'],'url'=>(string)$business['url'],'address'=>['@type'=>'PostalAddress',...(array)$business['address']]];
        if(!empty($business['telephone'])) $data['telephone']=(string)$business['telephone'];
        if(!empty($business['image'])) $data['image']=(array)$business['image'];
        if(!empty($business['geo'])) $data['geo']=['@type'=>'GeoCoordinates',...(array)$business['geo']];
        if(!empty($business['opening_hours'])) $data['openingHoursSpecification']=(array)$business['opening_hours'];
        return $data;
    }
}
