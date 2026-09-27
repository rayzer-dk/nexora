<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

use Commerce\Modules\Appearance\Application\StorefrontPresentationWriterInterface;
use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Doctrine\DBAL\Connection;

final readonly class StorefrontPresentationSettings implements StorefrontPresentationWriterInterface
{
    public function __construct(
        private Connection $connection,
        private ConfigurationRevisionStore $revisions,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(int $storeId): array
    {
        $defaults = self::defaults();

        // Revision storage is the canonical source. A damaged metadata cache must never break rendering.
        $revision = $this->revisions->latestValidPayload($storeId, 'appearance', 'storefront_presentation');
        if (is_array($revision)) {
            return $this->normalize(array_replace_recursive($defaults, $revision));
        }

        // Legacy fallback for installations created before configuration revisions existed.
        try {
            $publicId = $this->connection->fetchOne('SELECT public_id FROM mc_store WHERE id=?', [$storeId]);
            if (!is_string($publicId) || $publicId === '') {
                return $defaults;
            }
            $json = $this->connection->fetchOne(
                "SELECT value_json FROM mc_entity_metadata WHERE entity_type='store' AND entity_public_id=? AND namespace='appearance' AND meta_key='storefront_presentation' LIMIT 1",
                [$publicId],
            );
            if (!is_string($json) || trim($json) === '') {
                return $defaults;
            }
            $saved = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            return is_array($saved) ? $this->normalize(array_replace_recursive($defaults, $saved)) : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
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
                'title' => 'Modern Shop',
                'subtitle' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.suchasni_rishennia_dlia_krashchoho_zhyttia'),
                'logo' => '',
                'icon' => '',
                'favicon' => '',
            ],
            'theme' => [
                'preset' => 'modern',
                'primary' => '#0B63F6',
                'accent' => '#FF7A1A',
                'success' => '#16A364',
                'surface' => '#FFFFFF',
                'radius' => '18',
                'shadow' => 'medium',
                'density' => 'comfortable',
                'container' => '1408',
                'font' => 'system',
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
            'hero' => [
                'eyebrow' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.kava_shcho_nadykhaie'),
                'title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.kava_shcho_nadykhaie'),
                'subtitle' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.avtomatychni_kavomashyny_dlia_spravzhnikh_tsinyteliv'),
                'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.svizhozmelena_kava_idealnyi_smak_suchasnyi_dyzain_po'),
                'image' => '/media/demo/hero-coffee-reference.webp',
                'button_label' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.perehlianuty_kavomashyny'),
                'button_url' => '/home-appliances',
            ],
            'promo_left' => [
                'title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.novyi_smartfon_vzhe_v_naiavnosti'),
                'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.potuzhnist_krasa_intelekt'),
                'image' => '/media/demo/promo-smartphone-reference.webp',
                'url' => '/smartphones',
            ],
            'promo_right' => [
                'title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.krasa_u_kozhnii_detali'),
                'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.appearance.infrastructure.storefrontpresentationsettings.dohliad_iakyi_nadykhaie'),
                'image' => '/media/demo/promo-beauty-reference.webp',
                'url' => '/beauty-health',
            ],
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
        $preset=(string)($input['theme']['preset']??$defaults['theme']['preset']);
        if(!in_array($preset,['modern','marketplace','premium','minimal','soft'],true)){$preset=$defaults['theme']['preset'];}
        $out['theme']['preset']=$preset;
        foreach (['primary','accent','success','surface'] as $key) {
            $out['theme'][$key] = $this->hex($input['theme'][$key] ?? $defaults['theme'][$key], $defaults['theme'][$key]);
        }
        $radius=(int)($input['theme']['radius']??$defaults['theme']['radius']); $out['theme']['radius']=(string)max(4,min(32,$radius));
        $shadow=(string)($input['theme']['shadow']??$defaults['theme']['shadow']); $out['theme']['shadow']=in_array($shadow,['none','soft','medium','strong'],true)?$shadow:$defaults['theme']['shadow'];
        $density=(string)($input['theme']['density']??$defaults['theme']['density']); $out['theme']['density']=in_array($density,['compact','comfortable','spacious'],true)?$density:$defaults['theme']['density'];
        $container=(int)($input['theme']['container']??$defaults['theme']['container']); $out['theme']['container']=(string)max(960,min(1680,$container));
        $font=(string)($input['theme']['font']??$defaults['theme']['font']); $out['theme']['font']=in_array($font,['system','inter','humanist','rounded'],true)?$font:$defaults['theme']['font'];
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
