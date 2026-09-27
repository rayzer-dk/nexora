<?php

declare(strict_types=1);

namespace Commerce\Modules\B2B\Http;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\B2B\Application\B2bCommerceService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class B2bAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly AdminContextResolver $contexts, private readonly PublicIdFactory $ids, private readonly B2bCommerceService $b2b) {}

    #[Route('/admin/b2b', name:'admin_b2b', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $companies=$this->db->fetchAllAssociative("SELECT c.*,COUNT(DISTINCT m.customer_id) member_count,COUNT(DISTINCT pc.price_list_id) price_list_count FROM mc_b2b_company c LEFT JOIN mc_b2b_company_member m ON m.company_id=c.id LEFT JOIN mc_b2b_price_list_company pc ON pc.company_id=c.id WHERE c.store_id=? GROUP BY c.id ORDER BY c.name",[$ctx->storeId]);
        $lists=$this->db->fetchAllAssociative("SELECT pl.*,COUNT(DISTINCT pc.company_id) company_count,COUNT(DISTINCT t.id) tier_count FROM mc_b2b_price_list pl LEFT JOIN mc_b2b_price_list_company pc ON pc.price_list_id=pl.id LEFT JOIN mc_b2b_price_tier t ON t.price_list_id=pl.id WHERE pl.store_id=? GROUP BY pl.id ORDER BY pl.priority,pl.name",[$ctx->storeId]);
        $members=$this->db->fetchAllAssociative("SELECT m.company_id,m.customer_id,m.role,m.status,m.spending_limit_minor,c.display_name,c.email,bc.name company_name FROM mc_b2b_company_member m JOIN mc_customer c ON c.id=m.customer_id JOIN mc_b2b_company bc ON bc.id=m.company_id WHERE bc.store_id=? ORDER BY bc.name,c.display_name,c.email",[$ctx->storeId]);
        $tiers=$this->db->fetchAllAssociative("SELECT t.*,pl.name price_list_name,v.sku FROM mc_b2b_price_tier t JOIN mc_b2b_price_list pl ON pl.id=t.price_list_id JOIN mc_product_variant v ON v.id=t.variant_id WHERE pl.store_id=? ORDER BY pl.priority,pl.name,v.sku,t.min_quantity",[$ctx->storeId]);
        $pending=$this->db->fetchAllAssociative("SELECT o.id,o.order_number,o.total_minor,o.currency,o.created_at,o.purchase_order_number,c.name company_name FROM mc_sales_order o JOIN mc_b2b_company c ON c.id=o.b2b_company_id WHERE o.store_id=? AND o.b2b_approval_status='pending' ORDER BY o.created_at ASC",[$ctx->storeId]);
        return $this->render('@storefront/admin/b2b/index.html.twig',compact('companies','lists','members','tiers','pending'));
    }

    #[Route('/admin/b2b/company', name:'admin_b2b_company_save', methods:['POST'])]
    public function company(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'b2b_company');
        $name=mb_substr(trim((string)$request->request->get('name','')),0,190); if($name==='') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.vkazhit_nazvu_kompanii'));
        $currency=strtoupper(trim((string)$request->request->get('currency',$ctx->currency))); if(preg_match('/^[A-Z]{3}$/D',$currency)!==1) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_valiuta'));
        $status=(string)$request->request->get('status','active'); if(!in_array($status,['pending','active','suspended'],true))$status='pending';
        $now=$this->now(); $this->db->insert('mc_b2b_company',['public_id'=>$this->ids->binary(),'store_id'=>$ctx->storeId,'name'=>$name,'legal_name'=>$this->text($request,'legal_name',255),'tax_id'=>$this->text($request,'tax_id',64),'vat_id'=>$this->text($request,'vat_id',64),'currency'=>$currency,'status'=>$status,'credit_limit_minor'=>$this->money($request,'credit_limit'),'payment_terms_days'=>$this->smallInt($request,'payment_terms_days',0,365),'approval_threshold_minor'=>$this->nullableMoney($request,'approval_threshold'),'created_at'=>$now,'updated_at'=>$now]);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.b2b_kompaniiu_stvoreno')); return $this->redirectToRoute('admin_b2b');
    }

    #[Route('/admin/b2b/member', name:'admin_b2b_member_save', methods:['POST'])]
    public function member(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'b2b_member'); $company=$request->request->getInt('company_id');
        $exists=$this->db->fetchOne('SELECT id FROM mc_b2b_company WHERE id=? AND store_id=?',[$company,$ctx->storeId]); if((int)$exists!==$company) throw $this->createNotFoundException();
        $email=mb_strtolower(trim((string)$request->request->get('email',''))); if(filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.vkazhit_email_isnuiuchoho_pokuptsia'));
        $customer=$this->db->fetchOne('SELECT id FROM mc_customer WHERE email_normalized=? AND status=\'active\' LIMIT 1',[$email]); if($customer===false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.pokuptsia_z_takym_email_ne_znaideno'));
        $role=(string)$request->request->get('role','buyer'); if(!in_array($role,['buyer','approver','admin'],true))$role='buyer'; $now=$this->now();
        $this->db->executeStatement("INSERT INTO mc_b2b_company_member(company_id,customer_id,role,status,spending_limit_minor,created_at,updated_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),status=VALUES(status),spending_limit_minor=VALUES(spending_limit_minor),updated_at=VALUES(updated_at)",[$company,(int)$customer,$role,'active',$this->nullableMoney($request,'spending_limit'),$now,$now]);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.spivrobitnyka_kompanii_zberezheno')); return $this->redirectToRoute('admin_b2b');
    }

    #[Route('/admin/b2b/price-list', name:'admin_b2b_price_list_save', methods:['POST'])]
    public function priceList(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'b2b_price_list'); $name=mb_substr(trim((string)$request->request->get('name','')),0,190); if($name==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.vkazhit_nazvu_prais_lysta'));
        $currency=strtoupper(trim((string)$request->request->get('currency',$ctx->currency))); if(preg_match('/^[A-Z]{3}$/D',$currency)!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_valiuta')); $now=$this->now();
        $this->db->insert('mc_b2b_price_list',['public_id'=>$this->ids->binary(),'store_id'=>$ctx->storeId,'name'=>$name,'currency'=>$currency,'priority'=>max(0,min(9999,$request->request->getInt('priority',100))),'status'=>'active','starts_at'=>null,'ends_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$this->db->lastInsertId(); foreach(array_unique(array_map('intval',(array)$request->request->all('company_ids'))) as $company){if($company<1)continue;$ok=$this->db->fetchOne('SELECT id FROM mc_b2b_company WHERE id=? AND store_id=?',[$company,$ctx->storeId]);if((int)$ok===$company)$this->db->insert('mc_b2b_price_list_company',['price_list_id'=>$id,'company_id'=>$company]);}
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.b2b_prais_lyst_stvoreno')); return $this->redirectToRoute('admin_b2b');
    }

    #[Route('/admin/b2b/price-tier', name:'admin_b2b_price_tier_save', methods:['POST'])]
    public function tier(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'b2b_price_tier'); $list=$request->request->getInt('price_list_id'); $sku=mb_substr(trim((string)$request->request->get('sku','')),0,190);
        $listRow=$this->db->fetchAssociative('SELECT id FROM mc_b2b_price_list WHERE id=? AND store_id=? AND status=\'active\'',[$list,$ctx->storeId]); if(!is_array($listRow)) throw $this->createNotFoundException(); $variant=$this->db->fetchOne('SELECT id FROM mc_product_variant WHERE sku=? AND status=\'active\' LIMIT 1',[$sku]); if($variant===false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.sku_ne_znaideno'));
        $min=$this->quantity($request,'min_quantity','1'); $maxRaw=trim((string)$request->request->get('max_quantity','')); $max=$maxRaw===''?null:$this->quantity($request,'max_quantity',$maxRaw); if($max!==null && (float)$max<(float)$min)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.maksymalna_kilkist_mensha_za_minimalnu'));
        $amount=$this->money($request,'price'); if($amount<1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.tsina_maie_buty_bilshoiu_za_nul')); $now=$this->now();
        $this->db->executeStatement("INSERT INTO mc_b2b_price_tier(price_list_id,variant_id,min_quantity,max_quantity,amount_minor,created_at,updated_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE max_quantity=VALUES(max_quantity),amount_minor=VALUES(amount_minor),updated_at=VALUES(updated_at)",[$list,(int)$variant,$min,$max,$amount,$now,$now]);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.tsinovyi_riven_zberezheno')); return $this->redirectToRoute('admin_b2b');
    }

    #[Route('/admin/b2b/order/{id}/decision', name:'admin_b2b_order_decision', methods:['POST'])]
    public function decision(int $id, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'b2b_order_'.$id); $this->b2b->approveOrder($ctx->storeId,$id,(string)$request->request->get('decision')==='approve'); $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.rishennia_po_b2b_zamovlenniu_zberezheno')); return $this->redirectToRoute('admin_b2b');
    }

    private function csrf(Request $r,string $id):void{if(!$this->isCsrfTokenValid($id,(string)$r->request->get('_csrf_token')))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));}
    private function text(Request $r,string $key,int $max):?string{$v=mb_substr(trim(strip_tags((string)$r->request->get($key,''))),0,$max);return $v===''?null:$v;}
    private function money(Request $r,string $key):int{$v=str_replace(',','.',trim((string)$r->request->get($key,'0')));if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$v))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.nekorektna_hroshova_suma'));return (int)round((float)$v*100);}
    private function nullableMoney(Request $r,string $key):?int{$v=trim((string)$r->request->get($key,''));return $v===''?null:$this->money($r,$key);}
    private function smallInt(Request $r,string $key,int $min,int $max):int{return max($min,min($max,$r->request->getInt($key,$min)));}
    private function quantity(Request $r,string $key,string $default):string{$v=str_replace(',','.',trim((string)$r->request->get($key,$default)));if(!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',$v)||(float)$v<=0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.nekorektna_kilkist'));return number_format((float)$v,6,'.','');}
    private function now():string{return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
}
