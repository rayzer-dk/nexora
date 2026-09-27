<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppearanceAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly StorefrontPresentationSettings $settings)
    {
    }

    #[Route('/admin/appearance/storefront', name: 'admin_appearance_storefront', methods: ['GET','POST'])]
    public function __invoke(Request $request, Connection $connection): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('storefront_presentation', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.sesiia_formy_zavershylas_onovit_storinku_ta_povtorit'));
                return $this->redirectToRoute('admin_appearance_storefront');
            }
            $bool = static fn (string $key): bool => $request->request->has($key);
            $defaultLocale = (string) $connection->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$context->storeId]);
            try {
                $posted = [
                    'utility' => [
                        'location' => $request->request->get('utility_location',''),
                        'delivery' => $request->request->get('utility_delivery',''),
                        'support' => $request->request->get('utility_support',''),
                    ],
                    'brand' => [
                        'title' => $request->request->get('brand_title',''),
                        'subtitle' => $request->request->get('brand_subtitle',''),
                        'logo' => $request->request->get('brand_logo',''),
                        'icon' => $request->request->get('brand_icon',''),
                        'favicon' => $request->request->get('brand_favicon',''),
                    ],
                    'theme' => [
                        'preset' => $request->request->get('theme_preset','modern'),
                        'primary' => $request->request->get('theme_primary','#0B63F6'),
                        'accent' => $request->request->get('theme_accent','#FF7A1A'),
                        'success' => $request->request->get('theme_success','#16A364'),
                        'surface' => $request->request->get('theme_surface','#FFFFFF'),
                        'radius' => $request->request->get('theme_radius','18'),
                        'shadow' => $request->request->get('theme_shadow','medium'),
                        'density' => $request->request->get('theme_density','comfortable'),
                        'container' => $request->request->get('theme_container','1408'),
                        'font' => $request->request->get('theme_font','system'),
                    ],
                    'header' => [
                        'search_placeholder' => $request->request->get('search_placeholder',''),
                        'show_category_nav' => $bool('show_category_nav'),
                    ],
                    'home' => [
                        'show_benefits' => $bool('show_benefits'),
                        'show_categories' => $bool('show_categories'),
                        'show_products' => $bool('show_products'),
                        'show_promos' => $bool('show_promos'),
                        'show_articles' => $bool('show_articles'),
                    ],
                    'hero' => [
                        'eyebrow' => $request->request->get('hero_eyebrow',''),
                        'title' => $request->request->get('hero_title',''),
                        'subtitle' => $request->request->get('hero_subtitle',''),
                        'text' => $request->request->get('hero_text',''),
                        'image' => $request->request->get('hero_image',''),
                        'button_label' => $request->request->get('hero_button_label',''),
                        'button_url' => $request->request->get('hero_button_url',''),
                    ],
                    'promo_left' => [
                        'title' => $request->request->get('promo_left_title',''),
                        'text' => $request->request->get('promo_left_text',''),
                        'image' => $request->request->get('promo_left_image',''),
                        'url' => $request->request->get('promo_left_url',''),
                    ],
                    'promo_right' => [
                        'title' => $request->request->get('promo_right_title',''),
                        'text' => $request->request->get('promo_right_text',''),
                        'image' => $request->request->get('promo_right_image',''),
                        'url' => $request->request->get('promo_right_url',''),
                    ],
                ];
                // Texts entered while the admin content language is not the default are stored as that language's
                // translation; colours, images and switches are shared by all languages.
                $context->locale !== $defaultLocale && $defaultLocale !== ''
                    ? $this->settings->saveTranslation($context->storeId, $context->locale, $posted, $this->actor())
                    : $this->settings->save($context->storeId, $posted, $this->actor());
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.oformlennia_vytryny_zberezheno_poperednia_versiia_do'));
            } catch (\Throwable $e) {
                $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.zminy_ne_zastosovano_poperednie_oformlennia_zalyshyl') . mb_substr($message, 0, 350, 'UTF-8'));
            }
            return $this->redirectToRoute('admin_appearance_storefront');
        }
        $store = $connection->fetchAssociative('SELECT name,default_locale FROM mc_store WHERE id=?', [$context->storeId]) ?: [];
        $translating = ($store['default_locale'] ?? $context->locale) !== $context->locale;
        return $this->render('@storefront/admin/appearance/storefront.html.twig', [
            'settings' => $this->settings->get($context->storeId, $translating ? $context->locale : null),
            'translating_locale' => $translating ? $context->locale : null,
            'store' => $store,
            'platform_version' => PlatformVersion::VERSION,
            'revisions' => $this->settings->history($context->storeId, 12),
        ]);
    }

    #[Route('/admin/appearance/storefront/rollback/{revisionId}', name: 'admin_appearance_storefront_rollback', methods: ['POST'], requirements: ['revisionId' => '\d+'])]
    public function rollback(Request $request, int $revisionId): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('storefront_presentation_rollback', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_appearance_storefront');
        }
        try {
            $this->settings->rollback($context->storeId, $revisionId, $this->actor());
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.poperednie_oformlennia_vidnovleno_iak_novu_aktyvnu_r'));
        } catch (\Throwable $e) {
            $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . mb_substr($message, 0, 400, 'UTF-8'));
        }
        return $this->redirectToRoute('admin_appearance_storefront');
    }

    private function actor(): string
    {
        $user = $this->getUser();
        return $user instanceof AdminUser ? 'admin:' . $user->id : 'admin';
    }
}
