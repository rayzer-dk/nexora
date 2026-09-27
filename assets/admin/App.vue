<script setup lang="ts">
import { t } from './i18n';
import { computed, ref } from 'vue';
import {
  Activity,
  Boxes,
  ChevronRight,
  CircleCheck,
  Gauge,
  RefreshCw,
  Database,
  ArrowRightLeft,
  Globe2,
  Languages,
  Tags,
  BellRing,
  LayoutDashboard,
  Link2,
  MonitorSmartphone,
  PackageCheck,
  Palette,
  Search,
  Settings2,
  ShieldCheck,
  ShoppingBag,
  Truck,
  Wrench,
  Cookie,
  FileBadge2,
  Accessibility,
} from '@lucide/vue';

const dark = ref(false);
const primary = ref('#0B63F6');
const radius = ref(14);
const density = ref<'compact' | 'comfortable' | 'spacious'>('comfortable');
const fontFamily = ref<'system' | 'sans' | 'serif'>('system');

const shellStyle = computed(() => ({
  '--admin-primary': primary.value,
  '--admin-radius': `${radius.value}px`,
  '--admin-density': density.value === 'compact' ? '.9' : density.value === 'spacious' ? '1.1' : '1',
  '--admin-font': fontFamily.value === 'serif' ? 'ui-serif, Georgia, serif' : 'ui-sans-serif, system-ui, sans-serif',
}));

const systemStatus = [
  { name: t('vue.app.yadro'), text: 'Extension API 2.0', state: 'healthy', icon: Boxes },
  { name: t('vue.app.rehion'), text: t('vue.app.yevropa_ukraina'), state: 'healthy', icon: MonitorSmartphone },
  { name: t('vue.app.dostavka'), text: t('vue.app.4_pereviznyky_ukrainy'), state: 'healthy', icon: Truck },
  { name: t('vue.app.onovlennia'), text: t('vue.app.zakhyshchena_sumisnist'), state: 'healthy', icon: PackageCheck },
];

const navigation = [
  { label: t('vue.app.ohliad'), icon: LayoutDashboard, active: true },
  { label: t('vue.app.kataloh'), icon: ShoppingBag },
  { label: t('vue.app.zamovlennia'), icon: PackageCheck },
  { label: t('vue.app.kliienty'), icon: Activity },
  { label: t('vue.app.marketynh'), icon: Gauge },
  { label: t('vue.app.oformlennia'), icon: Palette },
  { label: t('vue.app.intehratsii'), icon: Wrench },
  { label: t('vue.app.nalashtuvannia'), icon: Settings2 },
];

const componentStatus = [
  [t('vue.app.platforma'), '3.5.0'],
  ['PHP', '8.4–8.5'],
  ['Symfony', '8.1 stable'],
  ['Doctrine DBAL', '4.4'],
  ['Extension API', '2.0'],
  [t('vue.app.skhema_bd'), '49'],
  ['TypeScript', '6.0.3'],
];

const activity = [
  [t('vue.app.kesh_dostavky'), t('vue.app.tymchasovyi_kesh_tochok_pratsiuie')],
  [t('vue.app.merchant_dani'), t('vue.app.taksonomiia_ta_avtomatychna_klasyfikatsiia_hotovi')],
  ['SEO URL', t('vue.app.chysti_canonical_ta_polityka_indeksatsii_filtriv')],
  [t('vue.app.systemni_moduli'), t('vue.app.zakhyshcheni_pakety_core')],
  [t('vue.app.kompiliator'), 'TypeScript 6 toolchain'],
];
</script>

<template>
  <div class="admin-app" :class="{ 'is-dark': dark }" :style="shellStyle">
    <aside class="sidebar">
      <a class="admin-brand" href="#" :aria-label="t('vue.app.panel_modern_commerce')">
        <img class="admin-brand__mark" src="/assets/branding/nexora-mark.svg" alt="" width="36" height="36" />
        <span><strong>Nexora Commerce</strong><small>{{ t('vue.app.yevropa_ukraina') }}</small></span>
      </a>

      <nav class="sidebar-nav" :aria-label="t('vue.app.administruvannia')">
        <button v-for="item in navigation" :key="item.label" :class="['nav-item', { 'is-active': item.active }]" type="button">
          <component :is="item.icon" :size="18" aria-hidden="true" />
          <span>{{ item.label }}</span>
        </button>
      </nav>

      <div class="sidebar-health">
        <ShieldCheck :size="20" aria-hidden="true" />
        <div><strong>{{ t('vue.app.systema_pratsiuie') }}</strong><small>{{ t('vue.app.kontrakty_core_zakhyshcheni') }}</small></div>
      </div>
    </aside>

    <section class="admin-workspace">
      <header class="topbar">
        <label class="global-search">
          <Search :size="18" aria-hidden="true" />
          <input type="search" :placeholder="t('vue.app.poshuk_tovariv_zamovlen_nalashtuvan')" :aria-label="t('vue.app.hlobalnyi_poshuk')" />
          <kbd>⌘ K</kbd>
        </label>
        <div class="topbar-actions">
          <button class="icon-action" type="button" :title="t('vue.app.peremknuty_svitlu_temnu_temu')" @click="dark = !dark">
            <MonitorSmartphone :size="19" aria-hidden="true" />
          </button>
          <button class="profile-button" type="button"><span>MC</span><strong>{{ t('vue.app.administrator') }}</strong></button>
        </div>
      </header>

      <main class="admin-main">
        <section class="page-heading">
          <div><p class="eyebrow">{{ t('vue.app.ohliad_systemy') }}</p><h1>{{ t('vue.app.tsentr_keruvannia_mahazynom') }}</h1><p>{{ t('vue.app.krytychno_neobkhidni_funktsii_torhivli_vkhodiat_do_z') }}</p></div>
          <button class="primary-action" type="button"><CircleCheck :size="18" /> {{ t('vue.app.pereviryty_systemu') }}</button>
        </section>

        <section class="metric-grid" :aria-label="t('vue.app.stan_systemy')">
          <article v-for="item in systemStatus" :key="item.name" class="metric-card">
            <div class="metric-icon"><component :is="item.icon" :size="20" /></div>
            <span>{{ item.name }}</span>
            <strong>{{ item.text }}</strong>
            <small><i></i> {{ t('vue.app.hotovo') }}</small>
          </article>
        </section>

        <section class="dashboard-grid">
          <article class="panel panel--wide">
            <div class="panel-heading"><div><span>{{ t('vue.app.rehionalnyi_profil') }}</span><h2>{{ t('vue.app.yevropa_ukraina') }}</h2></div><button class="text-button" type="button">{{ t('vue.app.nalashtuvaty') }} <ChevronRight :size="16" /></button></div>
            <div class="region-summary">
              <div><strong>{{ t('vue.app.lyshe_yes_ukraina') }}</strong><span>{{ t('vue.app.moduli_spetsyfichni_lyshe_dlia_ssha_kytaiu_ta_inshyk') }}</span></div>
              <div><strong>{{ t('vue.app.lehki_dovidnyky_dostavky') }}</strong><span>{{ t('vue.app.mista_ta_viddilennia_zberihaiutsia_lyshe_u_tymchasov') }}</span></div>
              <div><strong>{{ t('vue.app.rezervnyi_rezhym_pereviznyka') }}</strong><span>{{ t('vue.app.ruchne_vvedennia_dostavky_dozvoliaie_oformyty_zamovl') }}</span></div>
            </div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.oformlennia') }}</span><h2>{{ t('vue.app.poperednii_perehliad_temy') }}</h2></div><Palette :size="20" /></div>
            <div class="appearance-form">
              <label>{{ t('vue.app.osnovnyi_kolir') }} <input v-model="primary" type="color" /></label>
              <label>{{ t('vue.app.radius_kutiv') }} <input v-model="radius" type="range" min="4" max="28" /><b>{{ radius }}px</b></label>
              <label>{{ t('vue.app.shchilnist') }}
                <select v-model="density"><option value="compact">{{ t('vue.app.kompaktno') }}</option><option value="comfortable">{{ t('vue.app.zvychaino') }}</option><option value="spacious">{{ t('vue.app.prostoro') }}</option></select>
              </label>
              <label>{{ t('vue.app.typohrafika') }}
                <select v-model="fontFamily"><option value="system">{{ t('vue.app.systemnyi_naishvydshyi') }}</option><option value="sans">{{ t('vue.app.lokalnyi_sans') }}</option><option value="serif">Serif</option></select>
              </label>
              <div class="theme-preview"><button type="button">{{ t('vue.app.osnovna_diia') }}</button><span>{{ t('vue.app.tovar_u_naiavnosti') }}</span></div>
            </div>
          </article>

          <article class="panel panel--wide">
            <div class="panel-heading"><div><span>{{ t('vue.app.arkhitektura') }}</span><h2>{{ t('vue.app.zakhyshcheni_systemni_moduli') }}</h2></div><button class="text-button" type="button">{{ t('vue.app.perehlianuty') }} <ChevronRight :size="16" /></button></div>
            <div class="module-row"><span>Catalog → Pricing → Inventory</span><b>{{ t('vue.app.zakhyshcheno') }}</b></div>
            <div class="module-row"><span>Cart → Checkout → Orders</span><b>{{ t('vue.app.zakhyshcheno') }}</b></div>
            <div class="module-row"><span>SEO → Media → Search → Analytics</span><b>{{ t('vue.app.zakhyshcheno') }}</b></div>
            <div class="module-row"><span>{{ t('vue.app.storonnie_rozshyrennia') }}</span><b class="optional">{{ t('vue.app.mozhna_vydalyty') }}</b></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.ostanni_perevirky') }}</span><h2>{{ t('vue.app.stan_roboty') }}</h2></div><Activity :size="20" /></div>
            <div v-for="row in activity" :key="row[0]" class="activity-row"><i></i><div><strong>{{ row[0] }}</strong><span>{{ row[1] }}</span></div></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.spovishchennia') }}</span><h2>{{ t('vue.app.tsentr_spovishchen') }}</h2></div><BellRing :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>Email</strong><span>{{ t('vue.app.adaptyvni_shablony_nadiina_cherha') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>Telegram</strong><span>{{ t('vue.app.neoboviazkovi_bot_api_spovishchennia_checkout_vid_ny') }}</span></div></div>
          </article>

          <article class="panel panel--wide">
            <div class="panel-heading"><div><span>{{ t('vue.app.tsentr_mihratsii') }}</span><h2>{{ t('vue.app.bezpechne_perenesennia_isnuiuchoho_mahazynu') }}</h2></div><ArrowRightLeft :size="20" /></div>
            <div class="region-summary">
              <div><strong>OpenCart / ocStore 3.x</strong><span>{{ t('vue.app.dzherelo_lyshe_dlia_chytannia_z_paketnym_perenosom_m') }}</span></div>
              <div><strong>{{ t('vue.app.paket_perenesennia') }}</strong><span>{{ t('vue.app.potokovyi_manifest_ndjson_dlia_velykykh_mihratsii_be') }}</span></div>
              <div><strong>{{ t('vue.app.spochatku_poperednia_perevirka') }}</strong><span>{{ t('vue.app.kilkist_konflikty_ta_vidpovidnosti_pereviriaiutsia_d') }}</span></div>
            </div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.lokalizatsiia') }}</span><h2>{{ t('vue.app.movy_ta_valiuty') }}</h2></div><Globe2 :size="20" /></div>
            <div class="activity-row"><Languages :size="17" /><div><strong>BCP-47 locales</strong><span>{{ t('vue.app.movy_okremo_dlia_mahazynu_fallback_ta_neoboviazkovi_') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>ISO-4217 currencies</strong><span>{{ t('vue.app.priorytet_iavnykh_praisiv_avtomatychna_konvertatsiia') }}</span></div></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>Google Commerce</span><h2>{{ t('vue.app.taksonomiia_tovariv') }}</h2></div><Tags :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.znachennia_katehorii') }}</strong><span>{{ t('vue.app.google_katehoriiu_mozhna_odyn_raz_pryznachyty_kateho') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.perevyznachennia_tovaru') }}</strong><span>{{ t('vue.app.perevyznachennia_lyshe_za_potreby_inakshe_google_moz') }}</span></div></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>SEO URLs</span><h2>{{ t('vue.app.avtomatychno_ta_z_mozhlyvistiu_redahuvannia') }}</h2></div><Link2 :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.za_zamovchuvanniam_ukraina') }}</strong><span>{{ t('vue.app.ua_uk_ua_uah_systemni_url_maiut_stabilni_anhliiski_s') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.bezpechna_zmina_url') }}</strong><span>{{ t('vue.app.pislia_zminy_slug_poperednia_adresa_zalyshaietsia_pr') }}</span></div></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.konfidentsiinist') }}</span><h2>{{ t('vue.app.tsentr_zhody') }}</h2></div><Cookie :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.suvori_nalashtuvannia_yes') }}</strong><span>{{ t('vue.app.do_vyboru_korystuvacha_aktyvni_lyshe_neobkhidni_kate') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>Google Consent Mode v2</strong><span>{{ t('vue.app.analitychni_ta_reklamni_syhnaly_zalezhat_vid_zberezh') }}</span></div></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.vidpovidnist') }}</span><h2>{{ t('vue.app.hotovnist_tovaru_dlia_yes') }}</h2></div><FileBadge2 :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>{{ t('vue.app.sertyfikaty_ta_deklaratsii') }}</strong><span>{{ t('vue.app.vyrobnyk_vidpovidalna_osoba_poperedzhennia_sertyfika') }}</span></div></div>
            <div class="activity-row"><Accessibility :size="17" /><div><strong>{{ t('vue.app.dostupnist') }}</strong><span>{{ t('vue.app.bazovyi_riven_en_301_549_wcag_2_2_aa') }}</span></div></div>
          </article>

          <article class="panel panel--wide">
            <div class="panel-heading"><div><span>{{ t('vue.app.versii_ta_onovlennia') }}</span><h2>{{ t('vue.app.tsentr_komponentiv') }}</h2></div><RefreshCw :size="20" /></div>
            <div class="module-row" v-for="row in componentStatus" :key="row[0]"><span>{{ row[0] }}</span><b>{{ row[1] }}</b></div>
          </article>

          <article class="panel">
            <div class="panel-heading"><div><span>{{ t('vue.app.baza_danykh') }}</span><h2>{{ t('vue.app.indeksy_ta_versii') }}</h2></div><Database :size="20" /></div>
            <div class="activity-row"><i></i><div><strong>utf8mb4</strong><span>{{ t('vue.app.uuidv7_rynky_rezervuvannia') }}</span></div></div>
            <div class="activity-row"><i></i><div><strong>Migrations</strong><span>{{ t('vue.app.versiina_skhema_z_mozhlyvistiu_vidkatu') }}</span></div></div>
          </article>
        </section>
      </main>
    </section>
  </div>
</template>
