<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue';

export interface ColorPickerLabels {
  title: string;
  spectrum: string;
  hue: string;
  hex: string;
  apply: string;
  invalid: string;
  popular: string;
  recent: string;
  recentEmpty: string;
  none: string;
}

const props = withDefaults(defineProps<{ modelValue?: string; fallback?: string; popular: string[]; recent: string[]; labels: ColorPickerLabels }>(), { modelValue: '', fallback: '#1971c2' });
const emit = defineEmits<{ pick: [hex: string]; clear: []; close: [] }>();

type Rgb = [number, number, number];
/** A pointer event (PointerEvent is a MouseEvent with a pointer id). */
type PointerLike = MouseEvent & { pointerId: number };
const clamp = (value: number, min = 0, max = 1): number => Math.min(max, Math.max(min, value));
const HEX_INPUT = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

/** Reads #rgb, #rrggbb (also #rrggbbaa, alpha dropped) and rgb()/rgba() values; anything else is null. */
const parseColor = (value: string): Rgb | null => {
  const text = value.trim().toLowerCase();
  const hex = /^#?([0-9a-f]{3}|[0-9a-f]{6})(?:[0-9a-f]{2})?$/.exec(text);
  if (hex?.[1]) {
    const digits = hex[1].length === 3 ? [...hex[1]].map((c) => c + c).join('') : hex[1];
    return [parseInt(digits.slice(0, 2), 16), parseInt(digits.slice(2, 4), 16), parseInt(digits.slice(4, 6), 16)];
  }
  const rgb = /^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/.exec(text);
  if (rgb) return [clamp(Number(rgb[1]), 0, 255), clamp(Number(rgb[2]), 0, 255), clamp(Number(rgb[3]), 0, 255)];
  return null;
};
const toHex = ([r, g, b]: Rgb): string => `#${[r, g, b].map((n) => Math.round(n).toString(16).padStart(2, '0')).join('')}`;

const rgbToHsv = ([r, g, b]: Rgb): { h: number; s: number; v: number } => {
  const rn = r / 255; const gn = g / 255; const bn = b / 255;
  const max = Math.max(rn, gn, bn); const min = Math.min(rn, gn, bn); const d = max - min;
  let h = 0;
  if (d !== 0) {
    if (max === rn) h = ((gn - bn) / d) % 6;
    else if (max === gn) h = (bn - rn) / d + 2;
    else h = (rn - gn) / d + 4;
    h *= 60;
    if (h < 0) h += 360;
  }
  return { h, s: max === 0 ? 0 : d / max, v: max };
};
const hsvToRgb = (h: number, s: number, v: number): Rgb => {
  const c = v * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = v - c;
  const sector = Math.floor((((h % 360) + 360) % 360) / 60);
  const [r, g, b] = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][sector] ?? [c, 0, x];
  return [((r ?? 0) + m) * 255, ((g ?? 0) + m) * 255, ((b ?? 0) + m) * 255];
};

const start = rgbToHsv(parseColor(props.modelValue) ?? parseColor(props.fallback) ?? [25, 113, 194]);
const hsv = reactive({ h: start.h, s: start.s, v: start.v });
const currentHex = computed(() => toHex(hsvToRgb(hsv.h, hsv.s, hsv.v)));
const hexDraft = ref(currentHex.value);
const attempted = ref(false);

const draftValid = computed(() => HEX_INPUT.test(hexDraft.value.trim()));
const showInvalid = computed(() => {
  const text = hexDraft.value.trim();
  if (text === '' || draftValid.value) return false;
  return attempted.value || /[^#0-9a-f]/i.test(text) || text.replace('#', '').length > 6;
});
const previewColor = computed(() => (draftValid.value ? toHex(parseColor(hexDraft.value) ?? [0, 0, 0]) : currentHex.value));

const syncDraft = () => { hexDraft.value = currentHex.value; attempted.value = false; };

const onHexInput = () => {
  const rgb = HEX_INPUT.test(hexDraft.value.trim()) ? parseColor(hexDraft.value) : null;
  if (rgb === null) return;
  const next = rgbToHsv(rgb);
  if (next.s > 0 && next.v > 0) hsv.h = next.h;
  hsv.s = next.s;
  hsv.v = next.v;
  attempted.value = false;
};

const submit = () => {
  if (!draftValid.value) { attempted.value = true; return; }
  emit('pick', toHex(parseColor(hexDraft.value) ?? [0, 0, 0]));
};

// ---- spectrum square (saturation to the right, brightness upwards) and hue strip ----
const plane = ref<HTMLElement | null>(null);
const hueStrip = ref<HTMLElement | null>(null);
const dragging = ref<'' | 'plane' | 'hue'>('');

const movePlane = (event: PointerLike) => {
  const box = plane.value?.getBoundingClientRect();
  if (!box || box.width === 0 || box.height === 0) return;
  hsv.s = clamp((event.clientX - box.left) / box.width);
  hsv.v = 1 - clamp((event.clientY - box.top) / box.height);
  syncDraft();
};
const moveHue = (event: PointerLike) => {
  const box = hueStrip.value?.getBoundingClientRect();
  if (!box || box.width === 0) return;
  hsv.h = clamp((event.clientX - box.left) / box.width) * 360;
  syncDraft();
};
const pointerDown = (kind: 'plane' | 'hue', event: PointerLike) => {
  const target = event.currentTarget as HTMLElement;
  target.setPointerCapture?.(event.pointerId);
  target.focus();
  dragging.value = kind;
  if (kind === 'plane') movePlane(event); else moveHue(event);
};
const pointerMove = (kind: 'plane' | 'hue', event: PointerLike) => {
  if (dragging.value !== kind) return;
  if (kind === 'plane') movePlane(event); else moveHue(event);
};
const pointerUp = () => { dragging.value = ''; };

const planeKey = (event: KeyboardEvent) => {
  const step = event.shiftKey ? 0.1 : 0.01;
  if (event.key === 'ArrowLeft') hsv.s = clamp(hsv.s - step);
  else if (event.key === 'ArrowRight') hsv.s = clamp(hsv.s + step);
  else if (event.key === 'ArrowUp') hsv.v = clamp(hsv.v + step);
  else if (event.key === 'ArrowDown') hsv.v = clamp(hsv.v - step);
  else return;
  event.preventDefault();
  syncDraft();
};
const hueKey = (event: KeyboardEvent) => {
  const step = event.shiftKey ? 10 : 1;
  if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') hsv.h = clamp(hsv.h - step, 0, 360);
  else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') hsv.h = clamp(hsv.h + step, 0, 360);
  else return;
  event.preventDefault();
  syncDraft();
};

const hueColor = computed(() => toHex(hsvToRgb(hsv.h, 1, 1)));
const planeThumb = computed(() => ({ left: `${hsv.s * 100}%`, top: `${(1 - hsv.v) * 100}%`, backgroundColor: currentHex.value }));
const hueThumb = computed(() => ({ left: `${(hsv.h / 360) * 100}%`, backgroundColor: hueColor.value }));

// ---- keep the panel inside the editor box and the screen (narrow phones) ----
const panel = ref<HTMLElement | null>(null);
const fit = () => {
  const el = panel.value;
  const host = el?.closest('.rich-editor');
  if (!el || !host) return;
  const box = host.getBoundingClientRect();
  const viewport = document.documentElement.clientWidth;
  const left = Math.max(box.left, 0) + 4;
  const right = Math.min(box.right, viewport) - 4;
  el.style.maxWidth = `${Math.max(200, right - left)}px`;
  // Position the layout box itself: a negative transform can still enlarge document.scrollWidth.
  el.style.left = '0px';
  const rect = el.getBoundingClientRect();
  let shift = 0;
  if (rect.right > right) shift = right - rect.right;
  if (rect.left + shift < left) shift = left - rect.left;
  el.style.left = `${Math.round(shift)}px`;
};
onMounted(() => { void nextTick(fit); });
</script>

<template>
  <div ref="panel" class="rich-editor__colorpanel" role="group" :aria-label="labels.title" @keydown.esc.stop.prevent="emit('close')">
    <strong>{{ labels.title }}</strong>
    <div
      ref="plane"
      class="rich-editor__plane"
      role="slider"
      tabindex="0"
      :aria-label="labels.spectrum"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-valuenow="Math.round(hsv.s * 100)"
      :aria-valuetext="currentHex"
      :style="{ backgroundColor: hueColor }"
      @pointerdown="pointerDown('plane', $event)"
      @pointermove="pointerMove('plane', $event)"
      @pointerup="pointerUp"
      @pointercancel="pointerUp"
      @keydown="planeKey"
    ><span class="rich-editor__thumb" :style="planeThumb"></span></div>
    <div
      ref="hueStrip"
      class="rich-editor__hue"
      role="slider"
      tabindex="0"
      :aria-label="labels.hue"
      aria-valuemin="0"
      aria-valuemax="360"
      :aria-valuenow="Math.round(hsv.h)"
      @pointerdown="pointerDown('hue', $event)"
      @pointermove="pointerMove('hue', $event)"
      @pointerup="pointerUp"
      @pointercancel="pointerUp"
      @keydown="hueKey"
    ><span class="rich-editor__thumb" :style="hueThumb"></span></div>
    <div class="rich-editor__hexrow">
      <span class="rich-editor__hexpreview"><span class="rich-editor__hexfill" :style="{ backgroundColor: previewColor }"></span></span>
      <input
        v-model="hexDraft"
        type="text"
        class="rich-editor__hex"
        :class="{ 'is-invalid': showInvalid }"
        maxlength="7"
        spellcheck="false"
        autocomplete="off"
        placeholder="#RRGGBB"
        :aria-label="labels.hex"
        :aria-invalid="showInvalid"
        @input="onHexInput"
        @blur="attempted = true"
        @keydown.enter.prevent="submit"
      >
      <button type="button" class="rich-editor__hexok" @click="submit">{{ labels.apply }}</button>
    </div>
    <small v-if="showInvalid" class="rich-editor__hexerror" role="alert">{{ labels.invalid }}</small>
    <small class="rich-editor__rowlabel">{{ labels.popular }}</small>
    <div class="rich-editor__swatchrow rich-editor__swatchrow--popular">
      <button v-for="color in popular.slice(0, 10)" :key="color" type="button" class="rich-editor__swatch" :style="{ backgroundColor: color }" :title="color" :aria-label="color" @click="emit('pick', color)"></button>
    </div>
    <small class="rich-editor__rowlabel">{{ labels.recent }}</small>
    <div v-if="recent.length > 0" class="rich-editor__swatchrow rich-editor__swatchrow--recent">
      <button v-for="color in recent.slice(0, 10)" :key="color" type="button" class="rich-editor__swatch" :style="{ backgroundColor: color }" :title="color" :aria-label="color" @click="emit('pick', color)"></button>
    </div>
    <p v-else class="rich-editor__rowempty rich-editor__swatchrow--recent">{{ labels.recentEmpty }}</p>
    <button type="button" class="rich-editor__colornone" @click="emit('clear')">{{ labels.none }}</button>
  </div>
</template>
