<script setup lang="ts">
import { t } from '../i18n';
import { computed, ref, watch } from 'vue';
import { Link2, RefreshCw, RotateCcw } from '@lucide/vue';

const props = withDefaults(defineProps<{
  modelValue: string;
  origin: string;
  localePrefix?: string;
  entityPrefix?: string;
  automatic?: boolean;
  previousCount?: number;
}>(), {
  localePrefix: '',
  entityPrefix: '',
  automatic: true,
  previousCount: 0,
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
  regenerate: [];
}>();

const editing = ref(false);
const local = ref(props.modelValue);
watch(() => props.modelValue, (value) => { if (!editing.value) local.value = value; });

const path = computed(() => [props.localePrefix, props.entityPrefix, local.value].filter(Boolean).join('/'));
const preview = computed(() => `${props.origin.replace(/\/$/, '')}/${path.value}`);

function save(): void {
  emit('update:modelValue', local.value.trim());
  editing.value = false;
}
</script>

<template>
  <section class="seo-url-editor">
    <header>
      <div class="seo-url-editor__icon"><Link2 :size="18" /></div>
      <div><strong>SEO URL</strong><small>{{ t('vue.components.seourleditor.stvoriuietsia_avtomatychno_zminiuite_lyshe_koly_tse_') }}</small></div>
      <span v-if="automatic" class="seo-url-editor__badge">Auto</span>
    </header>

    <div class="seo-url-editor__preview" :title="preview">
      <span>{{ origin.replace(/\/$/, '') }}/</span><b>{{ path }}</b>
    </div>

    <div v-if="editing" class="seo-url-editor__edit">
      <input v-model="local" type="text" inputmode="url" autocomplete="off" spellcheck="false" aria-label="SEO slug" />
      <button type="button" class="primary" @click="save">{{ t('vue.components.seourleditor.zberehty_url') }}</button>
      <button type="button" @click="editing = false; local = modelValue">{{ t('vue.components.seourleditor.skasuvaty') }}</button>
    </div>
    <div v-else class="seo-url-editor__actions">
      <button type="button" @click="editing = true">{{ t('vue.components.seourleditor.zminyty') }}</button>
      <button type="button" :title="t('vue.components.seourleditor.zheneruvaty_slug_zanovo_z_nazvy')" @click="emit('regenerate')"><RefreshCw :size="15" /> {{ t('vue.components.seourleditor.zheneruvaty_zanovo') }}</button>
      <span v-if="previousCount > 0"><RotateCcw :size="14" /> {{ t('vue.components.seourleditor.starykh_adres') }} {{ previousCount }} {{ t('vue.components.seourleditor.avtomatychnyi_301') }}</span>
    </div>
  </section>
</template>

<style scoped>
.seo-url-editor{display:grid;gap:12px;padding:16px;border:1px solid var(--admin-border,#dce3ec);border-radius:var(--admin-radius,14px);background:var(--admin-surface,#fff)}
header{display:flex;align-items:center;gap:10px}.seo-url-editor__icon{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:color-mix(in srgb,var(--admin-primary,#0B63F6) 10%,transparent);color:var(--admin-primary,#0B63F6)}
header div:nth-child(2){display:grid;gap:2px;min-width:0;flex:1}header small{opacity:.65}.seo-url-editor__badge{font-size:12px;font-weight:700;padding:4px 7px;border-radius:999px;background:#eef7f1;color:#177245}
.seo-url-editor__preview{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:10px 12px;border-radius:10px;background:var(--admin-muted,#f6f8fb);font-size:13px}.seo-url-editor__preview span{opacity:.55}.seo-url-editor__preview b{font-weight:650}
.seo-url-editor__edit,.seo-url-editor__actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.seo-url-editor__edit input{min-width:220px;flex:1;padding:9px 11px;border:1px solid var(--admin-border,#dce3ec);border-radius:9px;background:inherit;color:inherit}.seo-url-editor button{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid var(--admin-border,#dce3ec);border-radius:9px;background:transparent;color:inherit;cursor:pointer}.seo-url-editor button.primary{background:var(--admin-primary,#0B63F6);border-color:var(--admin-primary,#0B63F6);color:#fff}.seo-url-editor__actions span{display:inline-flex;align-items:center;gap:5px;font-size:12px;opacity:.68}
@media(max-width:640px){.seo-url-editor__edit>*{width:100%}.seo-url-editor__actions button{flex:1;justify-content:center}}
</style>
