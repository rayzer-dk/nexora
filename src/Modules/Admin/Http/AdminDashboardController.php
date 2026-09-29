<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminDashboardController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts)
    {
    }

    #[Route('/admin', name:'admin_dashboard', methods:['GET'])]
    public function __invoke(Request $request, Connection $connection): Response
    {
        $context = $this->contexts->resolve($request);
        $store = $connection->fetchAssociative('SELECT id,name,default_locale,default_currency,timezone FROM mc_store WHERE id=?', [$context->storeId]) ?: [];
        $counts = [
            'products' => $this->count($connection, 'SELECT COUNT(*) FROM mc_store_product WHERE store_id=?', [$context->storeId]),
            'categories' => $this->count($connection, 'SELECT COUNT(*) FROM mc_store_category WHERE store_id=?', [$context->storeId]),
            'orders' => $this->count($connection, 'SELECT COUNT(*) FROM mc_sales_order WHERE store_id=?', [$context->storeId]),
        ];
        $attention = [
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.zamovlennia_do_obrobky'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND status IN ('pending','confirmed','processing')",[$context->storeId]),'url'=>'/admin/orders','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.perevirte_oplatu_komplektatsiiu_ta_dostavku')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.nyzki_zalyshky'),'count'=>$this->count($connection,"SELECT COUNT(DISTINCT vii.variant_id) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_product_variant v ON v.id=vii.variant_id JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=? WHERE (sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock)<=3",[$context->storeId]),'url'=>'/admin/catalog/products','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.tovary_z_dostupnym_zalyshkom_3')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.problemy_google'),'count'=>$this->count($connection,'SELECT COUNT(*) FROM mc_google_merchant_product_state WHERE store_id=? AND issue_count>0',[$context->storeId]),'url'=>'/admin/system/integrations','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.vidkhyleni_abo_problemni_merchant_items')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.tovary_bez_gtin'),'count'=>$this->count($connection,"SELECT COUNT(DISTINCT p.id) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 WHERE p.status='published' AND (v.gtin IS NULL OR v.gtin='')",[$context->storeId]),'url'=>'/admin/catalog/products','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.perevirte_lyshe_tam_de_gtin_spravdi_potribnyi')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.novi_zvernennia'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_customer_inquiry WHERE store_id=? AND status IN ('new','in_progress')",[$context->storeId]),'url'=>'/admin/commerce/inquiries','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.zapyty_kliientiv_iaki_chekaiut_vidpovidi')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.pomylky_povidomlen'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_notification_outbox WHERE status='failed'"),'url'=>'/admin/commerce/notifications','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.email_telegram_sms_shcho_potrebuiut_uvahy')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_stale'),'count'=>$this->cronStale($connection),'url'=>'/admin/system/cron','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_stale_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_failed'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_scheduled_task_state WHERE last_status='failed'"),'url'=>'/admin/system/cron','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_failed_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.returns_open'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_return_request WHERE store_id=? AND status='requested'",[$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.returns_open_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.withdrawals_new'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_withdrawal_notice WHERE store_id=? AND status='received'",[$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.withdrawals_new_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.moderation'),'count'=>$this->count($connection,"SELECT (SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND status='pending')+(SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND status='pending')",[$context->storeId,$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.moderation_hint')],
        ];
        $backupAge=null;
        try {
            $created=$connection->fetchOne("SELECT created_at FROM mc_recovery_snapshot WHERE status IN ('ready','verified') ORDER BY id DESC LIMIT 1");
            if(is_string($created)&&$created!=='')$backupAge=max(0,(int)floor((time()-strtotime($created))/86400));
        } catch(\Throwable) {}
        $user=$this->getUser();
        return $this->render('@storefront/admin/dashboard.html.twig',['platform_version'=>PlatformVersion::VERSION,'store'=>$store,'counts'=>$counts,'attention'=>$attention,'backup_age_days'=>$backupAge,'admin_name'=>$user instanceof AdminUser?$user->displayName:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator')]);
    }

    /** 1 when the scheduler has not completed any task for over 15 minutes (or never), else 0. */
    private function cronStale(Connection $db): int
    {
        try {
            $last = $db->fetchOne('SELECT MAX(last_finished_at) FROM mc_scheduled_task_state');
        } catch (\Throwable) {
            return 0;
        }
        if (!is_string($last) || $last === '') {
            return 1;
        }

        return (time() - (int) strtotime($last . ' UTC')) > 900 ? 1 : 0;
    }

    /** @param list<mixed> $params */
    private function count(Connection $db,string $sql,array $params=[]): int
    {
        try { return (int)$db->fetchOne($sql,$params); } catch(\Throwable) { return 0; }
    }
}
