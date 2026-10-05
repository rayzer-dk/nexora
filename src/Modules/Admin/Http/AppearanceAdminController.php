<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Commerce\Modules\Appearance\Infrastructure\ThemePresetCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppearanceAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly StorefrontPresentationSettings $settings,
        private readonly ThemePresetCatalog $presets,
    ) {
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
            // A text field is sent once per store language (name[locale]); an old form sends a plain string.
            $tx = static fn (string $key): string|array => is_array($request->request->all()[$key] ?? null) ? $request->request->all($key) : (string) $request->request->get($key, '');
            try {
                $this->settings->save($context->storeId, [
                    'utility' => [
                        'location' => $tx('utility_location'),
                        'delivery' => $tx('utility_delivery'),
                        'support' => $tx('utility_support'),
                    ],
                    'brand' => [
                        'title' => $tx('brand_title'),
                        'subtitle' => $tx('brand_subtitle'),
                        'logo' => $request->request->get('brand_logo',''),
                        'icon' => $request->request->get('brand_icon',''),
                        'favicon' => $request->request->get('brand_favicon',''),
                        'pwa' => $request->request->get('brand_pwa','0') === '1' ? '1' : '0',
                    ],
                    'theme' => [
                        'preset' => $request->request->get('theme_preset','modern'),
                        'primary' => $request->request->get('theme_primary','#0B63F6'),
                        'accent' => $request->request->get('theme_accent','#FF7A1A'),
                        'success' => $request->request->get('theme_success','#0F7A4B'),
                        'surface' => $request->request->get('theme_surface','#FFFFFF'),
                        'radius' => $request->request->get('theme_radius','18'),
                        'shadow' => $request->request->get('theme_shadow','medium'),
                        'density' => $request->request->get('theme_density','comfortable'),
                        'color_scheme' => $request->request->get('theme_color_scheme','light'),
                        'toggle' => $request->request->getBoolean('theme_toggle') ? '1' : '0',
                        'container' => $request->request->get('theme_container','1600'),
                        'font' => $request->request->get('theme_font','system'),
                    ],
                    'display' => [
                        'card_style' => $request->request->get('display_card_style','classic'),
                        'card_columns' => $request->request->get('display_card_columns','4'),
                        'card_ratio' => $request->request->get('display_card_ratio','square'),
                        'card_actions' => $request->request->get('display_card_actions','visible'),
                        'category_style' => $request->request->get('display_category_style','classic'),
                        'product_layout' => $request->request->get('display_product_layout','classic'),
                        'product_details' => $request->request->get('display_product_details','tabs'),
                        'sticky_header' => $bool('display_sticky_header'),
                        'back_to_top' => $bool('display_back_to_top'),
                        'sale_timer' => $bool('display_sale_timer'),
                        'quick_order' => $bool('display_quick_order'),
                        'buy_now' => $bool('display_buy_now'),
                        'key_features_limit' => $request->request->get('display_key_features_limit','5'),
                        'benefits' => $request->request->get('display_benefits',''),
                    ],
                    'blog' => [
                        'index' => [
                            'show_intro' => $bool('blog_index_show_intro'),
                            'show_categories' => $bool('blog_index_show_categories'),
                            'show_search' => $bool('blog_index_show_search'),
                            'show_featured' => $bool('blog_index_show_featured'),
                            'show_tags' => $bool('blog_index_show_tags'),
                            'show_rss' => $bool('blog_index_show_rss'),
                            'layout' => $request->request->get('blog_index_layout', 'grid'),
                            'columns' => $request->request->get('blog_index_columns', '3'),
                        ],
                        'article' => [
                            'show_toc' => $bool('blog_article_show_toc'),
                            'show_author' => $bool('blog_article_show_author'),
                            'show_reading_time' => $bool('blog_article_show_reading_time'),
                            'show_tags' => $bool('blog_article_show_tags'),
                            'show_share' => $bool('blog_article_show_share'),
                            'show_products' => $bool('blog_article_show_products'),
                            'show_related' => $bool('blog_article_show_related'),
                            'show_neighbors' => $bool('blog_article_show_neighbors'),
                        ],
                    ],
                    'announcement' => [
                        'enabled' => $bool('announcement_enabled'),
                        'text' => $tx('announcement_text'),
                        'link_label' => $tx('announcement_link_label'),
                        'link_url' => $request->request->get('announcement_link_url', ''),
                        'mode' => $request->request->get('announcement_mode', 'marquee_mobile'),
                        'bg' => $request->request->getBoolean('announcement_bg_on') ? (string) $request->request->get('announcement_bg', '') : '',
                        'fg' => $request->request->getBoolean('announcement_fg_on') ? (string) $request->request->get('announcement_fg', '') : '',
                        'devices' => ['desktop' => $bool('announcement_dev_desktop'), 'tablet' => $bool('announcement_dev_tablet'), 'mobile' => $bool('announcement_dev_mobile')],
                        'items' => $this->announcementItems($request),
                        'pages' => array_combine(
                            ['home', 'catalog', 'category', 'product', 'cart', 'blog', 'content'],
                            array_map(static fn (string $page): bool => $request->request->getBoolean('announcement_page_' . $page), ['home', 'catalog', 'category', 'product', 'cart', 'blog', 'content']),
                        ),
                    ],
                    'colors' => array_combine(
                        ['background', 'text', 'heading', 'header_bg', 'footer_bg', 'primary_hover', 'primary_active', 'buy_button', 'buy_hover', 'buy_active'],
                        array_map(
                            static fn (string $key): string => $request->request->getBoolean('colors_' . $key . '_on') ? (string) $request->request->get('colors_' . $key, '') : '',
                            ['background', 'text', 'heading', 'header_bg', 'footer_bg', 'primary_hover', 'primary_active', 'buy_button', 'buy_hover', 'buy_active'],
                        ),
                    ),
                    'consent' => [
                        'title' => $tx('consent_title'),
                        'text' => $tx('consent_text'),
                        'position' => $request->request->get('consent_position','bar'),
                        'tone' => $request->request->get('consent_tone','light'),
                        'show_icon' => $bool('consent_show_icon'),
                    ],
                    'footer' => $this->footerSettings($request, $bool),
                    'header' => [
                        'search_placeholder' => $tx('search_placeholder'),
                        'show_category_nav' => $bool('show_category_nav'),
                        'show_topbar' => $bool('show_topbar'),
                    ],
                    'benefits' => [
                        'items' => $this->benefitItems($request),
                        'devices' => ['desktop' => $bool('benefits_desktop'), 'tablet' => $bool('benefits_tablet'), 'mobile' => $bool('benefits_mobile')],
                        'mobile_limit' => $request->request->get('benefits_mobile_limit', '0'),
                    ],
                    'home' => [
                        'show_benefits' => $bool('show_benefits'),
                        'show_categories' => $bool('show_categories'),
                        'show_products' => $bool('show_products'),
                        'show_promos' => $bool('show_promos'),
                        'show_articles' => $bool('show_articles'),
                    ],
                    'hero' => [
                        'eyebrow' => $tx('hero_eyebrow'),
                        'title' => $tx('hero_title'),
                        'subtitle' => $tx('hero_subtitle'),
                        'text' => $tx('hero_text'),
                        'image' => $request->request->get('hero_image',''),
                        'button_label' => $tx('hero_button_label'),
                        'button_url' => $request->request->get('hero_button_url',''),
                    ],
                    'promo_left' => [
                        'title' => $tx('promo_left_title'),
                        'text' => $tx('promo_left_text'),
                        'image' => $request->request->get('promo_left_image',''),
                        'url' => $request->request->get('promo_left_url',''),
                    ],
                    'promo_right' => [
                        'title' => $tx('promo_right_title'),
                        'text' => $tx('promo_right_text'),
                        'image' => $request->request->get('promo_right_image',''),
                        'url' => $request->request->get('promo_right_url',''),
                    ],
                ], $this->actor());
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.oformlennia_vytryny_zberezheno_poperednia_versiia_do'));
            } catch (\Throwable $e) {
                $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.zminy_ne_zastosovano_poperednie_oformlennia_zalyshyl') . mb_substr($message, 0, 350, 'UTF-8'));
            }
            return $this->redirectToRoute('admin_appearance_storefront');
        }
        $store = $connection->fetchAssociative('SELECT name FROM mc_store WHERE id=?', [$context->storeId]) ?: [];
        return $this->render('@storefront/admin/appearance/storefront.html.twig', [
            'settings' => $this->settings->get($context->storeId),
            'raw' => $this->settings->getRaw($context->storeId),
            'raw_benefits' => $this->settings->getRaw($context->storeId)['benefits'] ?? [],
            'benefit_icons' => \Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings::BENEFIT_ICONS,
            'raw_footer' => $this->settings->getRaw($context->storeId)['footer'] ?? [],
            'footer_links' => \Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings::FOOTER_LINKS,
            'store_locales' => $connection->fetchAllAssociative('SELECT sl.locale_code code,COALESCE(l.native_name,sl.locale_code) native_name FROM mc_store_locale sl LEFT JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.locale_code', [$context->storeId]),
            'store' => $store,
            'platform_version' => PlatformVersion::VERSION,
            'revisions' => $this->settings->history($context->storeId, 12),
            'theme_presets' => $this->presets->all(),
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

    /**
     * Further announcement messages from the form: a text and a link label per language and one address.
     *
     * @return list<array{text:array<string,string>,link_label:array<string,string>,link_url:string}>
     */
    private function announcementItems(Request $request): array
    {
        $texts = $request->request->all('ann_item_text');
        $labels = $request->request->all('ann_item_label');
        $urls = $request->request->all('ann_item_url');
        $items = [];
        foreach ($texts as $index => $text) {
            $items[] = ['text' => is_array($text) ? $text : [], 'link_label' => is_array($labels[$index] ?? null) ? $labels[$index] : [], 'link_url' => (string) ($urls[$index] ?? '')];
        }

        return $items;
    }

    /**
     * Footer from the form: texts per language, colours, which built-in links stay and up to three own columns.
     *
     * @param callable(string):bool $bool
     * @return array<string,mixed>
     */
    private function footerSettings(Request $request, callable $bool): array
    {
        $r = $request->request;
        $map = static fn (string $key): array => is_array($r->all($key)) ? $r->all($key) : [];
        $colour = static fn (string $key): string => $r->getBoolean($key . '_on') ? (string) $r->get($key, '') : '';
        $hidden = [];
        foreach (array_keys(\Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings::FOOTER_LINKS) as $key) {
            if (!$r->getBoolean('footer_link_' . $key)) {
                $hidden[] = $key;
            }
        }
        $columns = [];
        foreach ($map('footer_col_title') as $c => $title) {
            $links = [];
            $labels = $r->all('footer_link_label')[$c] ?? [];
            $urls = $r->all('footer_link_url')[$c] ?? [];
            $tabs = $r->all('footer_link_tab')[$c] ?? [];
            foreach (is_array($urls) ? $urls : [] as $j => $url) {
                $links[] = ['label' => is_array($labels[$j] ?? null) ? $labels[$j] : [], 'url' => (string) $url, 'new_tab' => !empty($tabs[$j])];
            }
            $columns[] = ['title' => is_array($title) ? $title : [], 'links' => $links];
        }

        return [
            'tagline' => $map('footer_tagline'),
            'copyright' => $map('footer_copyright'),
            'bg' => $colour('footer_bg'),
            'text_color' => $colour('footer_text_color'),
            'newsletter' => [
                'enabled' => $bool('footer_nl_enabled'),
                'icon' => (string) $r->get('footer_nl_icon', 'mail'),
                'eyebrow' => $map('footer_nl_eyebrow'),
                'title' => $map('footer_nl_title'),
                'text' => $map('footer_nl_text'),
                'button' => $map('footer_nl_button'),
                'placeholder' => $map('footer_nl_placeholder'),
                'bg' => $colour('footer_nl_bg'),
                'text_color' => $colour('footer_nl_text_color'),
            ],
            'hidden_links' => $hidden,
            'columns' => $columns,
        ];
    }

    /**
     * Benefit cards from the form: icon, and a title and text for every language of the store (empty titles are dropped by the settings).
     *
     * @return list<array{icon:string,title:array<string,string>,text:array<string,string>}>
     */
    private function benefitItems(Request $request): array
    {
        $icons = $request->request->all('benefit_icon');
        $titles = $request->request->all('benefit_title');
        $texts = $request->request->all('benefit_text');
        $items = [];
        foreach ($icons as $index => $icon) {
            $items[] = [
                'icon' => (string) $icon,
                'title' => is_array($titles[$index] ?? null) ? $titles[$index] : [],
                'text' => is_array($texts[$index] ?? null) ? $texts[$index] : [],
            ];
        }

        return $items;
    }
}
