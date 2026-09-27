<?php

declare(strict_types=1);

namespace Commerce\Modules\Demo\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\CategoryWriter;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Appearance\Application\StorefrontPresentationWriterInterface;
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
        private string $projectDir,
    ) {
    }

    /** @return array{categories:int,products:int,articles:int,reviews:int} */
    public function install(): array
    {
        $ctx = $this->context();
        if ($this->isInstalled($ctx['store_id'], $ctx['store_public_id'])) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiini_dani_vzhe_vstanovleno'));
        }

        return $this->connection->transactional(function (Connection $db) use ($ctx): array {
            $now = $this->now();
            $categoryDefs = [
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.smartfony'),'slug'=>'smartphones','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.suchasni_smartfony_korysni_hadzhety_ta_mobilni_akses')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.noutbuky'),'slug'=>'laptops','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tekhnika_dlia_roboty_navchannia_tvorchosti_ta_shchod')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.pobutova_tekhnika'),'slug'=>'home-appliances','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.praktychna_tekhnika_shcho_ekonomyt_chas_i_robyt_dim_')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dim_i_interier'),'slug'=>'home-interior','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.mebli_ta_rechi_dlia_zatyshnoho_suchasnoho_prostoru')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.krasa_i_zdorovia'),'slug'=>'beauty-health','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dohliad_kosmetyka_ta_tovary_dlia_shchodennoho_komfor')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.sport_i_vidpochynok'),'slug'=>'sport-leisure','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tovary_dlia_aktyvnoho_zhyttia_trenuvan_i_podorozhei')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dytiachi_tovary'),'slug'=>'kids','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.korysni_ta_bezpechni_tovary_dlia_ditei_i_batkiv')],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.aksesuary'),'slug'=>'accessories','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.navushnyky_zariadni_prystroi_ta_inshi_korysni_aksesu')],
            ];
            $categoryIds=[];
            foreach ($categoryDefs as $i=>$def) {
                $created=$this->categories->create(new CreateCategoryCommand($ctx['store_id'],$ctx['market_id'],'uk-UA',$def['name'],null,$def['slug'],($i+1)*10));
                $categoryIds[$def['slug']]=$created['id'];
                $db->update('mc_category_translation',['description'=>$def['description'],'meta_title'=>$def['name'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kupyty_v_ukraini'),'meta_description'=>$def['description']],['category_id'=>$created['id'],'store_id'=>$ctx['store_id'],'locale'=>'uk-UA']);
                $this->tag($db,$ctx['store_id'],'category',$created['public_id'],'seed',['kind'=>'category']);
            }

            $brands=[];
            foreach (['NovaTech','Aero','Soundly','Visionary','BrewLab','HomeOne','NordHome','PureSkin','RunWay','Kiddo'] as $name) {
                $brands[$name]=$this->createBrand($db,$ctx['store_id'],$name,$now);
            }

            $defs=[
                ['name'=>'Smartphone Neo X1 256 GB','slug'=>'smartphone-neo-x1-256gb','sku'=>'DEMO-PHONE-X1','price'=>3799900,'compare'=>4299900,'stock'=>'18.000000','category'=>'smartphones','brand'=>'NovaTech','image'=>'demo/smartphone-neo-x1.webp','gallery'=>['demo/smartphone-neo-x1-alt.webp'],'short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.yaskravyi_oled_dysplei_shvydka_robota_ta_kamera_dlia'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_strong_neo_x1_strong_zbalansovanyi_suchasnyi_smart'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dysplei'),'6,5” OLED'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pamiat'),'256 GB'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kolir'),'Graphite']]],
                ['name'=>'Laptop Pro 14 OLED','slug'=>'laptop-pro-14-oled','sku'=>'DEMO-LAPTOP-P14','price'=>3999900,'compare'=>4499900,'stock'=>'9.000000','category'=>'laptops','brand'=>'Aero','image'=>'demo/laptop-pro-14.webp','gallery'=>['demo/laptop-pro-14-alt.webp'],'short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.lehkyi_14_diuimovyi_noutbuk_z_oled_ekranom_dlia_robo'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_kompaktnyi_noutbuk_iz_chitkym_oled_dyspleiem_shvyd'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dysplei'),'14” OLED'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.operatyvna_pamiat'),'16 GB'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.nakopychuvach'),'512 GB SSD']]],
                ['name'=>'AirBeat Studio ANC','slug'=>'airbeat-studio-anc','sku'=>'DEMO-AUDIO-ANC','price'=>1449900,'compare'=>1699900,'stock'=>'24.000000','category'=>'accessories','brand'=>'Soundly','image'=>'demo/headphones-airbeat.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povnorozmirni_bezdrotovi_navushnyky_z_aktyvnym_shumo'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_komfortni_navushnyky_dlia_muzyky_dzvinkiv_i_podoro'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pidkliuchennia'),'Bluetooth 5.3'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.shumozahlushennia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.aktyvne_anc')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.avtonomnist'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.do_30_hod')]]],
                ['name'=>'Vision 55 QLED 4K','slug'=>'vision-55-qled-4k','sku'=>'DEMO-TV-Q55','price'=>2499900,'compare'=>null,'stock'=>'0.000000','purchase_mode'=>'coming_soon','purchase_button'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povidomyty_pro_naiavnist'),'purchase_eta'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.3_5_dniv'),'category'=>'home-appliances','brand'=>'Visionary','image'=>'demo/smart-tv-vision-55.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.55_diuimovyi_qled_televizor_iz_4k_zobrazhenniam_ta_s'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_velykyi_ekran_nasycheni_kolory_ta_zruchni_onlain_s'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.diahonal'),'55”'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.rozdilna_zdatnist'),'4K UHD'],['Smart TV',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tak')]]],
                ['name'=>'Barista One Automatic','slug'=>'barista-one-automatic','sku'=>'DEMO-COFFEE-B1','price'=>2069900,'compare'=>2299900,'stock'=>'11.000000','category'=>'home-appliances','brand'=>'BrewLab','image'=>'demo/coffee-machine-barista.webp','gallery'=>['demo/coffee-machine-barista-alt.webp'],'short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.avtomatychna_kavomashyna_dlia_espreso_amerykano_ta_m'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_zruchna_avtomatychna_kavomashyna_z_prostym_keruvan'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.potuzhnist'),'1450 W'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tysk'),'15 bar'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.typ_kavy'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.zernova_melena')]]],
                ['name'=>'Crisp XL Air Fryer 7.2 L','slug'=>'crisp-xl-air-fryer-72l','sku'=>'DEMO-AIRFRYER-XL','price'=>799900,'compare'=>999900,'stock'=>'31.000000','category'=>'home-appliances','brand'=>'HomeOne','image'=>'demo/air-fryer-crisp-xl.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.mistka_aerofrytiurnytsia_dlia_shvydkoho_pryhotuvanni'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_velyka_chasha_zrozumile_keruvannia_ta_hotovi_prohr'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.obiem'),'7,2 L'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.potuzhnist'),'2000 W'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.prohramy'),'8']]],
                ['name'=>'NordHome Comfort Sofa','slug'=>'nordhome-comfort-sofa','sku'=>'DEMO-HOME-SOFA','price'=>2899900,'compare'=>3199900,'stock'=>'0.000000','purchase_mode'=>'backorder','purchase_button'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pid_zamovlennia'),'purchase_eta'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.vidpravka_3_5_dniv'),'category'=>'home-interior','brand'=>'NordHome','image'=>'demo/product-sofa-demo.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.miakyi_dyvan_u_suchasnomu_minimalistychnomu_styli_dl'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_demonstratsiinyi_dyvan_pokazuie_iak_u_katalozi_moz'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.shyryna'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.218_sm')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.material'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tkanyna')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kolir'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.teplyi_bezh')]]],
                ['name'=>'PureSkin Daily Care Set','slug'=>'pureskin-daily-care-set','sku'=>'DEMO-BEAUTY-SET','price'=>189900,'compare'=>219900,'stock'=>'46.000000','category'=>'beauty-health','brand'=>'PureSkin','image'=>'demo/product-beauty-demo.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.nabir_bazovoho_shchodennoho_dohliadu_dlia_oblychchia'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_pryklad_tovaru_z_katehorii_krasy_i_zdorovia_korotk'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.typ'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.nabir_dohliadu')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kilkist'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.4_zasoby')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dlia_koho'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.universalnyi')]]],
                ['name'=>'RunWay Motion Trainers','slug'=>'runway-motion-trainers','sku'=>'DEMO-SPORT-SHOES','price'=>329900,'compare'=>379900,'stock'=>'22.000000','category'=>'sport-leisure','brand'=>'RunWay','image'=>'demo/product-sport-demo.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.lehki_trenuvalni_krosivky_dlia_bihu_zalu_ta_aktyvnoh'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_demonstratsiina_sportyvna_model_iz_rozmirnoiu_khar'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.rozmiry'),'40–45'],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.vaha'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.290_h')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pryznachennia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.bih_trenuvannia')]]],
                ['name'=>'Kiddo Soft Bear','slug'=>'kiddo-soft-bear','sku'=>'DEMO-KIDS-BEAR','price'=>89900,'compare'=>null,'stock'=>'0.000000','purchase_mode'=>'notify','purchase_button'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povidomyty_pro_naiavnist'),'purchase_eta'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.ochikuiemo_novu_partiiu'),'category'=>'kids','brand'=>'Kiddo','image'=>'demo/product-kids-demo.webp','short'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.miaka_ihrashka_dlia_podarunka_ta_zatyshnoi_dytiachoi'),'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_demonstratsiina_dytiacha_pozytsiia_z_prostoiu_tova'),'attrs'=>[[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.vysota'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.38_sm')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.material'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.hipoalerhennyi_tekstyl')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.vik'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.vid_3_rokiv')]]],
            ];

            $productIds=[]; $reviewCount=0;
            foreach ($defs as $def) {
                $purchaseMode=(string)($def['purchase_mode'] ?? 'auto');
                $purchaseButton=isset($def['purchase_button'])?(string)$def['purchase_button']:null;
                $purchaseEta=isset($def['purchase_eta'])?(string)$def['purchase_eta']:null;
                $brandId=$brands[$def['brand']];
                $created=$this->products->create(new CreateProductCommand($ctx['store_id'],$ctx['market_id'],'uk-UA',$def['name'],$def['sku'],$def['price'],'UAH',$def['stock'],'item','physical',[$categoryIds[$def['category']]],$def['slug'],$def['short'],$def['description'],null,null,$brandId,$purchaseMode,$purchaseButton,$purchaseEta));
                $productId=$created['id']; $productIds[]=$productId;
                $this->products->update(new UpdateProductCommand($productId,$ctx['store_id'],$ctx['market_id'],'uk-UA',$def['name'],$def['sku'],$def['price'],'UAH',$def['stock'],'item',[$categoryIds[$def['category']]],$def['slug'],$def['short'],$def['description'],null,null,'published',$brandId,$purchaseMode,$purchaseButton,$purchaseEta));
                if ($def['compare']!==null) {
                    $db->executeStatement('UPDATE mc_price SET compare_at_minor=? WHERE variant_id=(SELECT id FROM mc_product_variant WHERE product_id=? ORDER BY sort_order,id LIMIT 1) AND store_id=? AND market_id=?',[$def['compare'],$productId,$ctx['store_id'],$ctx['market_id']]);
                }
                $mediaId=$this->createMedia($db,$def['image'],$now);
                $db->insert('mc_product_media',['product_id'=>$productId,'variant_id'=>null,'media_asset_id'=>$mediaId,'role'=>'primary','sort_order'=>0,'alt_text'=>$def['name']]);
                foreach (($def['gallery'] ?? []) as $galleryIndex => $galleryKey) {
                    $galleryId=$this->createMedia($db,(string)$galleryKey,$now);
                    $db->insert('mc_product_media',['product_id'=>$productId,'variant_id'=>null,'media_asset_id'=>$galleryId,'role'=>'gallery','sort_order'=>($galleryIndex+1)*10,'alt_text'=>$def['name'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.foto').($galleryIndex+2)]);
                }
                $publicBinary=(string)$db->fetchOne('SELECT public_id FROM mc_product WHERE id=?',[$productId]);
                $publicId=Uuid::fromBinary($publicBinary)->toRfc4122();
                $this->tag($db,$ctx['store_id'],'product',$publicId,'seed',['kind'=>'product','image'=>$def['image']]);
                foreach ($def['attrs'] as $sort=>$attr) { $this->addAttribute($db,$productId,$attr[0],$attr[1],$sort,$now); }
                foreach ([[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.olena'),5,\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.duzhe_zruchnyi_tovar_opys_i_kharakterystyky_zrozumil')],[\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.andrii'),4,\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.khorosha_demonstratsiia_kartky_tovaru_ta_oformlennia')]] as $ri=>$review) {
                    $db->insert('mc_product_review',['public_id'=>$this->publicIds->binary(),'store_id'=>$ctx['store_id'],'product_id'=>$productId,'customer_id'=>null,'locale'=>'uk-UA','author_name'=>$review[0],'rating'=>$review[1],'title'=>$ri===0?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.rekomenduiu'):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.harne_vrazhennia'),'body'=>$review[2],'verified_purchase'=>1,'status'=>'published','helpful_count'=>$ri===0?3:1,'created_at'=>$now,'published_at'=>$now]);
                    $reviewCount++;
                }
            }
            for($i=0;$i<count($productIds)-1;$i++){
                $db->insert('mc_product_relation',['product_id'=>$productIds[$i],'related_product_id'=>$productIds[$i+1],'relation_type'=>'related','sort_order'=>($i+1)*10,'created_at'=>$now]);
            }

            $articles=[
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.yak_obraty_noutbuk_dlia_roboty_ta_navchannia'),'slug'=>'how-to-choose-laptop','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.ekran_pamiat_avtonomnist_i_porty_korotkyi_praktychny'),'image'=>'demo/article-laptop.webp','body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_pochnit_iz_stsenariiu_vykorystannia_dlia_brauzera_')],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.5_oznak_spravdi_zruchnoho_smartfona'),'slug'=>'smartphone-selection-guide','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.yak_otsinyty_ekran_kameru_avtonomnist_i_pamiat_bez_m'),'image'=>'demo/article-smartphone.webp','body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_zruchnyi_smartfon_tse_balans_ekrana_avtonomnosti_k')],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.rozumnyi_dim_bez_zaivoi_skladnosti'),'slug'=>'smart-home-basics','excerpt'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.z_choho_pochaty_avtomatyzatsiiu_domu_ta_iaki_prystro'),'image'=>'demo/article-smart-home.webp','body'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.p_pochnit_iz_prostykh_rechei_osvitlennia_klimatu_pry')],
            ];
            foreach($articles as $i=>$article){ $this->createArticle($db,$ctx['store_id'],$article,$now,$i); }
            $this->seedInformationPagesDemo($db,$ctx['store_id'],$now);
            $this->seedPromotionDemo($db,$ctx['store_id'],$now);
            $this->seedForumDemo($db,$ctx['store_id'],$now);
            $this->presentation->save($ctx['store_id'], $this->demoPresentation(), 'demo:seed');

            $this->tag($db,$ctx['store_id'],'store',Uuid::fromBinary($ctx['store_public_id'])->toRfc4122(),'installed',['version'=>'3.5.0']);
            return ['categories'=>count($categoryDefs),'products'=>count($defs),'articles'=>count($articles),'reviews'=>$reviewCount];
        });
    }

    public function remove(): void
    {
        $ctx=$this->context();
        $this->connection->transactional(function(Connection $db) use($ctx):void{
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
            $db->executeStatement("UPDATE mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale='uk-UA' SET ce.status='draft',ce.author_subject='system:installer',ce.published_at=NULL,ct.body_html=NULL,ct.excerpt=NULL WHERE ce.store_id=? AND ce.content_type='page' AND ce.author_subject='demo:seed'",[$ctx['store_id']]);
            if ($db->createSchemaManager()->tablesExist(['mc_promotion'])) { $db->executeStatement("DELETE FROM mc_promotion WHERE store_id=? AND code='DEMO10'",[$ctx['store_id']]); }
            if ($db->createSchemaManager()->tablesExist(['mc_forum_topic','mc_forum_board'])) { $db->executeStatement("DELETE t FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.slug='welcome-demo'",[$ctx['store_id']]); }
            $db->executeStatement("DELETE FROM mc_entity_metadata WHERE namespace=?",[$namespace]);
            $media=$db->fetchAllAssociative("SELECT id,storage_key FROM mc_media_asset WHERE storage_key LIKE 'demo/%'");
            foreach($media as $m){ if((int)$db->fetchOne('SELECT COUNT(*) FROM mc_product_media WHERE media_asset_id=?',[(int)$m['id']])===0){$db->delete('mc_media_asset',['id'=>(int)$m['id']]);}}
        });
    }

    private function context(): array
    {
        $row=$this->connection->fetchAssociative("SELECT s.id store_id,s.public_id store_public_id,m.id market_id FROM mc_store s JOIN mc_market m ON m.store_id=s.id AND m.status='active' WHERE s.status='active' ORDER BY s.id,m.id LIMIT 1");
        if(!is_array($row)){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.spochatku_vstanovit_mahazyn'));}
        return ['store_id'=>(int)$row['store_id'],'store_public_id'=>(string)$row['store_public_id'],'market_id'=>(int)$row['market_id']];
    }

    private function isInstalled(int $storeId, string $storePublicId): bool
    {
        return (int)$this->connection->fetchOne("SELECT COUNT(*) FROM mc_entity_metadata WHERE entity_type='store' AND entity_public_id=? AND namespace=? AND meta_key='installed'",[$storePublicId,$this->namespace($storeId)])>0;
    }

    private function createBrand(Connection $db,int $storeId,string $name,string $now): int
    {
        $public=$this->publicIds->generate(); $slug=strtolower(preg_replace('/[^a-z0-9]+/i','-',trim($name))??$name);
        $db->insert('mc_brand',['public_id'=>$public->toBinary(),'name'=>$name,'normalized_name'=>mb_strtolower($name,'UTF-8'),'website_url'=>null,'logo_media_id'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$db->lastInsertId();
        $db->insert('mc_store_brand',['store_id'=>$storeId,'brand_id'=>$id,'status'=>'active','sort_order'=>10]);
        $db->insert('mc_brand_translation',['brand_id'=>$id,'store_id'=>$storeId,'locale'=>'uk-UA','slug'=>$slug,'description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiinyi_brend_modern_commerce'),'meta_title'=>$name,'meta_description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.tovary_brendu').$name]);
        $this->tag($db,$storeId,'brand',$public->toRfc4122(),'seed',['kind'=>'brand']);
        return $id;
    }

    private function createMedia(Connection $db,string $storageKey,string $now): int
    {
        $path=$this->projectDir.'/public/media/'.$storageKey;
        if(!is_file($path)){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d3aad94b3d8c').$storageKey);}
        $size=getimagesize($path); if($size===false){throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.28f8db879325').$storageKey);}
        $public=$this->publicIds->generate();
        $db->insert('mc_media_asset',['public_id'=>$public->toBinary(),'storage_key'=>$storageKey,'storage_key_hash'=>hash('sha256',$storageKey,true),'mime_type'=>(string)$size['mime'],'bytes'=>(int)filesize($path),'width'=>(int)$size[0],'height'=>(int)$size[1],'checksum_sha256'=>hash_file('sha256',$path,true),'metadata'=>json_encode(['demo'=>true],JSON_THROW_ON_ERROR),'created_at'=>$now]);
        return (int)$db->lastInsertId();
    }

    private function addAttribute(Connection $db,int $productId,string $name,string $value,int $sort,string $now): void
    {
        $code='demo_'.substr(hash('sha256',$name),0,12);
        $attr=$db->fetchOne('SELECT id FROM mc_attribute_definition WHERE code=?',[$code]);
        if($attr===false){
            $db->insert('mc_attribute_definition',['public_id'=>$this->publicIds->binary(),'code'=>$code,'data_type'=>'text','filterable'=>1,'comparable'=>1,'sort_order'=>$sort*10]);
            $attr=(int)$db->lastInsertId();
            $db->insert('mc_attribute_translation',['attribute_id'=>$attr,'locale'=>'uk-UA','name'=>$name,'unit_label'=>null]);
        }
        $db->insert('mc_product_attribute_value',['product_id'=>$productId,'variant_id'=>null,'attribute_id'=>(int)$attr,'locale'=>'uk-UA','value_text'=>$value,'value_text_hash'=>hash('sha256',$value,true),'value_decimal'=>null,'value_boolean'=>null,'value_json'=>null,'sort_order'=>$sort*10]);
    }

    private function createArticle(Connection $db,int $storeId,array $article,string $now,int $sort): void
    {
        $public=$this->publicIds->generate();
        $db->insert('mc_content_entry',['public_id'=>$public->toBinary(),'store_id'=>$storeId,'content_type'=>'article','system_key'=>null,'status'=>'published','author_subject'=>'demo:editorial','published_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$db->lastInsertId();
        $db->insert('mc_content_translation',['content_id'=>$id,'locale'=>'uk-UA','title'=>$article['title'],'excerpt'=>$article['excerpt'],'body_html'=>$article['body'],'meta_title'=>$article['title'],'meta_description'=>$article['excerpt'],'created_at'=>$now,'updated_at'=>$now]);
        $this->seo->ensureForCreatedEntity($storeId,'uk-UA',SeoEntityType::BlogArticle,$public->toRfc4122(),$article['title'],$article['slug']);
        $this->tag($db,$storeId,'article',$public->toRfc4122(),'seed',['kind'=>'article','image'=>$article['image'],'sort'=>$sort]);
        $this->tag($db,$storeId,'article',$public->toRfc4122(),'image',['path'=>$article['image']]);
    }

    private function seedInformationPagesDemo(Connection $db,int $storeId,string $now): void
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
        foreach($pages as $key=>$page){
            $row=$db->fetchAssociative("SELECT ce.id,ce.status,ct.body_html FROM mc_content_entry ce JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale='uk-UA' WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key=? LIMIT 1",[$storeId,$key]);
            if(!is_array($row) || trim((string)($row['body_html']??''))!==''){continue;}
            $db->update('mc_content_entry',['status'=>'published','author_subject'=>'demo:seed','published_at'=>$now,'updated_at'=>$now],['id'=>(int)$row['id']]);
            $db->update('mc_content_translation',['title'=>$page[0],'excerpt'=>$page[1],'body_html'=>$page[2],'meta_title'=>$page[0],'meta_description'=>$page[1],'updated_at'=>$now],['content_id'=>(int)$row['id'],'locale'=>'uk-UA']);
        }
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
    private function demoPresentation(): array
    {
        return [
            'utility'=>['location'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.ukraina'),'delivery'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.bezkoshtovna_dostavka_vid_2_000'),'support'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pidtrymka_shchodnia')],
            'brand'=>['title'=>'Modern Market','subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiina_vitryna_modern_commerce')],
            'theme'=>['primary'=>'#0B63F6','accent'=>'#FF7A1A','success'=>'#16A364'],
            'header'=>['search_placeholder'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.poshuk_tovariv_brendiv_abo_katehorii'),'show_category_nav'=>true],
            'home'=>['show_benefits'=>true,'show_categories'=>true,'show_products'=>true,'show_promos'=>true,'show_articles'=>true],
            'hero'=>['eyebrow'=>'Nexora Commerce','title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.hotovyi_suchasnyi_mahazyn'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.kataloh_checkout_kontent_i_marketynh_v_odnii_systemi'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demonstratsiina_vitryna_vstanovliuietsia_razom_iz_sy'),'image'=>'/media/demo/laptop-pro-14.webp','button_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pereity_do_katalohu'),'button_url'=>'/catalog'],
            'promo_left'=>['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.smartfony_ta_hadzhety'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.demo_tovary_z_tsinamy_zalyshkamy_ta_vidhukamy'),'image'=>'/media/demo/promo-smartphone-reference.webp','url'=>'/smartphones'],
            'promo_right'=>['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.krasa_ta_dohliad'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.katehorii_aktsii_ta_kontentni_bloky'),'image'=>'/media/demo/promo-beauty-reference.webp','url'=>'/beauty-health'],
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
