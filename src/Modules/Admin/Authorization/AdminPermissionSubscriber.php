<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Authorization;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class AdminPermissionSubscriber implements EventSubscriberInterface
{
    public function __construct(private Security $security, private AdminAuthorizationService $authorization, private AdminContextResolver $contexts) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::CONTROLLER => ['onController', 32]]; }

    public function onController(ControllerEvent $event): void
    {
        $request=$event->getRequest(); $route=(string)$request->attributes->get('_route','');
        if ($route==='' || in_array($route,['admin_login','admin_logout'],true) || !str_starts_with($route,'admin_')) return;
        if ($route==='admin_extension_dynamic') return; // Dynamic extension controller enforces its own declared permission.
        $user=$this->security->getUser();
        if (!$user instanceof AdminUser) throw new AccessDeniedHttpException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.028a7bba4242'));
        $permission=$this->permissionForRoute($route,$request);
        if ($permission===null) {
            if (in_array('ROLE_SUPER_ADMIN',$user->getRoles(),true)) return;
            throw new AccessDeniedHttpException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.36a7185d3188'));
        }
        $storeId=null;
        if (!$this->isGlobalPermission($permission)) {
            try {$storeId=$this->contexts->resolve($request)->storeId;} catch (\Throwable) {throw new AccessDeniedHttpException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.803371db7e6a'));}
        }
        if (!$this->authorization->isGranted($user,$permission,$storeId)) throw new AccessDeniedHttpException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4d6a9d24eab1'));
    }

    private function permissionForRoute(string $route, Request $request): ?string
    {
        $get=$request->isMethod('GET');
        if ($route==='admin_dashboard'||$route==='admin_api_session'||$route==='admin_interface_language'||$route==='admin_mfa_challenge'||str_starts_with($route,'admin_account_security')) return AdminPermissionCatalog::DASHBOARD_VIEW; // Self-service: an administrator manages only their own second factor.
        if ($route==='admin_analytics') return AdminPermissionCatalog::ANALYTICS_VIEW;
        if (str_starts_with($route,'admin_b2b')) return $get?AdminPermissionCatalog::B2B_VIEW:AdminPermissionCatalog::B2B_MANAGE;
        if (str_starts_with($route,'admin_rewards')) return $get?AdminPermissionCatalog::REWARDS_VIEW:AdminPermissionCatalog::REWARDS_MANAGE;
        if (str_starts_with($route,'admin_access_')) return AdminPermissionCatalog::ADMIN_USERS_MANAGE;
        if ($route==='admin_system_activity') return AdminPermissionCatalog::SYSTEM_AUDIT_VIEW;
        if (str_starts_with($route,'admin_system_developer')) return AdminPermissionCatalog::DEVELOPER_TOOLS_VIEW;
        if (in_array($route,['admin_catalog_saved_view_save','admin_catalog_saved_view_delete'],true)) return AdminPermissionCatalog::CATALOG_VIEW;
        if (in_array($route,['admin_order_saved_view_save','admin_order_saved_view_delete'],true)) return AdminPermissionCatalog::ORDERS_VIEW;
        if (str_contains($route,'refund')) return AdminPermissionCatalog::ORDERS_REFUND;
        if (str_starts_with($route,'admin_order')||$route==='admin_orders'||str_starts_with($route,'admin_shipment')) return $get?AdminPermissionCatalog::ORDERS_VIEW:AdminPermissionCatalog::ORDERS_MANAGE;
        if ($route==='admin_customer_experience') return AdminPermissionCatalog::CUSTOMERS_VIEW;
        if (str_starts_with($route,'admin_return_')) return AdminPermissionCatalog::ORDERS_MANAGE;
        if (str_starts_with($route,'admin_review_')||str_starts_with($route,'admin_question_')) return AdminPermissionCatalog::CONTENT_MANAGE;
        if (in_array($route,['admin_commerce_export_products','admin_commerce_export_products_multilingual'],true)) return AdminPermissionCatalog::CATALOG_EXPORT;
        if ($route==='admin_commerce_import_wizard') return AdminPermissionCatalog::CATALOG_MANAGE;
        if (in_array($route,['admin_commerce_products_bulk','admin_catalog_products_bulk_edit'],true)) return AdminPermissionCatalog::CATALOG_BULK;
        if (str_contains($route,'catalog_product_delete')||str_contains($route,'catalog_products_delete')) return AdminPermissionCatalog::CATALOG_DELETE;
        if (str_starts_with($route,'admin_catalog_search')) return $get?AdminPermissionCatalog::SEARCH_VIEW:AdminPermissionCatalog::SEARCH_MANAGE;
        if (preg_match('/^admin_catalog_(?:product|category)_(?:edit|new)$/D',$route)===1 || str_contains($route,'products_bulk_edit')) return AdminPermissionCatalog::CATALOG_MANAGE;
        if (str_starts_with($route,'admin_catalog_')) return $get?AdminPermissionCatalog::CATALOG_VIEW:AdminPermissionCatalog::CATALOG_MANAGE;
        if ($route==='admin_commerce_customers') return $get?AdminPermissionCatalog::CUSTOMERS_VIEW:AdminPermissionCatalog::CUSTOMERS_MANAGE;
        if ($route==='admin_content_page_edit') return AdminPermissionCatalog::CONTENT_MANAGE;
        if (str_starts_with($route,'admin_content_')) return $get?AdminPermissionCatalog::CONTENT_VIEW:(str_contains($route,'delete')?AdminPermissionCatalog::CONTENT_DELETE:AdminPermissionCatalog::CONTENT_MANAGE);
        if (str_starts_with($route,'admin_appearance_builder')||$route==='admin_visual_store_editor') return AdminPermissionCatalog::APPEARANCE_MANAGE;
        if (str_starts_with($route,'admin_appearance_')) return $get?AdminPermissionCatalog::APPEARANCE_VIEW:AdminPermissionCatalog::APPEARANCE_MANAGE;
        if (str_starts_with($route,'admin_media_')) return $get?AdminPermissionCatalog::MEDIA_VIEW:(str_contains($route,'delete')?AdminPermissionCatalog::MEDIA_DELETE:AdminPermissionCatalog::MEDIA_MANAGE);
        if (str_starts_with($route,'admin_forum')) return $get?AdminPermissionCatalog::FORUM_VIEW:AdminPermissionCatalog::FORUM_MANAGE;
        if (in_array($route,['admin_commerce_promotions','admin_commerce_campaigns','admin_commerce_marketing_automation'],true)||str_contains($route,'promotion_toggle')) return $get?AdminPermissionCatalog::MARKETING_VIEW:AdminPermissionCatalog::MARKETING_MANAGE;
        if (str_starts_with($route,'admin_commerce_feed')) return $get?AdminPermissionCatalog::FEEDS_VIEW:AdminPermissionCatalog::FEEDS_MANAGE;
        if (in_array($route,['admin_commerce_notifications','admin_commerce_notification_templates','admin_commerce_notification_templates_save','admin_email_preview','admin_email_preview_render'],true)) return $get?AdminPermissionCatalog::NOTIFICATIONS_VIEW:AdminPermissionCatalog::NOTIFICATIONS_MANAGE;
        if ($route==='admin_commerce_inquiries') return $get?AdminPermissionCatalog::CUSTOMERS_VIEW:AdminPermissionCatalog::CUSTOMERS_MANAGE;
        if ($route==='admin_commerce_import_export') return $get?AdminPermissionCatalog::CATALOG_VIEW:AdminPermissionCatalog::CATALOG_MANAGE;
        if (str_starts_with($route,'admin_nova_post_')) return $get?AdminPermissionCatalog::ORDERS_VIEW:AdminPermissionCatalog::ORDERS_MANAGE;
        if ($route==='admin_system_captcha'||$route==='admin_system_tracking') return AdminPermissionCatalog::SYSTEM_SETTINGS;
        if (str_starts_with($route,'admin_system_seo_')) return $get?AdminPermissionCatalog::SYSTEM_SETTINGS:AdminPermissionCatalog::SYSTEM_SETTINGS;
        if (str_contains($route,'extension')) return AdminPermissionCatalog::EXTENSIONS_MANAGE;
        if (str_contains($route,'recovery')) return AdminPermissionCatalog::SYSTEM_RECOVERY;
        if (str_contains($route,'update')) return AdminPermissionCatalog::SYSTEM_UPDATE;
        if ($route==='admin_system_cron') return $get?AdminPermissionCatalog::SYSTEM_CRON_VIEW:AdminPermissionCatalog::SYSTEM_CRON_MANAGE;
        if (str_starts_with($route,'admin_system_integrations')) return AdminPermissionCatalog::INTEGRATIONS_MANAGE;
        if (in_array($route,['admin_system_site','admin_system_site_rollback','admin_system_store','admin_system_store_rollback','admin_system_localization','admin_system_localization_locales','admin_system_localization_currencies','admin_system_localization_rate','admin_system_localization_refresh'],true)) return AdminPermissionCatalog::SYSTEM_SETTINGS;
        if (in_array($route,['admin_system_stability','admin_system_components','admin_api_system_health'],true)) return AdminPermissionCatalog::SYSTEM_SETTINGS;
        if (str_starts_with($route,'admin_system_migration')) return AdminPermissionCatalog::SYSTEM_UPDATE;
        if ($route==='admin_ai_product_draft') return AdminPermissionCatalog::CATALOG_MANAGE;
        return null;
    }

    private function isGlobalPermission(string $permission): bool
    {
        return in_array($permission,[AdminPermissionCatalog::EXTENSIONS_MANAGE,AdminPermissionCatalog::SYSTEM_RECOVERY,AdminPermissionCatalog::SYSTEM_UPDATE,AdminPermissionCatalog::ADMIN_USERS_MANAGE,AdminPermissionCatalog::SYSTEM_AUDIT_VIEW],true);
    }
}
