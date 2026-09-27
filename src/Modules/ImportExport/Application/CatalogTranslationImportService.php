<?php

declare(strict_types=1);

namespace Commerce\Modules\ImportExport\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogTranslationImportService
{
    public function __construct(
        private Connection $db,
        private SeoUrlManager $seo,
        private HtmlSanitizerInterface $richTextSanitizer,
    ) {}

    /** @param array<string,string> $data */
    public function upsertBySku(int $storeId, string $sku, string $locale, array $data): void
    {
        if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) !== 1) {
            throw new \DomainException(CanonicalUiText::get('import.translation.invalid_locale') . $locale);
        }
        if ((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$storeId,$locale]) !== 1) {
            throw new \DomainException(CanonicalUiText::get('import.translation.locale_disabled') . $locale);
        }
        $product = $this->db->fetchAssociative('SELECT p.id,p.public_id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_variant v ON v.product_id=p.id WHERE v.sku=? ORDER BY v.sort_order,v.id LIMIT 1', [$storeId,$sku]);
        if (!is_array($product)) throw new \DomainException(CanonicalUiText::get('import.translation.product_missing') . $sku);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name,'UTF-8') > 255) throw new \DomainException(CanonicalUiText::get('import.translation.invalid_name') . $locale);
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $row=[
            'name'=>$name,
            'short_description'=>$this->plain($data['short_description'] ?? null, 2000),
            'description'=>$this->rich($data['description'] ?? null),
            'meta_title'=>$this->plain($data['meta_title'] ?? null, 255),
            'meta_description'=>$this->plain($data['meta_description'] ?? null, 500),
            'updated_at'=>$now,
        ];
        $exists=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_product_translation WHERE product_id=? AND store_id=? AND locale=?',[(int)$product['id'],$storeId,$locale]);
        if($exists===1){$this->db->update('mc_product_translation',$row,['product_id'=>(int)$product['id'],'store_id'=>$storeId,'locale'=>$locale]);}
        else{$this->db->insert('mc_product_translation',['product_id'=>(int)$product['id'],'store_id'=>$storeId,'locale'=>$locale,'slug'=>null,'created_at'=>$now,...$row]);}
        $publicId=Uuid::fromBinary((string)$product['public_id'])->toRfc4122();
        $route=$this->seo->ensureForCreatedEntity($storeId,$locale,SeoEntityType::Product,$publicId,$name);
        $slug=trim((string)($data['slug']??''));
        if($slug!=='' && $slug!==$route->slug) $this->seo->changeSlug($route,$slug);
    }

    private function plain(mixed $value,int $max): ?string
    {
        $v=trim(preg_replace('/\s+/u',' ',strip_tags((string)$value)) ?? '');
        return $v===''?null:mb_substr($v,0,$max,'UTF-8');
    }

    private function rich(mixed $value): ?string
    {
        $v=trim((string)$value); if($v==='')return null;
        return $this->richTextSanitizer->sanitize($v);
    }
}
