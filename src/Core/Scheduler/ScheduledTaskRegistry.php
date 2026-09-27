<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

final class ScheduledTaskRegistry
{
    /** @return array<string,array{label:string,command:string,args:array<string,string|bool|int>,interval:int,group:string,description:string}> */
    public function all(): array
    {
        return [
            'events' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.podii_domenu'),'command'=>'commerce:events:work','args'=>['--limit'=>200],'interval'=>300,'group'=>'critical','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.dostavliaie_tranzaktsiini_podii_moduliv')],
            'notifications' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.notifications'),'command'=>'commerce:notifications:work','args'=>['--limit'=>200],'interval'=>300,'group'=>'critical','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.obrobliaie_cherhu_povidomlen_bez_blokuvannia_checkou')],
            'integrations' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.integrations'),'command'=>'commerce:integration:work','args'=>['--limit'=>100],'interval'=>300,'group'=>'integration','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.asynkhronna_synkhronizatsiia_merchant_ta_marketing_e')],
            'webhooks' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.webhooks'),'command'=>'commerce:webhooks:work','args'=>['--limit'=>100],'interval'=>300,'group'=>'integration','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.webhooks')],
            'unpaid_orders' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.neoplacheni_zamovlennia'),'command'=>'commerce:orders:expire-unpaid','args'=>['--limit'=>200],'interval'=>300,'group'=>'critical','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.zvilniaie_prostrocheni_rezervy_skladu')],
            'campaigns' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.rozsylky'),'command'=>'commerce:campaigns:enqueue','args'=>['--batch'=>250,'--max-batches'=>20],'interval'=>600,'group'=>'marketing','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.portsiiamy_perenosyt_oderzhuvachiv_kampanii_u_durabl')],
            'marketing_automations' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.marketynhovi_stsenarii'),'command'=>'commerce:marketing:automations','args'=>['--limit'=>200],'interval'=>1800,'group'=>'marketing','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.shukaie_pokynuti_koshyky_post_purchase_i_win_back_po')],
            'feeds' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.torhovi_fidy'),'command'=>'commerce:feeds:generate','args'=>['--platform'=>'all'],'interval'=>1800,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.onovliuie_atomarni_snapshots_fidiv_poza_http_zapytom')],
            'facets' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.kesh_filtriv'),'command'=>'commerce:storefront:warm-facets','args'=>['--categories'=>40,'--ttl'=>600],'interval'=>3600,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.pidihrivaie_populiarni_facets_sql_fallback_zberihaie')],
            'currency_prices' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.currency_prices'),'command'=>'commerce:currency:sync','args'=>['--fetch'=>true],'interval'=>3600,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.currency_prices')],
            'queue_purge' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.ochyshchennia_cherh'),'command'=>'commerce:queues:purge','args'=>['--days'=>30,'--limit'=>5000],'interval'=>86400,'group'=>'maintenance','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.vydaliaie_lyshe_stari_uspishno_zaversheni_zapysy')],
        ];
    }
}
