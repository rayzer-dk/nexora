<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Commerce\Core\Extension\TrustedExtensionRuntimeLoader;
use Commerce\Core\Extension\TrustedExtensionRuntimeRegistry;

final class ScheduledTaskRegistry
{
    public function __construct(private readonly TrustedExtensionRuntimeLoader $extensions, private readonly TrustedExtensionRuntimeRegistry $extensionTasks)
    {
    }

    /** @return array<string,array{label:string,command:string,args:array<string,string|bool|int>,interval:int,group:string,description:string,handler?:callable}> */
    public function all(): array
    {
        return $this->core() + $this->fromExtensions();
    }

    /** Tasks of active trusted extensions: they have a handler instead of a console command. */
    private function fromExtensions(): array
    {
        $this->extensions->bootActive();
        $tasks = [];
        foreach ($this->extensionTasks->tasks() as $code => $task) {
            $tasks[$code] = ['label' => $task['label'], 'command' => '', 'args' => [], 'interval' => $task['interval'], 'group' => 'extensions', 'description' => $task['description'], 'handler' => $task['handler']];
        }

        return $tasks;
    }

    /** @return array<string,array{label:string,command:string,args:array<string,string|bool|int>,interval:int,group:string,description:string}> */
    private function core(): array
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
            'search_index' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.search_index'),'command'=>'commerce:search:reindex','args'=>['--batch'=>500],'interval'=>21600,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.search_index')],
            'currency_prices' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.currency_prices'),'command'=>'commerce:currency:sync','args'=>['--fetch'=>true],'interval'=>3600,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.currency_prices')],
            'indexnow' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.indexnow'),'command'=>'commerce:indexnow:submit','args'=>[],'interval'=>1800,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.indexnow')],
            'retention' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.retention'),'command'=>'commerce:maintenance:retention','args'=>['--max-batches'=>50],'interval'=>86400,'group'=>'maintenance','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.retention')],
            'quality' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.quality'),'command'=>'commerce:quality:check','args'=>[],'interval'=>21600,'group'=>'maintenance','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.quality')],
            'media_warm' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.media_warm'),'command'=>'commerce:media:warm','args'=>['--max-seconds'=>45],'interval'=>900,'group'=>'catalog','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.media_warm')],
            'media_gc' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.label.media_gc'),'command'=>'commerce:media:gc','args'=>['--apply'=>true],'interval'=>86400,'group'=>'maintenance','description'=>\Commerce\Core\I18n\CanonicalUiText::get('scheduler.description.media_gc')],
            'queue_purge' => ['label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.ochyshchennia_cherh'),'command'=>'commerce:queues:purge','args'=>['--days'=>30,'--limit'=>5000],'interval'=>86400,'group'=>'maintenance','description'=>\Commerce\Core\I18n\CanonicalUiText::get('php.core.scheduler.scheduledtaskregistry.vydaliaie_lyshe_stari_uspishno_zaversheni_zapysy')],
        ];
    }
}
