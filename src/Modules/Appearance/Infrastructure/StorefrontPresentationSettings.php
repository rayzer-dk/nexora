<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

use Commerce\Modules\Appearance\Application\StorefrontPresentationWriterInterface;
use Commerce\Core\Configuration\ConfigurationRevisionStore;

final readonly class StorefrontPresentationSettings implements StorefrontPresentationWriterInterface
{
    public function __construct(
        private ConfigurationRevisionStore $revisions,
        private ThemePresetCatalog $presets,
    )
    {
    }

    /** @return array<string,mixed> */
    public function get(int $storeId): array
    {
        $defaults = self::defaults();

        $revision = $this->revisions->latestValidPayload($storeId, 'appearance', 'storefront_presentation');
        return is_array($revision)
            ? $this->normalize(array_replace_recursive($defaults, $revision))
            : $defaults;
    }

    /** @param array<string,mixed> $settings */
    public function save(int $storeId, array $settings, ?string $actorSubject = null): int
    {
        $clean = $this->normalize(array_replace_recursive(self::defaults(), $settings));
        return $this->revisions->activateStoreJson($storeId, 'appearance', 'storefront_presentation', $clean, $actorSubject);
    }

    /** @return list<array{id:int,public_id:string,revision_number:int,status:string,actor_subject:?string,created_at:string,activated_at:?string}> */
    public function history(int $storeId, int $limit = 20): array
    {
        return $this->revisions->history($storeId, 'appearance', 'storefront_presentation', $limit);
    }

    public function rollback(int $storeId, int $revisionId, ?string $actorSubject = null): int
    {
        return $this->revisions->rollback(
            $storeId,
            $revisionId,
            'appearance',
            'storefront_presentation',
            $actorSubject,
            fn (array $payload): array => $this->normalize(array_replace_recursive(self::defaults(), $payload)),
        );
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'utility' => [
                'location' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.ukraina_kyiv'),
                'delivery' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.bezkoshtovna_dostavka_vid_2000_hrn'),
                'support' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.pidtrymka_24_7'),
            ],
            'brand' => [
                'title' => '',
                'subtitle' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.suchasni_rishennia_dlia_krashchoho_zhyttia'),
                'logo' => '',
                'icon' => '',
                'favicon' => '',
                'pwa' => '1',
            ],
            'theme' => [
                'preset' => 'modern',
                'primary' => '#0B63F6',
                'accent' => '#FF7A1A',
                'success' => '#0F7A4B',
                'surface' => '#FFFFFF',
                'radius' => '18',
                'shadow' => 'medium',
                'density' => 'comfortable',
                'color_scheme' => 'light',
                'toggle' => '1',
                'container' => '1408',
                'font' => 'system',
            ],
            'display' => [
                'card_style' => 'classic',
                'card_columns' => '4',
                'card_ratio' => 'square',
                'card_actions' => 'visible',
                'category_style' => 'classic',
                'product_layout' => 'classic',
                'product_details' => 'tabs',
                'sticky_header' => false,
                'back_to_top' => true,
                'sale_timer' => true,
                'quick_order' => false,
                'benefits' => '',
            ],
            'header' => [
                'search_placeholder' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.poshuk_tovariv_katehorii_brendiv'),
                'show_category_nav' => true,
            ],
            'home' => [
                'show_benefits' => true,
                'show_categories' => true,
                'show_products' => true,
                'show_promos' => true,
                'show_articles' => true,
            ],
            // A new store ships without promotional artwork; hero and banners appear once they are configured in Appearance.
            'hero' => [
                'eyebrow' => '',
                'title' => '',
                'subtitle' => '',
                'text' => '',
                'image' => '',
                'button_label' => '',
                'button_url' => '/catalog',
            ],
            'promo_left' => ['title' => '', 'text' => '', 'image' => '', 'url' => '/catalog'],
            'promo_right' => ['title' => '', 'text' => '', 'image' => '', 'url' => '/catalog'],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $defaults = self::defaults();
        $out = $defaults;
        foreach (['location','delivery','support'] as $key) {
            $out['utility'][$key] = $this->text($input['utility'][$key] ?? '', 120);
        }
        foreach (['title','subtitle'] as $key) {
            $out['brand'][$key] = $this->text($input['brand'][$key] ?? '', 140);
        }
        foreach (['logo','icon','favicon'] as $key) {
            $out['brand'][$key] = $this->mediaPath($input['brand'][$key] ?? '');
        }
        $out['brand']['pwa'] = (string) ($input['brand']['pwa'] ?? $defaults['brand']['pwa']) === '1' ? '1' : '0';
        $preset=(string)($input['theme']['preset']??$defaults['theme']['preset']);
        if(!in_array($preset,$this->presets->codes(),true)){$preset=$defaults['theme']['preset'];}
        $out['theme']['preset']=$preset;
        foreach (['primary','accent','success','surface'] as $key) {
            $out['theme'][$key] = $this->hex($input['theme'][$key] ?? $defaults['theme'][$key], $defaults['theme'][$key]);
        }
        $radius=(int)($input['theme']['radius']??$defaults['theme']['radius']); $out['theme']['radius']=(string)max(4,min(32,$radius));
        $shadow=(string)($input['theme']['shadow']??$defaults['theme']['shadow']); $out['theme']['shadow']=in_array($shadow,['none','soft','medium','strong'],true)?$shadow:$defaults['theme']['shadow'];
        $density=(string)($input['theme']['density']??$defaults['theme']['density']); $out['theme']['density']=in_array($density,['compact','comfortable','spacious'],true)?$density:$defaults['theme']['density'];
        $scheme=(string)($input['theme']['color_scheme']??$defaults['theme']['color_scheme']); $out['theme']['color_scheme']=in_array($scheme,['light','auto','dark'],true)?$scheme:$defaults['theme']['color_scheme'];$out['theme']['toggle']=(string)($input['theme']['toggle']??$defaults['theme']['toggle'])==='0'?'0':'1';
        $container=(int)($input['theme']['container']??$defaults['theme']['container']); $out['theme']['container']=(string)max(960,min(1680,$container));
        $font=(string)($input['theme']['font']??$defaults['theme']['font']); $out['theme']['font']=in_array($font,['system','inter','manrope'],true)?$font:$defaults['theme']['font'];
        $choice = static fn (mixed $v, array $allowed, string $fallback): string => in_array((string) $v, $allowed, true) ? (string) $v : $fallback;
        $d = $defaults['display'];
        $in = is_array($input['display'] ?? null) ? $input['display'] : [];
        $out['display']['card_style'] = $choice($in['card_style'] ?? $d['card_style'], ['classic', 'minimal', 'outlined', 'overlay', 'list'], $d['card_style']);
        $out['display']['card_columns'] = $choice($in['card_columns'] ?? $d['card_columns'], ['3', '4', '5'], $d['card_columns']);
        $out['display']['card_ratio'] = $choice($in['card_ratio'] ?? $d['card_ratio'], ['square', 'portrait', 'landscape'], $d['card_ratio']);
        $out['display']['card_actions'] = $choice($in['card_actions'] ?? $d['card_actions'], ['visible', 'hover'], $d['card_actions']);
        $out['display']['category_style'] = $choice($in['category_style'] ?? $d['category_style'], ['classic', 'overlay', 'chips'], $d['category_style']);
        $out['display']['product_details'] = $choice($in['product_details'] ?? $d['product_details'], ['tabs', 'list'], $d['product_details']);
        $out['display']['product_layout'] = $choice($in['product_layout'] ?? $d['product_layout'], ['classic', 'wide', 'stacked', 'split'], $d['product_layout']);
        foreach (['sticky_header', 'back_to_top', 'sale_timer', 'quick_order'] as $flag) {
            $out['display'][$flag] = (bool) ($in[$flag] ?? $d[$flag]);
        }
        $lines = array_slice(array_values(array_filter(array_map(fn (string $l): string => $this->text($l, 90), preg_split('/\R/u', (string) ($in['benefits'] ?? '')) ?: []), static fn (string $l): bool => $l !== '')), 0, 4);
        $out['display']['benefits'] = implode("\n", $lines);
        $out['header']['search_placeholder'] = $this->text($input['header']['search_placeholder'] ?? '', 160);
        $out['header']['show_category_nav'] = (bool) ($input['header']['show_category_nav'] ?? false);
        foreach (array_keys($defaults['home']) as $key) {
            $out['home'][$key] = (bool) ($input['home'][$key] ?? false);
        }
        foreach (['eyebrow','title','subtitle','text','button_label'] as $key) {
            $out['hero'][$key] = $this->text($input['hero'][$key] ?? '', $key === 'text' ? 420 : 180);
        }
        $out['hero']['image'] = $this->mediaPath($input['hero']['image'] ?? '');
        $out['hero']['button_url'] = $this->url($input['hero']['button_url'] ?? '/catalog');
        foreach (['promo_left','promo_right'] as $slot) {
            foreach (['title','text'] as $key) {
                $out[$slot][$key] = $this->text($input[$slot][$key] ?? '', $key === 'text' ? 240 : 180);
            }
            $out[$slot]['image'] = $this->mediaPath($input[$slot]['image'] ?? '');
            $out[$slot]['url'] = $this->url($input[$slot]['url'] ?? '/catalog');
        }
        return $out;
    }

    private function hex(mixed $value, string $fallback): string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^#[0-9A-F]{6}$/D', $value) === 1 ? $value : $fallback;
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim(strip_tags((string) $value));
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    private function mediaPath(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_contains($value, '..') || !preg_match('#^/(?:media|assets)/[A-Za-z0-9_./-]+$#', $value)) {
            return '';
        }
        return $value;
    }

    private function url(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
            return '/catalog';
        }
        if (str_starts_with($value, '/')) {
            return preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $value) ? $value : '/catalog';
        }
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '/catalog';
    }
}