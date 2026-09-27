<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminAccessController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly PublicIdFactory $publicIds,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {}

    #[Route('/admin/system/access', name:'admin_access_index', methods:['GET'])]
    public function index(): Response
    {
        $roles=[];$users=[];$stores=[];$permissions=[];
        try {
            $roles=$this->db->fetchAllAssociative('SELECT code,name,description,is_system FROM mc_admin_role ORDER BY is_system DESC,name');
            foreach($roles as &$role){$role['permissions']=$this->db->fetchFirstColumn('SELECT permission_code FROM mc_admin_role_permission WHERE role_code=? ORDER BY permission_code',[$role['code']]);} unset($role);
            $users=$this->db->fetchAllAssociative('SELECT id,public_id,email,display_name,roles,status,last_login_at FROM mc_admin_user ORDER BY display_name,email');
            foreach($users as &$u){$u['roles']=json_decode((string)$u['roles'],true)?:[];$u['stores']=$this->db->fetchFirstColumn('SELECT store_id FROM mc_admin_store_scope WHERE admin_user_id=? ORDER BY store_id',[(int)$u['id']]);} unset($u);
            $stores=$this->db->fetchAllAssociative('SELECT id,name,status FROM mc_store ORDER BY id');
            $permissions=$this->db->fetchAllAssociative('SELECT code,description,risk_level FROM mc_admin_permission ORDER BY code');
        } catch(Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.ne_vdalosia_prochytaty_matrytsiu_dostupu').$this->safe($e));}
        return $this->render('@storefront/admin/system/access.html.twig',['roles'=>$roles,'users'=>$users,'stores'=>$stores,'permissions'=>$permissions,'permission_groups'=>$this->groups()]);
    }

    #[Route('/admin/system/access/role/save', name:'admin_access_role_save', methods:['POST'])]
    public function saveRole(Request $request): Response
    {
        if(!$this->isCsrfTokenValid('admin_access_role',(string)$request->request->get('_csrf_token'))){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));return $this->redirectToRoute('admin_access_index');}
        $code=strtoupper(trim((string)$request->request->get('code'))); $name=trim((string)$request->request->get('name')); $description=trim((string)$request->request->get('description'));
        if(!preg_match('/^ROLE_[A-Z0-9_]{3,80}$/D',$code)||$name===''){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.vkazhit_korektnyi_kod_role_i_nazvu_roli'));return $this->redirectToRoute('admin_access_index');}
        try {
            $allowed=array_map('strval',$this->db->fetchFirstColumn('SELECT code FROM mc_admin_permission'));
            $selected=array_values(array_intersect($allowed,array_map('strval',(array)$request->request->all('permissions'))));
            $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $this->db->transactional(function(Connection $db)use($code,$name,$description,$selected,$now):void{
                $existing=$db->fetchAssociative('SELECT is_system FROM mc_admin_role WHERE code=?',[$code]);
                if($existing){$db->update('mc_admin_role',['name'=>mb_substr($name,0,190),'description'=>mb_substr($description,0,500),'updated_at'=>$now],['code'=>$code]);}
                else{$db->insert('mc_admin_role',['code'=>$code,'name'=>mb_substr($name,0,190),'description'=>mb_substr($description,0,500),'is_system'=>0,'created_at'=>$now,'updated_at'=>$now]);}
                $db->delete('mc_admin_role_permission',['role_code'=>$code]);
                foreach($selected as $permission)$db->insert('mc_admin_role_permission',['role_code'=>$code,'permission_code'=>$permission]);
            });
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.rol_i_matrytsiu_prav_zberezheno'));
        }catch(Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.rol_ne_zberezheno').$this->safe($e));}
        return $this->redirectToRoute('admin_access_index');
    }

    #[Route('/admin/system/access/role/{code}/delete', name:'admin_access_role_delete', methods:['POST'])]
    public function deleteRole(Request $request,string $code): Response
    {
        if(!$this->isCsrfTokenValid('admin_access_role_delete_'.$code,(string)$request->request->get('_csrf_token'))){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));return $this->redirectToRoute('admin_access_index');}
        try{
            $system=(int)$this->db->fetchOne('SELECT is_system FROM mc_admin_role WHERE code=?',[$code]); if($system===1)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.systemnu_rol_vydaliaty_ne_mozhna'));
            $used=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_admin_user WHERE JSON_CONTAINS(roles, ?)',[json_encode($code,JSON_THROW_ON_ERROR)]); if($used>0)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.rol_shche_pryznachena_administratoram'));
            $this->db->delete('mc_admin_role_permission',['role_code'=>$code]);$this->db->delete('mc_admin_role',['code'=>$code]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.rol_vydaleno'));
        }catch(Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.rol_ne_vydaleno').$this->safe($e));}
        return $this->redirectToRoute('admin_access_index');
    }

    #[Route('/admin/system/access/user/save', name:'admin_access_user_save', methods:['POST'])]
    public function saveUser(Request $request): Response
    {
        if(!$this->isCsrfTokenValid('admin_access_user',(string)$request->request->get('_csrf_token'))){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));return $this->redirectToRoute('admin_access_index');}
        $id=(int)$request->request->get('id',0);$email=mb_strtolower(trim((string)$request->request->get('email')),'UTF-8');$name=trim((string)$request->request->get('display_name'));$password=(string)$request->request->get('password');$status=(string)$request->request->get('status','active');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$name===''){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.vkazhit_korektne_imia_ta_email'));return $this->redirectToRoute('admin_access_index');}
        try{
            $roleCodes=array_map('strval',$this->db->fetchFirstColumn('SELECT code FROM mc_admin_role'));
            $roles=array_values(array_intersect($roleCodes,array_map('strval',(array)$request->request->all('roles')))); if($roles===[])$roles=['ROLE_VIEWER'];
            $storeIds=array_values(array_unique(array_filter(array_map('intval',(array)$request->request->all('stores')),fn(int $v)=>$v>0)));
            $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $this->db->transactional(function(Connection $db)use(&$id,$email,$name,$password,$status,$roles,$storeIds,$now):void{
                if($id>0){
                    $before=$db->fetchAssociative('SELECT roles,status FROM mc_admin_user WHERE id=? FOR UPDATE',[$id]); if(!$before)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.administratora_ne_znaideno'));
                    $beforeRoles=json_decode((string)$before['roles'],true)?:[]; $removesSuper=in_array('ROLE_SUPER_ADMIN',$beforeRoles,true)&&(!in_array('ROLE_SUPER_ADMIN',$roles,true)||$status!=='active');
                    if($removesSuper && $this->activeSuperAdminCount($db)<=1)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.ne_mozhna_vymknuty_abo_pozbavyty_prav_ostannoho_supe'));
                    $data=['email'=>$email,'email_normalized'=>$email,'display_name'=>mb_substr($name,0,190),'roles'=>json_encode($roles,JSON_THROW_ON_ERROR),'status'=>in_array($status,['active','disabled'],true)?$status:'active','updated_at'=>$now];
                    if($password!==''){$this->validatePassword($password);$data['password_hash']=$this->hashers->getPasswordHasher(AdminUser::class)->hash($password);} $db->update('mc_admin_user',$data,['id'=>$id]);
                }else{
                    $this->validatePassword($password);$db->insert('mc_admin_user',['public_id'=>$this->publicIds->binary(),'email'=>$email,'email_normalized'=>$email,'display_name'=>mb_substr($name,0,190),'password_hash'=>$this->hashers->getPasswordHasher(AdminUser::class)->hash($password),'roles'=>json_encode($roles,JSON_THROW_ON_ERROR),'status'=>'active','created_at'=>$now,'updated_at'=>$now,'last_login_at'=>null]);$id=(int)$db->lastInsertId();
                }
                $db->delete('mc_admin_store_scope',['admin_user_id'=>$id]); foreach($storeIds as $storeId)$db->insert('mc_admin_store_scope',['admin_user_id'=>$id,'store_id'=>$storeId]);
            });
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.dostup_administratora_zberezheno'));
        }catch(Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.dostup_ne_zberezheno').$this->safe($e));}
        return $this->redirectToRoute('admin_access_index');
    }

    private function activeSuperAdminCount(Connection $db): int { return (int)$db->fetchOne("SELECT COUNT(*) FROM mc_admin_user WHERE status='active' AND JSON_CONTAINS(roles, ?)",[json_encode('ROLE_SUPER_ADMIN',JSON_THROW_ON_ERROR)]); }
    private function validatePassword(string $password): void { if(strlen($password)<12)throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.parol_administratora_maie_mistyty_shchonaimenshe_12_')); }
    private function safe(Throwable $e): string { return mb_substr((string)(preg_replace('/[\\r\\n\\t]+/',' ',$e->getMessage())?:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.operatsiia_zavershylas_pomylkoiu')),0,350); }
    private function groups(): array { $groups = [
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.kataloh')=>['catalog.view','catalog.manage','catalog.bulk','catalog.export','catalog.delete','search.view','search.manage','media.view','media.manage','media.delete'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.zamovlennia')=>['orders.view','orders.manage','orders.refund','orders.export'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.pokuptsi')=>['customers.view','personal_data.view','customers.manage','customers.export','notifications.view','notifications.manage'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.kontent')=>['content.view','content.manage','content.delete','forum.view','forum.manage'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.marketynh')=>['marketing.view','marketing.manage','feeds.view','feeds.manage'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.vyhliad')=>['appearance.view','appearance.manage'],
        \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.systema')=>['dashboard.view','analytics.view','rewards.view','rewards.manage','system.settings','system.recovery','system.update','system.cron.view','system.cron.manage','system.audit.view','extensions.manage','integrations.manage','admin_users.manage'],
    ];
        try { $extensionPermissions=array_map('strval',$this->db->fetchFirstColumn("SELECT code FROM mc_admin_permission WHERE code LIKE 'extension.%' ORDER BY code")); } catch (\Throwable) { $extensionPermissions=[]; }
        if($extensionPermissions!==[])$groups[\Commerce\Core\I18n\CanonicalUiText::get('admin_extensions')]=$extensionPermissions;
        return $groups;
    }
}
