<?php

declare(strict_types=1);

namespace Commerce\Modules\Demo\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Commerce\Modules\Catalog\Application\CategoryWriter;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Appearance\Application\StorefrontPresentationWriterInterface;
use Commerce\Modules\Content\Application\BlogService;
use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DemoSeeder
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private CategoryWriter $categories,
        private ProductWriter $products,
        private SeoUrlManager $seo,
        private StorefrontPresentationWriterInterface $presentation,
        private BlogService $blog,
        private DemoCommerceSeeder $commerce,
        private CatalogTranslationService $translations,
        private CurrencyPriceSynchronizer $currencyPrices,
        private string $projectDir,
    ) {
    }

    /** @return array{categories:int,products:int,articles:int,reviews:int,commerce:array<string,int>} */
    public function install(): array
    {
        $ctx = $this->context();
        \Commerce\Core\I18n\CanonicalUiText::useLocale(in_array(substr($ctx['locale'], 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US');
        if ($this->isInstalled($ctx['store_id'], $ctx['store_public_id'])) {
            $this->remove();
            $ctx = $this->context();
        }

        $catalog = $this->loadDemoCatalog($ctx['locale'], $ctx['currency']);
        $catalog['products'] = $this->prepareDemoMedia($catalog['products']);

        return $this->connection->transactional(function (Connection $db) use ($ctx, $catalog): array {
            $now = $this->now();
            $categoryDefs = $catalog['categories'];
            $categoryIds = [];
            $siblingIndex = [];
            foreach ($categoryDefs as $def) {
                // Parents come before their children in the snapshot; a parent that is missing makes the category a top-level one.
                $parentSlug = $def['parent'] ?? null;
                $parentId = $parentSlug !== null ? ($categoryIds[$parentSlug] ?? null) : null;
                $siblingIndex[$parentSlug ?? ''] = ($siblingIndex[$parentSlug ?? ''] ?? 0) + 1;
                $created = $this->categories->create(new CreateCategoryCommand(
                    $ctx['store_id'],
                    $ctx['market_id'],
                    $ctx['locale'],
                    $def['name'],
                    $parentId,
                    $def['slug'],
                    $siblingIndex[$parentSlug ?? ''] * 10,
                ));
                $categoryIds[$def['slug']] = $created['id'];
                $db->update('mc_category_translation', [
                    'description' => $def['description'],
                    'meta_title' => $def['name'],
                    'meta_description' => $def['description'],
                ], [
                    'category_id' => $created['id'],
                    'store_id' => $ctx['store_id'],
                    'locale' => $ctx['locale'],
                ]);
                $this->tag($db, $ctx['store_id'], 'category', $created['public_id'], 'seed', [
                    'kind' => 'category',
                    'source' => 'dummyjson',
                ]);
            }

            $brands = [];
            foreach (array_values(array_unique(array_column($catalog['products'], 'brand'))) as $name) {
                $brands[$name] = $this->createBrand($db, $ctx['store_id'], (string) $name, $now, $ctx['locale']);
            }

            $productIds = [];
            $reviewCount = 0;
            foreach ($catalog['products'] as $def) {
                $brandId = $brands[$def['brand']];
                $created = $this->products->create(new CreateProductCommand(
                    $ctx['store_id'],
                    $ctx['market_id'],
                    $ctx['locale'],
                    $def['name'],
                    $def['sku'],
                    $def['price'],
                    $ctx['currency'],
                    number_format((float) $def['stock'], 6, '.', ''),
                    'item',
                    'physical',
                    [$categoryIds[$def['category']]],
                    $def['slug'],
                    $def['short'],
                    $def['description'],
                    null,
                    null,
                    $brandId,
                    (string)($def['purchase_mode'] ?? 'auto'),
                    null,
                    $def['purchase_eta'] ?? null,
                ));
                $productId = $created['id'];
                $productIds[] = $productId;
                $this->products->update(new UpdateProductCommand(
                    $productId,
                    $ctx['store_id'],
                    $ctx['market_id'],
                    $ctx['locale'],
                    $def['name'],
                    $def['sku'],
                    $def['price'],
                    $ctx['currency'],
                    number_format((float) $def['stock'], 6, '.', ''),
                    'item',
                    [$categoryIds[$def['category']]],
                    $def['slug'],
                    $def['short'],
                    $def['description'],
                    null,
                    null,
                    'published',
                    $brandId,
                    (string)($def['purchase_mode'] ?? 'auto'),
                    null,
                    $def['purchase_eta'] ?? null,
                ));
                if ($def['compare'] !== null && $def['compare'] > $def['price']) {
                    $db->executeStatement(
                        'UPDATE mc_price SET compare_at_minor=? WHERE variant_id=(SELECT id FROM mc_product_variant WHERE product_id=? ORDER BY sort_order,id LIMIT 1) AND store_id=? AND market_id=?',
                        [$def['compare'], $productId, $ctx['store_id'], $ctx['market_id']],
                    );
                }

                if ($def['image'] !== null) {
                    $mediaId = $this->createMedia($db, $def['image'], $now, [
                        'source' => 'dummyjson',
                        'source_id' => $def['source_id'],
                        'source_url' => $def['image_url'],
                    ]);
                    $db->insert('mc_product_media', [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'media_asset_id' => $mediaId,
                        'role' => 'primary',
                        'sort_order' => 0,
                        'alt_text' => $def['name'],
                    ]);
                }
                foreach ($def['gallery'] as $galleryIndex => $gallery) {
                    $galleryId = $this->createMedia($db, $gallery['path'], $now, [
                        'source' => 'dummyjson',
                        'source_id' => $def['source_id'],
                        'source_url' => $gallery['url'],
                    ]);
                    $db->insert('mc_product_media', [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'media_asset_id' => $galleryId,
                        'role' => 'gallery',
                        'sort_order' => ($galleryIndex + 1) * 10,
                        'alt_text' => $def['name'] . ' ' . ($galleryIndex + 2),
                    ]);
                }

                $publicBinary = (string) $db->fetchOne('SELECT public_id FROM mc_product WHERE id=?', [$productId]);
                $publicId = Uuid::fromBinary($publicBinary)->toRfc4122();
                $this->tag($db, $ctx['store_id'], 'product', $publicId, 'seed', [
                    'kind' => 'product',
                    'source' => 'dummyjson',
                    'source_id' => $def['source_id'],
                    'image' => $def['image'],
                ]);
                foreach ($def['attrs'] as $sort => $attr) {
                    $this->addAttribute($db, $productId, $attr[0], $attr[1], $sort, $now, $ctx['locale']);
                }
                foreach ($this->demoReviews($ctx['locale']) as $ri => $review) {
                    $db->insert('mc_product_review', [
                        'public_id' => $this->publicIds->binary(),
                        'store_id' => $ctx['store_id'],
                        'product_id' => $productId,
                        'customer_id' => null,
                        'locale' => $ctx['locale'],
                        'author_name' => $review['author'],
                        'rating' => $review['rating'],
                        'title' => $review['title'],
                        'body' => $review['body'],
                        'verified_purchase' => 1,
                        'status' => 'published',
                        'helpful_count' => $ri === 0 ? 3 : 1,
                        'created_at' => $now,
                        'published_at' => $now,
                    ]);
                    $reviewCount++;
                }
            }

            $this->enableDemoCurrencies($db, $ctx);
            $reviewCount += $this->translateCatalog($db, $ctx, $categoryIds, $productIds, $brands, $catalog['products'], $now);

            for ($i = 0; $i < count($productIds) - 1; $i++) {
                $db->insert('mc_product_relation', [
                    'product_id' => $productIds[$i],
                    'related_product_id' => $productIds[$i + 1],
                    'relation_type' => 'related',
                    'sort_order' => ($i + 1) * 10,
                    'created_at' => $now,
                ]);
            }

            $articles = [
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.yak_obraty_noutbuk_dlia_roboty_ta_navchannia'),'slug'=>'how-to-choose-laptop','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.ekran_pamiat_avtonomnist_i_porty_korotkyi_praktychny'),'body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_pochnit_iz_stsenariiu_vykorystannia_dlia_brauzera_')],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.5_oznak_spravdi_zruchnoho_smartfona'),'slug'=>'smartphone-selection-guide','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.yak_otsinyty_ekran_kameru_avtonomnist_i_pamiat_bez_m'),'body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_zruchnyi_smartfon_tse_balans_ekrana_avtonomnosti_k')],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.rozumnyi_dim_bez_zaivoi_skladnosti'),'slug'=>'smart-home-basics','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.z_choho_pochaty_avtomatyzatsiiu_domu_ta_iaki_prystro'),'body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_pochnit_iz_prostykh_rechei_osvitlennia_klimatu_pry')],
            ];
            foreach ($articles as $i => $article) {
                $this->createArticle($db, $ctx['store_id'], $article, $now, $i, $ctx['locale']);
            }
            $showcase = $this->seedBlogShowcase($db, $ctx['store_id'], $now, $ctx['locale']);
            $this->seedInformationPagesDemo($db, $ctx['store_id'], $now, $ctx['locale']);
            $this->seedPromotionDemo($db, $ctx['store_id'], $now);
            $this->seedForumDemo($db, $ctx['store_id'], $now);
            $commerce = $this->commerce->install($db, $ctx, $productIds);
            $promoTitles = [];
            foreach (array_merge([$ctx['locale']], $this->extraLocales($db, $ctx['store_id'], $ctx['locale'])) as $promoLocale) {
                $promoTitles[$promoLocale] = (string) ($this->loadDemoCatalog($promoLocale, $ctx['currency'])['categories'][2]['name'] ?? '');
            }
            $this->presentation->save($ctx['store_id'], $this->demoPresentation($catalog, $ctx['store_name'], array_filter($promoTitles)), 'demo:seed');

            $this->tag($db, $ctx['store_id'], 'store', Uuid::fromBinary($ctx['store_public_id'])->toRfc4122(), 'installed', [
                'version' => '3.73.0',
                'catalog_source' => 'DummyJSON',
            ]);

            return ['categories' => count($categoryDefs), 'products' => count($catalog['products']), 'articles' => count($articles) + $showcase, 'reviews' => $reviewCount, 'commerce' => $commerce];
        });
    }

    public function remove(): void
    {
        $ctx=$this->context();
        $this->connection->transactional(function(Connection $db) use($ctx):void{
            $this->commerce->remove($db,$ctx['store_id']);
            $namespace=$this->namespace($ctx['store_id']);
            $rows=$db->fetchAllAssociative("SELECT entity_type,entity_public_id FROM mc_entity_metadata WHERE namespace=? AND meta_key='seed'",[$namespace]);
            foreach($rows as $row){
                $type=(string)$row['entity_type']; $bin=(string)$row['entity_public_id'];
                $db->executeStatement('DELETE FROM mc_seo_route WHERE store_id=? AND entity_public_id=?',[$ctx['store_id'],$bin]);
                if($type==='product'){$db->delete('mc_product',['public_id'=>$bin]);}
                elseif($type==='category'){$db->delete('mc_category',['public_id'=>$bin]);}
                elseif($type==='article'){$db->delete('mc_content_entry',['public_id'=>$bin]);}
                elseif($type==='brand'){$db->delete('mc_brand',['public_id'=>$bin]);}
            }
            $db->executeStatement("UPDATE mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id SET ce.status='draft',ce.author_subject='system:installer',ce.published_at=NULL,ct.body_html=NULL,ct.excerpt=NULL WHERE ce.store_id=? AND ce.content_type='page' AND ce.author_subject='demo:seed'",[$ctx['store_id']]);
            if ($db->createSchemaManager()->tablesExist(['mc_promotion'])) { $db->executeStatement("DELETE FROM mc_promotion WHERE store_id=? AND code='DEMO10'",[$ctx['store_id']]); }
            if ($db->createSchemaManager()->tablesExist(['mc_forum_topic','mc_forum_board'])) { $db->executeStatement("DELETE t FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.slug='welcome-demo'",[$ctx['store_id']]); }
            foreach($db->fetchFirstColumn("SELECT value_json FROM mc_entity_metadata WHERE namespace=? AND meta_key='seed_blog_category'",[$namespace]) as $json){
                $catId=(int)((json_decode((string)$json,true)['id'] ?? 0));
                if($catId>0){$db->delete('mc_blog_category',['id'=>$catId,'store_id'=>$ctx['store_id']]);}
            }
            $db->executeStatement("DELETE i FROM mc_inventory_item i LEFT JOIN mc_variant_inventory_item vii ON vii.inventory_item_id=i.id LEFT JOIN mc_inventory_reservation rsv ON rsv.inventory_item_id=i.id WHERE i.sku LIKE 'DEMO-%' AND vii.inventory_item_id IS NULL AND rsv.id IS NULL");
            $db->executeStatement("DELETE FROM mc_entity_metadata WHERE namespace=?",[$namespace]);
            $media=$db->fetchAllAssociative("SELECT id,storage_key FROM mc_media_asset WHERE storage_key LIKE 'demo/%'");
            foreach($media as $m){ if((int)$db->fetchOne('SELECT COUNT(*) FROM mc_product_media WHERE media_asset_id=?',[(int)$m['id']])===0){$db->delete('mc_media_asset',['id'=>(int)$m['id']]);}}
            $db->executeStatement("DELETE ad FROM mc_attribute_definition ad LEFT JOIN mc_product_attribute_value av ON av.attribute_id=ad.id WHERE ad.code LIKE 'demo\_%' AND av.attribute_id IS NULL");
        });
    }

    private function context(): array
    {
        $row=$this->connection->fetchAssociative("SELECT s.id store_id,s.public_id store_public_id,s.name store_name,s.default_locale,s.default_currency,m.id market_id FROM mc_store s JOIN mc_market m ON m.store_id=s.id AND m.status='active' WHERE s.status='active' ORDER BY s.id,m.id LIMIT 1");
        if(!is_array($row)){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.spochatku_vstanovit_mahazyn'));}
        return ['store_id'=>(int)$row['store_id'],'store_public_id'=>(string)$row['store_public_id'],'store_name'=>(string)$row['store_name'],'market_id'=>(int)$row['market_id'],'locale'=>(string)$row['default_locale'],'currency'=>(string)$row['default_currency']];
    }

    private function isInstalled(int $storeId, string $storePublicId): bool
    {
        return (int)$this->connection->fetchOne("SELECT COUNT(*) FROM mc_entity_metadata WHERE entity_type='store' AND entity_public_id=? AND namespace=? AND meta_key='installed'",[$storePublicId,$this->namespace($storeId)])>0;
    }

    private function createBrand(Connection $db,int $storeId,string $name,string $now,string $locale): int
    {
        $public=$this->publicIds->generate(); $slug=strtolower(preg_replace('/[^a-z0-9]+/i','-',trim($name))??$name);
        $db->insert('mc_brand',['public_id'=>$public->toBinary(),'name'=>$name,'normalized_name'=>mb_strtolower($name,'UTF-8'),'website_url'=>null,'logo_media_id'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$db->lastInsertId();
        $db->insert('mc_store_brand',['store_id'=>$storeId,'brand_id'=>$id,'status'=>'active','sort_order'=>10]);
        $db->insert('mc_brand_translation',['brand_id'=>$id,'store_id'=>$storeId,'locale'=>$locale,'slug'=>$slug,'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiinyi_brend_modern_commerce'),'meta_title'=>$name,'meta_description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tovary_brendu').$name]);
        $this->tag($db,$storeId,'brand',$public->toRfc4122(),'seed',['kind'=>'brand']);
        return $id;
    }

    private function createMedia(Connection $db,string $storageKey,string $now,array $metadata=[]): int
    {
        $path=$this->projectDir.'/public/media/'.$storageKey;
        if(!is_file($path)){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d3aad94b3d8c').$storageKey);}
        $size=getimagesize($path); if($size===false){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.28f8db879325').$storageKey);}
        // One asset per storage key: the same demo image may back several products, promos and articles.
        $existing=$db->fetchOne('SELECT id FROM mc_media_asset WHERE storage_key_hash=?',[hash('sha256',$storageKey,true)]);
        if($existing!==false){$this->linkMediaToStore($db,(int)$existing,$now);return (int)$existing;}
        $public=$this->publicIds->generate();
        $db->insert('mc_media_asset',['public_id'=>$public->toBinary(),'storage_key'=>$storageKey,'storage_key_hash'=>hash('sha256',$storageKey,true),'mime_type'=>(string)$size['mime'],'bytes'=>(int)filesize($path),'width'=>(int)$size[0],'height'=>(int)$size[1],'checksum_sha256'=>hash_file('sha256',$path,true),'metadata'=>json_encode(array_merge(['demo'=>true],$metadata),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'created_at'=>$now]);
        $assetId=(int)$db->lastInsertId();
        $this->linkMediaToStore($db,$assetId,$now);
        return $assetId;
    }

    /** Registers a demo asset in the store media library (folder "Demo"), so it is visible and pickable in the admin. */
    private function linkMediaToStore(Connection $db,int $assetId,string $now): void
    {
        $storeId=(int)$db->fetchOne('SELECT MIN(id) FROM mc_store'); if($storeId<=0){return;}
        $folderId=$db->fetchOne("SELECT id FROM mc_media_folder WHERE store_id=? AND slug='demo' AND parent_id IS NULL",[$storeId]);
        if($folderId===false){$db->insert('mc_media_folder',['store_id'=>$storeId,'parent_id'=>null,'name'=>'Demo','slug'=>'demo','created_at'=>$now]);$folderId=$db->lastInsertId();}
        $db->executeStatement("INSERT IGNORE INTO mc_store_media_asset (store_id,asset_id,folder_id,tags_json,created_at,updated_at) VALUES (?,?,?,'[\"demo\"]',?,?)",[$storeId,$assetId,(int)$folderId,$now,$now]);
    }

    /** $identity is the label in the store default language: it keeps one attribute across all demo languages. */
    private function addAttribute(Connection $db,int $productId,string $name,string $value,int $sort,string $now,string $locale,?string $identity=null): void
    {
        $code='demo_'.substr(hash('sha256',$identity??$name),0,12);
        $attr=$db->fetchOne('SELECT id FROM mc_attribute_definition WHERE code=?',[$code]);
        if($attr===false){
            $db->insert('mc_attribute_definition',['public_id'=>$this->publicIds->binary(),'code'=>$code,'data_type'=>'text','filterable'=>1,'comparable'=>1,'sort_order'=>$sort*10]);
            $attr=(int)$db->lastInsertId();
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_attribute_translation WHERE attribute_id=? AND locale=?', [(int) $attr, $locale]) === 0) {
            $db->insert('mc_attribute_translation',['attribute_id'=>$attr,'locale'=>$locale,'name'=>$name,'unit_label'=>null]);
        }
        $db->insert('mc_product_attribute_value',['product_id'=>$productId,'variant_id'=>null,'attribute_id'=>(int)$attr,'locale'=>$locale,'value_text'=>$value,'value_text_hash'=>hash('sha256',$value,true),'value_decimal'=>null,'value_boolean'=>null,'value_json'=>null,'sort_order'=>$sort*10]);
    }

    private function createArticle(Connection $db,int $storeId,array $article,string $now,int $sort,string $locale): void
    {
        $public=$this->publicIds->generate();
        $db->insert('mc_content_entry',['public_id'=>$public->toBinary(),'store_id'=>$storeId,'content_type'=>'article','system_key'=>null,'status'=>'published','author_subject'=>'demo:editorial','published_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$db->lastInsertId();
        $db->insert('mc_content_translation',['content_id'=>$id,'locale'=>$locale,'title'=>$article['title'],'excerpt'=>$article['excerpt'],'body_html'=>$article['body'],'meta_title'=>$article['title'],'meta_description'=>$article['excerpt'],'created_at'=>$now,'updated_at'=>$now]);
        $this->seo->ensureForCreatedEntity($storeId,$locale,SeoEntityType::BlogArticle,$public->toRfc4122(),$article['title'],$article['slug']);
        $this->tag($db,$storeId,'article',$public->toRfc4122(),'seed',['kind'=>'article','sort'=>$sort]);
        // Every demo article gets a locally generated cover (no external downloads); it is also registered in the media library.
        $cover=match($article['slug']){'how-to-choose-laptop'=>'blog-laptop','smartphone-selection-guide'=>'blog-phone','smart-home-basics'=>'blog-home',default=>null};
        if($cover!==null){
            $url='/media/demo/blog/'.$cover.'.svg';
            $this->registerSvgMedia($db,'demo/blog/'.$cover.'.svg',$now);
            $meta=['cover_url'=>$url,'cover_alt'=>$article['title'],'image_size'=>'m','image_align'=>'none','reading_minutes'=>1];
            if((int)$db->fetchOne('SELECT COUNT(*) FROM mc_blog_article_meta WHERE content_id=?',[$id])===1){$db->update('mc_blog_article_meta',$meta,['content_id'=>$id]);}
            else{$db->insert('mc_blog_article_meta',$meta+['content_id'=>$id]);}
        }
    }

    /** SVG covers are not readable by getimagesize(): register them in the media library with the declared size. */
    private function registerSvgMedia(Connection $db,string $storageKey,string $now): void
    {
        $path=$this->projectDir.'/public/media/'.$storageKey;
        if(!is_file($path)){return;}
        $hash=hash('sha256',$storageKey,true);
        $existing=$db->fetchOne('SELECT id FROM mc_media_asset WHERE storage_key_hash=?',[$hash]);
        if($existing!==false){$this->linkMediaToStore($db,(int)$existing,$now);return;}
        $svg=(string)file_get_contents($path);
        $w=preg_match('/width="(\d+)"/',$svg,$mw)?(int)$mw[1]:1440;
        $h=preg_match('/height="(\d+)"/',$svg,$mh)?(int)$mh[1]:810;
        $db->insert('mc_media_asset',['public_id'=>$this->publicIds->generate()->toBinary(),'storage_key'=>$storageKey,'storage_key_hash'=>$hash,'mime_type'=>'image/svg+xml','bytes'=>(int)filesize($path),'width'=>$w,'height'=>$h,'checksum_sha256'=>hash_file('sha256',$path,true),'metadata'=>json_encode(['demo'=>true],JSON_THROW_ON_ERROR),'created_at'=>$now]);
        $this->linkMediaToStore($db,(int)$db->lastInsertId(),$now);
    }

    /** Editorial showcase: categories, covers, tags and long-form articles from resources/demo/blog-articles.json. */
    private function seedBlogShowcase(Connection $db,int $storeId,string $now,string $locale): int
    {
        $file=$this->projectDir.'/resources/demo/blog-articles.json';
        $data=is_file($file)?json_decode((string)file_get_contents($file),true):null;
        if(!is_array($data)||!is_array($data['articles']??null)){return 0;}
        $extraLocales=$this->extraLocales($db,$storeId,$locale);
        $categoryIds=[];
        $sort=0;
        foreach((array)($data['categories']??[]) as $key=>$names){
            $sort+=10;
            $parentKey=(string)(((array)($data['category_parents']??[]))[(string)$key]??'');
            $parentId=$parentKey!==''?($categoryIds[$parentKey]??0):0;
            $id=$this->blog->saveCategory($storeId,$locale,null,['name'=>$this->localized($names,$locale),'slug'=>'demo-'.$key,'sort_order'=>$sort,'status'=>'active','parent_id'=>$parentId]);
            foreach($extraLocales as $extra){$this->blog->saveCategory($storeId,$extra,$id,['name'=>$this->localized($names,$extra),'sort_order'=>$sort,'status'=>'active']);}
            $categoryIds[(string)$key]=$id;
            $this->tag($db,$storeId,'blog_category',Uuid::v4()->toRfc4122(),'seed_blog_category',['id'=>$id]);
        }
        $author=$this->localized($data['authors']??'',$locale);
        $count=0;
        foreach($data['articles'] as $i=>$article){
            $tr=$this->pickTranslation((array)($article['translations']??[]),$locale);
            if($tr===null){continue;}
            $published=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.(int)($article['days_ago']??($i+1)).' days')->format('Y-m-d H:i:s');
            $id=$this->blog->save($storeId,$locale,null,[
                'title'=>(string)$tr['title'],'slug'=>(string)$article['slug'],'excerpt'=>(string)$tr['excerpt'],'body_html'=>(string)$tr['body'],
                'status'=>'published','published_at'=>$published,'category_id'=>$categoryIds[(string)($article['category']??'')]??0,
                'cover_url'=>(string)($article['cover']??''),'cover_alt'=>(string)$tr['title'],'author_name'=>$author,
                'featured'=>!empty($article['featured']),'tags'=>implode(', ',(array)($tr['tags']??[])),
            ],'demo:editorial');
            foreach($extraLocales as $extra){
                $trExtra=$this->pickTranslation((array)($article['translations']??[]),$extra);
                if($trExtra===null){continue;}
                $this->blog->save($storeId,$extra,$id,[
                    'title'=>(string)$trExtra['title'],'slug'=>(string)$article['slug'],'excerpt'=>(string)$trExtra['excerpt'],'body_html'=>(string)$trExtra['body'],
                    'status'=>'published','published_at'=>$published,'category_id'=>$categoryIds[(string)($article['category']??'')]??0,
                    'cover_url'=>(string)($article['cover']??''),'cover_alt'=>(string)$trExtra['title'],'author_name'=>$this->localized($data['authors']??'',$extra),
                    'featured'=>!empty($article['featured']),'tags'=>implode(', ',(array)($trExtra['tags']??[])),
                ],'demo:editorial');
            }
            if(str_ends_with((string)($article['cover']??''),'.svg')){$this->registerSvgMedia($db,ltrim(substr((string)$article['cover'],strlen('/media/')),'/'),$now);}
            $public=(string)$db->fetchOne('SELECT public_id FROM mc_content_entry WHERE id=?',[$id]);
            $this->tag($db,$storeId,'article',Uuid::fromBinary($public)->toRfc4122(),'seed',['kind'=>'article','showcase'=>true]);
            $count++;
        }
        return $count;
    }

    /** @param array<string,array<string,mixed>> $translations */
    private function pickTranslation(array $translations,string $locale): ?array
    {
        $language=strtolower(substr($locale,0,2));
        foreach([$locale,$language==='uk'?'uk-UA':null,$language==='ru'?'ru-RU':null,'en-US','uk-UA'] as $key){
            if($key!==null&&isset($translations[$key])&&is_array($translations[$key])){return $translations[$key];}
        }
        return null;
    }

    private function seedInformationPagesDemo(Connection $db,int $storeId,string $now,string $locale): void
    {
        $pages=[
            'about'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pro_kompaniiu'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.modern_commerce_demo_prezentatsiinyi_mahazyn_suchasn'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_strong_modern_commerce_demo_strong_demonstruie_kat')],
            'contacts'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kontakty'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiini_kontaktni_dani'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_strong_demo_mahazyn_strong_br_email_demo_example_c')],
            'delivery'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dostavka'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.varianty_dostavky_dlia_demonstratsii_checkout'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_platforma_pidtrymuie_pidkliuchennia_populiarnykh_u')],
            'payment'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.oplata'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dostupni_sposoby_oplaty_zalezhat_vid_nalashtuvan_mah'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_u_demo_mozhna_pokazuvaty_bankivskyi_perekaz_oplatu')],
            'returns'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povernennia_ta_obmin'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.prozori_umovy_povernennia_dlia_pokuptsia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_tse_demonstratsiinyi_tekst_polityky_povernennia_pe')],
            'warranty'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.harantiia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.harantiini_umovy_i_dokumenty_mozhut_buty_poviazani_z'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_dlia_kozhnoho_tovaru_mozhna_pokazuvaty_harantiiu_i')],
            'faq'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.chasti_zapytannia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.korotki_vidpovidi_na_typovi_zapytannia_pokuptsiv'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.h2_yak_oformyty_zamovlennia_h2_p_dodaite_tovar_u_kos')],
            'privacy'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.polityka_konfidentsiinosti'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiina_polityka_konfidentsiinosti'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_tse_demo_tekst_pered_publichnym_zapuskom_mahazyn_p')],
            'cookies'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.polityka_cookie'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiina_informatsiia_pro_cookies'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_neobkhidni_cookies_zabezpechuiut_koshyk_sesiiu_ta_')],
            'terms'=>[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.umovy_ta_polozhennia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiini_umovy_vykorystannia_mahazynu'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_zaminit_tsei_tekst_iurydychnymy_umovamy_vashoi_kom')],
        ];
        $templates=new \Commerce\Modules\Content\System\InformationPageTemplates($this->projectDir);
        $profile=$db->fetchAssociative('SELECT sp.country_code,sp.legal_name,sp.registration_number,sp.registration_address,sp.email,sp.phone,sp.privacy_contact,sp.return_contact,sp.warranty_contact,s.name AS store_name FROM mc_store s LEFT JOIN mc_store_profile sp ON sp.store_id=s.id WHERE s.id=?',[$storeId]) ?: [];
        foreach($pages as $key=>$page){
            $row=$db->fetchAssociative("SELECT ce.id,ce.status,ct.body_html FROM mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=? WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key=? LIMIT 1",[$locale,$storeId,$key]);
            if(!is_array($row) || ((string)($row['status']??'')==='published' && trim((string)($row['body_html']??''))!=='')){continue;}
            $db->update('mc_content_entry',['status'=>'published','author_subject'=>'demo:seed','published_at'=>$now,'updated_at'=>$now],['id'=>(int)$row['id']]);
            $body=$templates->body((string)$key,$locale,$profile,(string)($profile['country_code']??'')) ?? $page[2];
            $page[0]=$templates->title((string)$key,$locale) ?? $page[0];
            $db->update('mc_content_translation',['title'=>$page[0],'excerpt'=>$page[1],'body_html'=>$body,'meta_title'=>$page[0],'meta_description'=>$page[1],'updated_at'=>$now],['content_id'=>(int)$row['id'],'locale'=>$locale]);
        }
    }


    /**
     * The demo shop offers US dollars and euros next to the store currency, so the currency switcher can be tried at once.
     * The rates are fixed demonstration values (rate source "manual"); a real store picks an official source in
     * Admin → System → Localization.
     */
    private function enableDemoCurrencies(Connection $db, array $ctx): void
    {
        $base = strtoupper((string) $ctx['currency']);
        $baseRate = \Commerce\Core\Install\RegionCatalog::demoRatePerUsd($base);
        if ($baseRate <= 0) {
            return;
        }
        $now = $this->now();
        foreach (['USD', 'EUR'] as $index => $quote) {
            $quoteRate = \Commerce\Core\Install\RegionCatalog::demoRatePerUsd($quote);
            if ($quote === $base || $quoteRate <= 0 || (int) $db->fetchOne('SELECT COUNT(*) FROM mc_currency WHERE code=?', [$quote]) === 0) {
                continue;
            }
            $db->executeStatement(
                "INSERT INTO mc_store_currency (store_id,currency_code,enabled,is_default,auto_convert,rate_source,rate_markup_bps,rounding_increment_minor,sort_order) VALUES (?,?,1,0,1,'manual',0,1,?)
                 ON DUPLICATE KEY UPDATE enabled=1,auto_convert=1,rate_source='manual'",
                [$ctx['store_id'], $quote, 20 + $index * 10],
            );
            $db->executeStatement(
                "INSERT INTO mc_exchange_rate (base_currency,quote_currency,rate,provider,observed_at,expires_at,created_at) VALUES (?,?,?,'manual',?,NULL,?)
                 ON DUPLICATE KEY UPDATE rate=VALUES(rate),expires_at=NULL",
                [$base, $quote, sprintf('%.12F', $quoteRate / $baseRate), $now, $now],
            );
        }
        $this->currencyPrices->sync($ctx['store_id'], true);
    }

    /** @return list<string> the demo languages other than the store default that are enabled on the store */
    private function extraLocales(Connection $db, int $storeId, string $default): array
    {
        $enabled = array_map('strval', $db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1', [$storeId]));

        return array_values(array_filter(\Commerce\Core\Install\RegionCatalog::FULL_LOCALES, static fn (string $locale): bool => $locale !== $default && in_array($locale, $enabled, true)));
    }

    /**
     * The demo catalogue is written in the store default language first; here every other enabled demo language gets its own
     * names, descriptions, characteristics, reviews and addresses, so the shop and the admin are complete in Ukrainian,
     * English and Russian right after installation.
     *
     * @param array<string,int> $categoryIds slug => id
     * @param list<int> $productIds in catalogue order
     * @param array<string,int> $brands name => id
     * @param list<array<string,mixed>> $defaultProducts the catalogue in the store default language (identifies attributes)
     * @return int number of reviews added
     */
    private function translateCatalog(Connection $db, array $ctx, array $categoryIds, array $productIds, array $brands, array $defaultProducts, string $now): int
    {
        $reviews = 0;
        foreach ($this->extraLocales($db, $ctx['store_id'], $ctx['locale']) as $locale) {
            \Commerce\Core\I18n\CanonicalUiText::useLocale(in_array(substr($locale, 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US');
            $catalog = $this->loadDemoCatalog($locale, $ctx['currency']);
            foreach ($catalog['categories'] as $def) {
                if (!isset($categoryIds[$def['slug']])) {
                    continue;
                }
                $this->translations->saveCategory($ctx['store_id'], $categoryIds[$def['slug']], $locale, [
                    'name' => $def['name'], 'description' => $def['description'], 'meta_title' => $def['name'], 'meta_description' => $def['description'],
                ]);
            }
            foreach ($brands as $name => $brandId) {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_brand_translation WHERE brand_id=? AND store_id=? AND locale=?', [$brandId, $ctx['store_id'], $locale]) === 0) {
                    $slug = (string) $db->fetchOne('SELECT slug FROM mc_brand_translation WHERE brand_id=? AND store_id=? LIMIT 1', [$brandId, $ctx['store_id']]);
                    $db->insert('mc_brand_translation', ['brand_id' => $brandId, 'store_id' => $ctx['store_id'], 'locale' => $locale, 'slug' => $slug, 'description' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiinyi_brend_modern_commerce'), 'meta_title' => (string) $name, 'meta_description' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tovary_brendu') . $name]);
                }
            }
            foreach ($catalog['products'] as $index => $def) {
                $productId = $productIds[$index] ?? null;
                if ($productId === null) {
                    continue;
                }
                $this->translations->saveProduct($ctx['store_id'], $productId, $locale, [
                    'name' => $def['name'], 'short_description' => $def['short'], 'description' => $def['description'],
                    'meta_title' => $def['name'], 'meta_description' => $def['short'],
                ]);
                foreach ($def['attrs'] as $sort => $attr) {
                    $this->addAttribute($db, $productId, $attr[0], $attr[1], $sort, $now, $locale, (string) ($defaultProducts[$index]['attrs'][$sort][0] ?? $attr[0]));
                }
                foreach ($this->demoReviews($locale) as $ri => $review) {
                    $db->insert('mc_product_review', [
                        'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'product_id' => $productId, 'customer_id' => null,
                        'locale' => $locale, 'author_name' => $review['author'], 'rating' => $review['rating'], 'title' => $review['title'], 'body' => $review['body'],
                        'verified_purchase' => 1, 'status' => 'published', 'helpful_count' => $ri === 0 ? 3 : 1, 'created_at' => $now, 'published_at' => $now,
                    ]);
                    ++$reviews;
                }
            }
        }
        \Commerce\Core\I18n\CanonicalUiText::useLocale(in_array(substr($ctx['locale'], 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US');

        return $reviews;
    }

    /** @return array{categories:list<array<string,mixed>>,products:list<array<string,mixed>>} */
    private function loadDemoCatalog(string $locale, string $currency): array
    {
        $file = $this->projectDir . '/resources/demo/dummyjson-catalog.json';
        if (!is_file($file)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.demo_snapshot_missing'));
        }
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['categories'] ?? null) || !is_array($decoded['products'] ?? null)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.demo_snapshot_invalid'));
        }
        $categories = [];
        $categoryNames = [];
        foreach ($decoded['categories'] as $category) {
            if (!is_array($category)) { continue; }
            $slug=(string) ($category['slug'] ?? '');
            $name=$this->localized($category['name'] ?? [], $locale);
            $categories[] = ['slug'=>$slug,'parent'=>isset($category['parent']) && $category['parent']!=='' ? (string)$category['parent'] : null,'name'=>$name,'description'=>$this->localized($category['description'] ?? [], $locale)];
            $categoryNames[$slug]=$name;
        }
        $products = [];
        foreach ($decoded['products'] as $product) {
            if (!is_array($product)) { continue; }
            $price = $this->demoPriceMinor((float) ($product['source_price_usd'] ?? 0), $currency);
            $compare = isset($product['source_compare_usd']) ? $this->demoPriceMinor((float) $product['source_compare_usd'], $currency) : null;
            $attrs = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
            $products[] = [
                'source_id' => (int) ($product['source_id'] ?? 0),
                'name' => $this->localized($product['name'] ?? '', $locale),
                'slug' => (string) ($product['slug'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'category' => (string) ($product['category'] ?? ''),
                'brand' => (string) ($product['brand'] ?? 'Demo'),
                'price' => $price,
                'compare' => $compare,
                'stock' => in_array((int)($product['source_id'] ?? 0), [99,101,103], true) ? 0 : (int) ($product['stock'] ?? 0),
                'purchase_mode' => match ((int)($product['source_id'] ?? 0)) { 99 => 'notify', 101 => 'backorder', 103 => 'coming_soon', default => 'auto' },
                'purchase_eta' => match ((int)($product['source_id'] ?? 0)) { 101 => $this->localized(['uk-UA'=>'відправлення за 5–7 днів','ru-RU'=>'отправка через 5–7 дней','en-US'=>'ships in 5–7 days'], $locale), 103 => $this->localized(['uk-UA'=>'Очікуємо нову партію','ru-RU'=>'Ожидаем новую партию','en-US'=>'New stock coming soon'], $locale), default => null },
                'short' => $this->localized($product['short'] ?? [], $locale),
                'description' => $this->localized($product['description'] ?? [], $locale),
                'image_url' => (string) ($product['image_url'] ?? ''),
                'gallery_urls' => array_values(array_filter(array_map('strval', is_array($product['gallery_urls'] ?? null) ? $product['gallery_urls'] : []))),
                'fallback' => isset($product['fallback']) && $product['fallback'] !== '' ? (string) $product['fallback'] : null,
                'attrs' => array_merge(
                    [
                        [$this->attributeLabel('brand', $locale), (string) ($product['brand'] ?? 'Demo')],
                        [$this->attributeLabel('category', $locale), (string) ($categoryNames[(string)($product['category'] ?? '')] ?? $product['category'] ?? '')],
                    ],
                    $this->specAttributes(is_array($product['specs'] ?? null) ? $product['specs'] : [], $locale),
                    [
                        [$this->attributeLabel('warranty', $locale), $this->sourceDetail((string) ($attrs['warranty'] ?? ''), $locale)],
                        [$this->attributeLabel('delivery', $locale), $this->sourceDetail((string) ($attrs['shipping'] ?? ''), $locale)],
                        [$this->attributeLabel('returns', $locale), $this->sourceDetail((string) ($attrs['return_policy'] ?? ''), $locale)],
                    ]
                ),
            ];
        }
        return ['categories' => $categories, 'products' => $products];
    }

    /**
     * The demo photos ship inside the package (resources/demo/images, WebP) and are copied into the media folder:
     * the installer never touches the network. A product whose photo is missing falls back to the bundled tech set.
     *
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function prepareDemoMedia(array $products): array
    {
        foreach ($products as &$product) {
            $sourceId = max(1, (int) ($product['source_id'] ?? 0));
            $primary = $this->bundleDemoImage($sourceId . '-1.webp');
            $product['image'] = $primary ?? ($product['fallback'] ?? null);
            $gallery = [];
            foreach (array_slice((array) ($product['gallery_urls'] ?? []), 0, 2) as $index => $url) {
                $path = $this->bundleDemoImage($sourceId . '-' . ($index + 2) . '.webp');
                if ($path !== null) {
                    $gallery[] = ['path' => $path, 'url' => (string) $url];
                }
            }
            $product['gallery'] = $gallery;
        }
        unset($product);

        return $products;
    }

    /** Copies one packaged demo photo into public/media/demo/dummyjson and returns its storage key, or null when the package has no such photo. */
    private function bundleDemoImage(string $file): ?string
    {
        $source = $this->projectDir . '/resources/demo/images/' . $file;
        if (!is_file($source) || filesize($source) === 0) {
            return null;
        }
        $key = 'demo/dummyjson/' . $file;
        $target = $this->projectDir . '/public/media/' . $key;
        if (is_file($target) && filesize($target) === filesize($source)) {
            return $key;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return @copy($source, $target) ? $key : null;
    }

    private function localized(mixed $values, string $locale): string
    {
        if (!is_array($values)) { return trim((string) $values); }
        $language = strtolower(substr($locale, 0, 2));
        foreach ([$locale, $language === 'uk' ? 'uk-UA' : null, $language === 'ru' ? 'ru-RU' : null, 'en-US'] as $key) {
            if ($key !== null && isset($values[$key]) && trim((string) $values[$key]) !== '') { return trim((string) $values[$key]); }
        }
        foreach ($values as $value) { if (trim((string) $value) !== '') { return trim((string) $value); } }
        return '';
    }

    private function demoPriceMinor(float $usd, string $currency): int
    {
        $rate = \Commerce\Core\Install\RegionCatalog::demoRatePerUsd($currency);
        $digits = \Commerce\Core\Install\RegionCatalog::minorUnits($currency);

        return max(0, (int) round($usd * $rate * (10 ** $digits)));
    }

    /**
     * Curated technical specifications, listed in a fixed order between the identity and the service rows.
     *
     * @param array<string,mixed> $specs
     * @return list<array{0:string,1:string}>
     */
    private function specAttributes(array $specs, string $locale): array
    {
        $rows = [];
        foreach (['year', 'display', 'chipset', 'camera', 'battery', 'connectivity', 'os', 'ports', 'weight', 'features'] as $key) {
            $value = trim((string) ($specs[$key] ?? ''));
            if ($value !== '') {
                $rows[] = [$this->attributeLabel($key, $locale), $value];
            }
        }
        return $rows;
    }

    private function attributeLabel(string $key, string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));
        $labels = [
            'uk' => ['brand'=>'Бренд','category'=>'Категорія','warranty'=>'Гарантія','delivery'=>'Доставка','returns'=>'Повернення','year'=>'Рік випуску','display'=>'Дисплей','chipset'=>'Процесор','camera'=>'Камера','battery'=>'Акумулятор','connectivity'=>'Зв’язок','os'=>'Операційна система','ports'=>'Порти','weight'=>'Вага','features'=>'Особливості'],
            'ru' => ['brand'=>'Бренд','category'=>'Категория','warranty'=>'Гарантия','delivery'=>'Доставка','returns'=>'Возврат','year'=>'Год выпуска','display'=>'Дисплей','chipset'=>'Процессор','camera'=>'Камера','battery'=>'Аккумулятор','connectivity'=>'Связь','os'=>'Операционная система','ports'=>'Порты','weight'=>'Вес','features'=>'Особенности'],
            'en' => ['brand'=>'Brand','category'=>'Category','warranty'=>'Warranty','delivery'=>'Delivery','returns'=>'Returns','year'=>'Release year','display'=>'Display','chipset'=>'Processor','camera'=>'Camera','battery'=>'Battery','connectivity'=>'Connectivity','os'=>'Operating system','ports'=>'Ports','weight'=>'Weight','features'=>'Features'],
        ];
        return $labels[$lang][$key] ?? $labels['en'][$key] ?? $key;
    }

    private function sourceDetail(string $value, string $locale): string
    {
        $value=trim($value);
        if($value===''){return '—';}
        $lang=strtolower(substr($locale,0,2));
        if($lang==='en'){return $value;}
        $months=preg_replace_callback('/(\d+)\s+months?\s+warranty/i',static fn(array $m):string=>$lang==='uk'?$m[1].' міс. гарантії':$m[1].' мес. гарантии',$value);
        $years=preg_replace_callback('/(\d+)\s+years?\s+warranty/i',static fn(array $m):string=>$lang==='uk'?$m[1].' р. гарантії':$m[1].' г. гарантии',$months??$value);
        $out=(string)$years;
        $dictionary=$lang==='uk' ? [
            'No warranty'=>'Без гарантії','Lifetime warranty'=>'Довічна гарантія','Ships overnight'=>'Відправлення наступного дня','Ships in 1 week'=>'Відправлення протягом тижня','Ships in 2 weeks'=>'Відправлення протягом 2 тижнів','Ships in 1 month'=>'Відправлення протягом місяця','Ships in 1-2 business days'=>'Відправлення за 1–2 робочі дні','Ships in 3-5 business days'=>'Відправлення за 3–5 робочих днів','No return policy'=>'Повернення не передбачено','7 days return policy'=>'Повернення протягом 7 днів','30 days return policy'=>'Повернення протягом 30 днів','60 days return policy'=>'Повернення протягом 60 днів','90 days return policy'=>'Повернення протягом 90 днів',
        ] : [
            'No warranty'=>'Без гарантии','Lifetime warranty'=>'Пожизненная гарантия','Ships overnight'=>'Отправка на следующий день','Ships in 1 week'=>'Отправка в течение недели','Ships in 2 weeks'=>'Отправка в течение 2 недель','Ships in 1 month'=>'Отправка в течение месяца','Ships in 1-2 business days'=>'Отправка за 1–2 рабочих дня','Ships in 3-5 business days'=>'Отправка за 3–5 рабочих дней','No return policy'=>'Возврат не предусмотрен','7 days return policy'=>'Возврат в течение 7 дней','30 days return policy'=>'Возврат в течение 30 дней','60 days return policy'=>'Возврат в течение 60 дней','90 days return policy'=>'Возврат в течение 90 дней',
        ];
        return $dictionary[$value] ?? $out;
    }

    /** @return list<array{author:string,rating:int,title:string,body:string}> */
    private function demoReviews(string $locale): array
    {
        return match (strtolower(substr($locale, 0, 2))) {
            'uk' => [
                ['author'=>'Олена','rating'=>5,'title'=>'Рекомендую','body'=>'Зручна демонстраційна картка: фото, ціна та характеристики легко переглянути.'],
                ['author'=>'Андрій','rating'=>4,'title'=>'Гарне враження','body'=>'Структура товару зрозуміла, потрібна інформація знаходиться швидко.'],
            ],
            'ru' => [
                ['author'=>'Елена','rating'=>5,'title'=>'Рекомендую','body'=>'Удобная демонстрационная карточка: фото, цена и характеристики легко просмотреть.'],
                ['author'=>'Андрей','rating'=>4,'title'=>'Хорошее впечатление','body'=>'Структура товара понятная, нужная информация находится быстро.'],
            ],
            default => [
                ['author'=>'Olivia','rating'=>5,'title'=>'Recommended','body'=>'Clear demonstration product page with accessible photos, pricing and specifications.'],
                ['author'=>'Andrew','rating'=>4,'title'=>'Good impression','body'=>'The product structure is easy to understand and key information is quick to find.'],
            ],
        };
    }

    private function seedPromotionDemo(Connection $db,int $storeId,string $now): void
    {
        if (!$db->createSchemaManager()->tablesExist(['mc_promotion'])) { return; }
        if ((int)$db->fetchOne('SELECT COUNT(*) FROM mc_promotion WHERE store_id=? AND code=?',[$storeId,'DEMO10'])>0) { return; }
        $db->insert('mc_promotion',[
            'public_id'=>$this->publicIds->binary(),'store_id'=>$storeId,'name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demo_znyzhka_10'),'code'=>'DEMO10','status'=>'active','trigger_type'=>'coupon','discount_type'=>'percent','discount_value'=>1000,
            'min_subtotal_minor'=>200000,'max_discount_minor'=>500000,'usage_limit'=>1000,'usage_count'=>0,'per_customer_limit'=>3,'priority'=>20,'stop_processing'=>0,'conditions_json'=>json_encode([],JSON_THROW_ON_ERROR),
            'starts_at'=>null,'ends_at'=>null,'created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    private function seedForumDemo(Connection $db,int $storeId,string $now): void
    {
        if (!$db->createSchemaManager()->tablesExist(['mc_forum_board','mc_forum_topic','mc_forum_post'])) { return; }
        $boardId=$db->fetchOne("SELECT id FROM mc_forum_board WHERE store_id=? ORDER BY sort_order,id LIMIT 1",[$storeId]);
        if($boardId===false || (int)$db->fetchOne("SELECT COUNT(*) FROM mc_forum_topic WHERE board_id=? AND slug='welcome-demo'",[(int)$boardId])>0){return;}
        $topicPublic=$this->publicIds->binary();
        $db->insert('mc_forum_topic',['public_id'=>$topicPublic,'board_id'=>(int)$boardId,'slug'=>'welcome-demo','title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.laskavo_prosymo_do_demonstratsiinoho_forumu'),'author_name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.komanda_modern_commerce'),'status'=>'published','is_pinned'=>1,'is_locked'=>0,'created_at'=>$now,'updated_at'=>$now,'published_at'=>$now,'last_post_at'=>$now]);
        $topicId=(int)$db->lastInsertId();
        $db->insert('mc_forum_post',['public_id'=>$this->publicIds->binary(),'topic_id'=>$topicId,'author_name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.komanda_modern_commerce'),'body_text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tse_demonstratsiina_tema_forum_bloh_mahazyn_i_konten'),'status'=>'published','created_at'=>$now,'updated_at'=>$now,'published_at'=>$now]);
    }

    /** @return array<string,mixed> */
    /**
     * The texts of the demo home page, in every demo language at once (the shop then shows the language of the visitor).
     *
     * @param array{categories:list<array<string,mixed>>,products:list<array<string,mixed>>} $catalog
     * @param array<string,string> $promoRightTitles locale => title of the second promo (a category name)
     * @return array<string,mixed>
     */
    private function demoPresentation(array $catalog, string $storeName, array $promoRightTitles = []): array
    {
        $restore = in_array(substr((string) ($this->context()['locale'] ?? ''), 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US';
        $t = function (string $key, string $ru) use ($restore): array {
            $out = [];
            foreach (['uk-UA', 'en-US'] as $locale) {
                \Commerce\Core\I18n\CanonicalUiText::useLocale($locale);
                $out[$locale] = \Commerce\Core\I18n\CanonicalUiText::get($key);
            }
            \Commerce\Core\I18n\CanonicalUiText::useLocale($restore);
            $out['ru-RU'] = $ru;

            return $out;
        };

        return [
            'utility' => [
                'location' => $t('php.modules.demo.application.demoseeder.ukraina', 'Украина'),
                'delivery' => $t('php.modules.demo.application.demoseeder.bezkoshtovna_dostavka_vid_2_000', 'Бесплатная доставка от 2 000 ₴'),
                'support' => $t('php.modules.demo.application.demoseeder.pidtrymka_shchodnia', 'Поддержка ежедневно'),
            ],
            'brand' => ['title' => $storeName, 'subtitle' => $t('php.modules.demo.application.demoshowcasequery.demo_vitryna_modern_commerce', 'Демо-витрина Nexora Commerce'), 'icon' => ''],
            'theme' => ['primary' => '#0B63F6', 'accent' => '#FF7A1A', 'success' => '#0F7A4B'],
            'header' => ['search_placeholder' => $t('php.modules.demo.application.demoseeder.poshuk_tovariv_brendiv_abo_katehorii', 'Поиск товаров, брендов или категорий…'), 'show_category_nav' => true],
            'home' => ['show_benefits' => true, 'show_categories' => true, 'show_products' => true, 'show_promos' => true, 'show_articles' => true],
            'hero' => [
                'eyebrow' => 'Nexora Commerce',
                'title' => $t('php.modules.demo.application.demoseeder.hotovyi_suchasnyi_mahazyn', 'Готовый современный магазин'),
                'subtitle' => $t('php.modules.demo.application.demoseeder.kataloh_checkout_kontent_i_marketynh_v_odnii_systemi', 'Каталог, оформление заказа, контент и маркетинг в одной системе'),
                'text' => $t('php.modules.demo.application.demoseeder.demonstratsiina_vitryna_vstanovliuietsia_razom_iz_sy', 'Демонстрационная витрина устанавливается вместе с системой и показывает реальные сценарии покупки.'),
                'image' => '/media/' . ($catalog['products'][0]['image'] ?? 'demo/laptop-pro-14.webp'),
                'button_label' => $t('php.modules.demo.application.demoseeder.pereity_do_katalohu', 'Перейти в каталог'),
                'button_url' => '/catalog',
            ],
            'promo_left' => [
                'title' => $t('php.modules.demo.application.demoshowcasequery.smartfony_ta_hadzhety', 'Смартфоны и гаджеты'),
                'text' => $t('php.modules.demo.application.demoseeder.demo_tovary_z_tsinamy_zalyshkamy_ta_vidhukamy', 'Демо-товары с ценами, остатками и отзывами'),
                'image' => '/media/' . ($catalog['products'][3]['image'] ?? 'demo/smartphone-neo-x1.webp'),
                'url' => '/smartphones',
            ],
            'promo_right' => [
                'title' => $promoRightTitles !== [] ? $promoRightTitles : ($catalog['categories'][2]['name'] ?? 'Audio & Smart Home'),
                'text' => $t('php.modules.demo.application.demoseeder.katehorii_aktsii_ta_kontentni_bloky', 'Категории, акции и контентные блоки'),
                'image' => '/media/' . ($catalog['products'][8]['image'] ?? 'demo/headphones-airbeat.webp'),
                'url' => '/audio-smart-home',
            ],
        ];
    }

    private function tag(Connection $db,int $storeId,string $entityType,string $publicId,string $key,array $value): void
    {
        $db->insert('mc_entity_metadata',['entity_type'=>$entityType,'entity_public_id'=>Uuid::fromString($publicId)->toBinary(),'namespace'=>$this->namespace($storeId),'meta_key'=>$key,'value_json'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'updated_at'=>$this->now()]);
    }

    private function namespace(int $storeId): string
    {
        return 'demo-' . $storeId;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}