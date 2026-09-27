<?php

declare(strict_types=1);
namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Navigation\Application\NavigationManager;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NavigationAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts,private readonly NavigationManager $navigation,private readonly Connection $db){}
    #[Route('/admin/appearance/navigation',name:'admin_appearance_navigation',methods:['GET'])]
    public function index(Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);$menu=(string)$request->query->get('menu','header');if(!in_array($menu,['header','footer','utility'],true))$menu='header';$rows=$this->db->fetchAllAssociative('SELECT n.id,n.parent_id,n.menu_code,n.item_type,n.target_ref,n.url,n.status,n.sort_order,n.open_new_tab FROM mc_navigation_item n WHERE n.store_id=? AND n.menu_code=? ORDER BY n.parent_id IS NOT NULL,n.sort_order,n.id',[$ctx->storeId,$menu]);$locales=$this->db->fetchAllAssociative('SELECT l.code,COALESCE(NULLIF(l.native_name,\'\'),l.name,l.code) name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.sort_order',[$ctx->storeId]);foreach($rows as &$row){$row['translations']=$this->db->fetchAllKeyValue('SELECT locale,label FROM mc_navigation_item_translation WHERE navigation_item_id=?',[(int)$row['id']]);}unset($row);$editId=$request->query->getInt('edit',0);$editing=null;foreach($rows as $row){if((int)$row['id']===$editId){$editing=$row;break;}}return$this->render('@storefront/admin/appearance/navigation.html.twig',['items'=>$rows,'locales'=>$locales,'menu'=>$menu,'editing'=>$editing]);
    }
    #[Route('/admin/appearance/navigation/save',name:'admin_appearance_navigation_save',methods:['POST'])]
    public function save(Request $request):Response
    {
        if(!$this->isCsrfTokenValid('navigation_save',(string)$request->request->get('_csrf_token')))throw$this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$labels=[];foreach($request->request->all('label') as $locale=>$label){if(is_scalar($label))$labels[(string)$locale]=(string)$label;}try{$this->navigation->save($ctx->storeId,$request->request->getInt('id',0)?:null,['menu_code'=>$request->request->get('menu_code','header'),'parent_id'=>$request->request->getInt('parent_id',0),'item_type'=>$request->request->get('item_type','custom'),'target_ref'=>$request->request->get('target_ref',''),'url'=>$request->request->get('url',''),'status'=>$request->request->get('status','active'),'sort_order'=>$request->request->getInt('sort_order',0),'open_new_tab'=>$request->request->getBoolean('open_new_tab')],$labels);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.navigationadmincontroller.punkt_meniu_zberezheno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}return$this->redirectToRoute('admin_appearance_navigation',['menu'=>$request->request->get('menu_code','header')]);
    }
    #[Route('/admin/appearance/navigation/{id}/delete',name:'admin_appearance_navigation_delete',methods:['POST'],requirements:['id'=>'\\d+'])]
    public function delete(int $id,Request $request):Response{if(!$this->isCsrfTokenValid('navigation_delete_'.$id,(string)$request->request->get('_csrf_token')))throw$this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$this->navigation->delete($ctx->storeId,$id);return$this->redirectToRoute('admin_appearance_navigation');}
}
