<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Analytics\Dashboard\DashboardChart;
use Commerce\Modules\Analytics\Dashboard\DashboardService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminDashboardController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly DashboardService $dashboard, private readonly OnboardingAdminController $onboarding, private readonly \Commerce\Modules\Catalog\Application\CatalogQualityService $catalogQuality, private readonly \Commerce\Modules\Quality\Application\EarlyWarningService $warnings)
    {
    }

    #[Route('/admin', name:'admin_dashboard', methods:['GET'])]
    public function __invoke(Request $request, Connection $connection): Response
    {
        $context = $this->contexts->resolve($request);
        $days = $request->query->getInt('days', 30);
        $report = $this->dashboard->build($context->storeId, $days);
        $store = $connection->fetchAssociative('SELECT id,name,default_locale,default_currency,timezone FROM mc_store WHERE id=?', [$context->storeId]) ?: [];
        $counts = [
            'products' => $this->count($connection, 'SELECT COUNT(*) FROM mc_store_product WHERE store_id=?', [$context->storeId]),
            'categories' => $this->count($connection, 'SELECT COUNT(*) FROM mc_store_category WHERE store_id=?', [$context->storeId]),
            'orders' => $this->count($connection, 'SELECT COUNT(*) FROM mc_sales_order WHERE store_id=?', [$context->storeId]),
        ];
        $groups = [
            'orders' => ['icon'=>'shopping-cart','items'=>[
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.zamovlennia_do_obrobky'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND status IN ('pending','confirmed','processing')",[$context->storeId]),'url'=>'/admin/orders','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.perevirte_oplatu_komplektatsiiu_ta_dostavku')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.returns_open'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_return_request WHERE store_id=? AND status='requested'",[$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.returns_open_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.withdrawals_new'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_withdrawal_notice WHERE store_id=? AND status='received'",[$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.withdrawals_new_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.unpaid_old'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND status='awaiting_payment' AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)",[$context->storeId]),'url'=>'/admin/orders','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.unpaid_old_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.b2b_pending'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND b2b_approval_status='pending'",[$context->storeId]),'url'=>'/admin/b2b','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.b2b_pending_hint')],
            ]],
            'customers' => ['icon'=>'users','items'=>[
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.novi_zvernennia'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_customer_inquiry WHERE store_id=? AND status IN ('new','in_progress')",[$context->storeId]),'url'=>'/admin/commerce/inquiries','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.zapyty_kliientiv_iaki_chekaiut_vidpovidi')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.moderation'),'count'=>$this->count($connection,"SELECT (SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND status='pending')+(SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND status='pending')",[$context->storeId,$context->storeId]),'url'=>'/admin/customer-experience','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.moderation_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.leads_unreminded'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_checkout_lead l JOIN mc_cart c ON c.id=l.cart_id WHERE l.store_id=? AND c.status='active' AND l.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) AND NOT EXISTS(SELECT 1 FROM mc_marketing_automation_delivery d WHERE d.cart_id=c.id)",[$context->storeId]),'url'=>'/admin/automation','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.leads_unreminded_hint')],
            ]],
            'catalog' => ['icon'=>'package','items'=>[
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.nyzki_zalyshky'),'count'=>$this->count($connection,"SELECT COUNT(DISTINCT vii.variant_id) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_product_variant v ON v.id=vii.variant_id JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=? WHERE (sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock)<=3",[$context->storeId]),'url'=>'/admin/catalog/products','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.tovary_z_dostupnym_zalyshkom_3')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.problemy_google'),'count'=>$this->count($connection,'SELECT COUNT(*) FROM mc_google_merchant_product_state WHERE store_id=? AND issue_count>0',[$context->storeId]),'url'=>'/admin/system/integrations','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.vidkhyleni_abo_problemni_merchant_items')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.tovary_bez_gtin'),'count'=>$this->count($connection,"SELECT COUNT(DISTINCT p.id) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 WHERE p.status='published' AND (v.gtin IS NULL OR v.gtin='')",[$context->storeId]),'url'=>'/admin/catalog/products','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.perevirte_lyshe_tam_de_gtin_spravdi_potribnyi')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.zero_searches'),'count'=>$this->count($connection,"SELECT COUNT(DISTINCT query_hash) FROM mc_search_query_log WHERE store_id=? AND result_count=0 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",[$context->storeId]),'url'=>'/admin/analytics','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.zero_searches_hint')],
            ]],
            'system' => ['icon'=>'heart-pulse','items'=>[
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.pomylky_povidomlen'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_notification_outbox WHERE status='failed'"),'url'=>'/admin/commerce/notifications','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.admindashboardcontroller.email_telegram_sms_shcho_potrebuiut_uvahy')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_stale'),'count'=>$this->cronStale($connection),'url'=>'/admin/system/cron','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_stale_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_failed'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_scheduled_task_state WHERE last_status='failed'"),'url'=>'/admin/system/cron','tone'=>'red','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cron_failed_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.early_warnings'),'count'=>count($this->warnings->warnings($context->storeId)),'url'=>'/admin/system/early-warnings','tone'=>'orange','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.early_warnings_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.broken_links'),'count'=>$this->count($connection,'SELECT COUNT(*) FROM mc_link_check_issue WHERE ignored=0',[]),'url'=>'/admin/system/link-check','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.broken_links_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.missing_pages'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_not_found_log WHERE hit_count>=3",[]),'url'=>'/admin/system/seo-custom-redirects','tone'=>'yellow','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.missing_pages_hint')],
            ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cards_expiring'),'count'=>$this->count($connection,"SELECT COUNT(*) FROM mc_gift_card WHERE store_id=? AND status='active' AND balance_minor>0 AND expires_at IS NOT NULL AND expires_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 14 DAY)",[$context->storeId]),'url'=>'/admin/rewards','tone'=>'blue','hint'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.dashboard.cards_expiring_hint')],
            ]],
        ];
        $backupAge=null;
        try {
            $created=$connection->fetchOne("SELECT created_at FROM mc_recovery_snapshot WHERE status IN ('ready','verified') ORDER BY id DESC LIMIT 1");
            if(is_string($created)&&$created!=='')$backupAge=max(0,(int)floor((time()-strtotime($created))/86400));
        } catch(\Throwable) {}
        $tz = (string) ($store['timezone'] ?? 'UTC');
        try {
            $midnight = (new \DateTimeImmutable('today', new \DateTimeZone($tz !== '' ? $tz : 'UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $midnight = gmdate('Y-m-d 00:00:00');
        }
        $today = [
            'orders' => $this->count($connection, "SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND status<>'cancelled' AND created_at>=?", [$context->storeId, $midnight]),
            'customers' => $this->count($connection, 'SELECT COUNT(*) FROM mc_customer WHERE created_at>=?', [$midnight]),
        ];
        $cronBad = $this->cronStale($connection) + $this->count($connection, "SELECT COUNT(*) FROM mc_scheduled_task_state WHERE last_status='failed'");
        $outboxFailed = $this->count($connection, "SELECT COUNT(*) FROM mc_notification_outbox WHERE status='failed'");
        $health = [
            ['key' => 'cron', 'icon' => 'clock', 'url' => '/admin/system/cron', 'status' => $cronBad > 0 ? 'bad' : 'ok'],
            ['key' => 'backup', 'icon' => 'database', 'url' => '/admin/system/stability', 'status' => $backupAge === null ? 'bad' : ($backupAge > 7 ? 'warn' : 'ok'), 'days' => $backupAge],
            ['key' => 'mail', 'icon' => 'mail', 'url' => '/admin/commerce/notifications', 'status' => $outboxFailed > 0 ? 'bad' : 'ok', 'count' => $outboxFailed],
            ['key' => 'warnings', 'icon' => 'triangle-alert', 'url' => '/admin/system/early-warnings', 'status' => count($this->warnings->warnings($context->storeId)) > 0 ? 'warn' : 'ok', 'count' => count($this->warnings->warnings($context->storeId))],
            ['key' => 'network', 'icon' => 'shield-check', 'url' => '/admin/system/network', 'status' => $request->isSecure() ? 'ok' : 'warn'],
        ];
        $user=$this->getUser();
        $steps=$this->onboarding->steps($context->storeId,$user instanceof AdminUser?$user->id:0);
        $onboarding=['done'=>count(array_filter($steps,static fn(array $s):bool=>$s['done'])),'total'=>count($steps)];
        try{$cq=$this->catalogQuality->summary($context->storeId);}catch(\Throwable){$cq=null;}
        return $this->render('@storefront/admin/dashboard.html.twig',['catalog_quality'=>$cq,'onboarding'=>$onboarding,'platform_version'=>PlatformVersion::VERSION,'store'=>$store,'counts'=>$counts,'groups'=>$groups,'today'=>$today,'health'=>$health,'report'=>$report,'chart'=>DashboardChart::layout($report['series'],$report['annotations']),'backup_age_days'=>$backupAge,'admin_name'=>$user instanceof AdminUser?$user->displayName:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator')]);
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
