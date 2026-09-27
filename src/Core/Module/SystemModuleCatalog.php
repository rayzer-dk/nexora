<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

final class SystemModuleCatalog
{
    /** @return array<string,SystemModuleDefinition> */
    public static function all(): array
    {
        $system = static fn (string $code, string $name, array $deps = [], bool $enabled = true, ModuleMaturity $maturity = ModuleMaturity::Stable): SystemModuleDefinition => new SystemModuleDefinition(
            code: $code,
            name: $name,
            tier: ModuleTier::System,
            removalPolicy: ModuleRemovalPolicy::Protected,
            dependencies: $deps,
            enabledByDefault: $enabled,
            maturity: $maturity,
        );

        $definitions = [
            $system('measurement', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.odynytsi_ta_vymiriuvannia')),
            $system('catalog', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.kataloh'), ['measurement']),
            $system('pricing', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.tsinoutvorennia'), ['catalog']),
            $system('inventory', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.zapasy'), ['catalog']),
            $system('customer', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.kliienty')),
            $system('cart', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.koshyk'), ['catalog', 'pricing', 'inventory']),
            $system('shipping', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.dostavka')),
            $system('payment', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.oplata')),
            $system('tax', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.podatky')),
            $system('privacy', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.konfidentsiinist_i_zhoda')),
            $system('consumer_rights', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.prava_pokuptsiv'), ['catalog', 'pricing']),
            $system('compliance', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.vidpovidnist_tovariv'), ['catalog', 'media']),
            $system('accessibility', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.dostupnist')),
            $system('checkout', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.oformlennia_zamovlennia'), ['cart', 'customer', 'shipping', 'payment', 'tax', 'consumer_rights']),
            $system('order', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.zamovlennia'), ['checkout']),
            $system('order_documents', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.dokumenty_zamovlennia'), ['order', 'payment']),
            $system('returns', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.povernennia'), ['order'], true, ModuleMaturity::Beta),

            $system('promotion', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.aktsii'), ['catalog', 'pricing']),
            $system('coupons', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.promokody'), ['promotion']),
            $system('gift_cards', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.podarunkovi_kartky'), ['pricing'], true, ModuleMaturity::Beta),
            $system('loyalty', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.loialnist'), ['customer', 'order'], true, ModuleMaturity::Beta),
            $system('reviews', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vidhuky'), ['catalog', 'customer']),
            $system('qa', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.pytannia_pro_tovar'), ['catalog', 'customer']),
            $system('wishlist', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.obrane'), ['catalog', 'customer']),
            $system('compare', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.porivniannia'), ['catalog'], true, ModuleMaturity::Beta),
            $system('saved_carts', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.zberezheni_koshyky'), ['cart', 'customer'], true, ModuleMaturity::Beta),
            $system('b2b', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.b2b_torhivlia'), ['catalog', 'pricing', 'customer'], true, ModuleMaturity::Beta),
            $system('localization', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.movy_ta_valiuty')),
            $system('markets', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.rynky_ta_multy_mahazyn'), ['catalog', 'pricing', 'localization']),

            $system('media', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.producteditorschema.media')),
            $system('search', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.poshuk'), ['catalog']),
            $system('seo', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.seo_ta_strukturovani_dani'), ['catalog']),
            $system('redirects', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.redyrekty'), ['seo']),
            $system('cms', 'CMS'),
            $system('blog', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.content.http.blogcontroller.bloh'), ['cms']),
            $system('forum', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.forum'), ['cms', 'customer'], true, ModuleMaturity::Beta),
            $system('navigation', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.navihatsiia'), ['cms', 'catalog'], true, ModuleMaturity::Beta),
            $system('page_builder', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.konstruktor_storinok'), ['cms', 'appearance'], true, ModuleMaturity::Beta),
            $system('recommendations', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.rekomendatsii'), ['catalog'], true, ModuleMaturity::Beta),

            $system('google_commerce', 'Google Commerce', ['catalog', 'inventory', 'seo'], true, ModuleMaturity::Beta),
            $system('feeds', \Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.torhovi_fidy'), ['catalog', 'inventory']),
            $system('marketing_event_layer', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.marketynhovi_podii'), ['privacy']),
            $system('analytics', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.analityka_torhivli'), ['order', 'marketing_event_layer', 'privacy'], true, ModuleMaturity::Stable),
            $system('attribution', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.atrybutsiia'), ['analytics'], true, ModuleMaturity::Beta),
            $system('abandoned_cart', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.pokynuti_koshyky'), ['cart', 'customer'], true, ModuleMaturity::Beta),
            $system('campaigns', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.kampanii'), ['customer', 'notification']),

            $system('identity', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.identyfikatsiia_ta_vkhid')),
            $system('notification', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.spovishchennia')),
            $system('appearance', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.oformlennia_ta_temy')),
            $system('api', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.api_ta_vebkhuky'), [], true, ModuleMaturity::Beta),
            $system('queue', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.cherhy_ta_planuvalnyk')),
            $system('import_export', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.import_ta_eksport')),
            $system('ai_authoring', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.ai_pomichnyk_kontentu'), ['catalog'], false),
            $system('migration_center', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.tsentr_mihratsii'), ['import_export', 'catalog', 'localization'], true, ModuleMaturity::Beta),
            $system('backup', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.rezervni_kopii_ta_vidnovlennia')),
            $system('update', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.onovlennia')),
            $system('health', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.stan_systemy')),
            $system('security', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.bezpeka_ta_audyt')),
            $system('logs', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.zhurnaly')),
            $system('developer_tools', \Commerce\Core\I18n\CanonicalUiText::get('php.core.module.systemmodulecatalog.instrumenty_rozrobnyka'), [], true, ModuleMaturity::Beta),
        ];

        $result = [];
        foreach ($definitions as $definition) {
            $result[$definition->code] = $definition;
        }
        return $result;
    }
}
