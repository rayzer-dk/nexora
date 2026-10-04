<script setup lang="ts">
import { t } from '../i18n';
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import { TextStyleKit } from '@tiptap/extension-text-style';
import {
  Bold, Italic, Underline, Strikethrough, Subscript as SubIcon, Superscript as SupIcon, Highlighter, List, ListOrdered, Quote, Minus, Table2,
  ImagePlus, Link2, Unlink, RemoveFormatting, Code, Undo2, Redo2, Maximize2, Minimize2, X, FolderOpen,
  Palette, PaintBucket, Anchor as AnchorIcon, Video as VideoIcon, Ellipsis, ChevronDown,
  TextAlignStart, TextAlignCenter, TextAlignEnd, TextAlignJustify, Rows3, Columns3, Trash2,
} from '@lucide/vue';
import { BlockAlign } from '../features/editor-align';
import { BlockAnchor, Video, videoEmbedUrl } from '../features/editor-extras';
import { pickMedia } from '../features/media-picker';
import { CODE_THEME_LABELS } from '../features/html-code-editor';
import type { CodeThemeName, HtmlCodeEditor } from '../features/html-code-editor';

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), { placeholder: t('vue.components.richtexteditor.pochnit_vvodyty_tekst') });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const tr = (key: string, replace: Record<string, string | number> = {}) => t(`vue.components.richtexteditor.${key}`, replace);

const editor = new Editor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({ link: { openOnClick: false, autolink: true, HTMLAttributes: { target: null, rel: null } } }),
    Image.configure({ allowBase64: false }),
    Highlight,
    Subscript,
    Superscript,
    BlockAlign,
    BlockAnchor,
    Video,
    TextStyleKit,
    TableKit.configure({ table: { resizable: false } }),
    Placeholder.configure({ placeholder: props.placeholder }),
  ],
  editorProps: { attributes: { class: 'rich-editor__content', spellcheck: 'true' } },
  onUpdate: ({ editor: instance }) => emit('update:modelValue', instance.getHTML()),
});

watch(() => props.modelValue, (value) => {
  if (!sourceMode.value && editor.getHTML() !== value) editor.commands.setContent(value, { emitUpdate: false });
});

const root = ref<HTMLElement | null>(null);
const fullscreen = ref(false);
const setFullscreen = (value: boolean) => {
  fullscreen.value = value;
  document.body.classList.toggle('has-admin-modal', value);
};
const onKey = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && fullscreen.value && !linkDialog.open && !imageDialog.open && !anchorDialog.open && !videoDialog.open) setFullscreen(false);
};
onMounted(() => document.addEventListener('keydown', onKey));

// ---- HTML source view (CodeMirror, monokai) ----
const sourceMode = ref(false);
const sourceHost = ref<HTMLElement | null>(null);
let code: HtmlCodeEditor | null = null;
const codeTheme = ref<CodeThemeName>('monokai');
const changeCodeTheme = (event: Event) => {
  codeTheme.value = (event.target as HTMLSelectElement).value as CodeThemeName;
  code?.setTheme(codeTheme.value);
};
const toggleSource = async () => {
  if (!sourceMode.value) {
    sourceMode.value = true;
    await nextTick();
    if (!sourceHost.value) return;
    const { createHtmlCodeEditor, savedCodeTheme } = await import('../features/html-code-editor');
    codeTheme.value = savedCodeTheme();
    code = createHtmlCodeEditor(sourceHost.value, editor.getHTML(), (value) => emit('update:modelValue', value), tr('html'));
    code.focus();
    return;
  }
  const html = code?.getValue() ?? editor.getHTML();
  code?.destroy();
  code = null;
  sourceMode.value = false;
  editor.commands.setContent(html, { emitUpdate: true });
};
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  if (fullscreen.value) document.body.classList.remove('has-admin-modal');
  code?.destroy();
  editor.destroy();
});

// ---- paragraph style ----
const blockValue = (): string => {
  if (editor.isActive('heading', { level: 1 })) return 'h1';
  if (editor.isActive('heading', { level: 2 })) return 'h2';
  if (editor.isActive('heading', { level: 3 })) return 'h3';
  if (editor.isActive('heading', { level: 4 })) return 'h4';
  if (editor.isActive('codeBlock')) return 'pre';
  return 'p';
};
const setBlock = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value;
  const chain = editor.chain().focus();
  if (value === 'p') chain.setParagraph().run();
  else if (value === 'pre') chain.toggleCodeBlock().run();
  else chain.setHeading({ level: Number(value.slice(1)) as 1 | 2 | 3 | 4 }).run();
};
const align = (value: 'left' | 'center' | 'right' | 'justify') => {
  const current = editor.getAttributes('paragraph').align ?? editor.getAttributes('heading').align ?? editor.getAttributes('image').align ?? null;
  if (current === value) editor.chain().focus().unsetBlockAlign().run();
  else editor.chain().focus().setBlockAlign(value).run();
};
const isAligned = (value: string) => (editor.getAttributes('paragraph').align ?? editor.getAttributes('heading').align ?? editor.getAttributes('image').align ?? null) === value;

// ---- link dialog ----
const linkDialog = reactive({ open: false, url: '', text: '', blank: false, nofollow: false, sponsored: false, ugc: false, hasLink: false, hasSelection: false });
const linkUrlInput = ref<HTMLInputElement | null>(null);
const openLink = async () => {
  const attrs = editor.getAttributes('link') as { href?: string; target?: string | null; rel?: string | null };
  const { from, to } = editor.state.selection;
  linkDialog.hasLink = editor.isActive('link');
  linkDialog.url = attrs.href ?? '';
  linkDialog.blank = attrs.target === '_blank';
  linkDialog.nofollow = (attrs.rel ?? '').includes('nofollow');
  linkDialog.sponsored = (attrs.rel ?? '').includes('sponsored');
  linkDialog.ugc = (attrs.rel ?? '').includes('ugc');
  linkDialog.hasSelection = from !== to || linkDialog.hasLink;
  linkDialog.text = linkDialog.hasLink ? '' : editor.state.doc.textBetween(from, to, ' ');
  linkDialog.open = true;
  await nextTick();
  linkUrlInput.value?.focus();
};
const closeLink = () => { linkDialog.open = false; editor.commands.focus(); };
const applyLink = () => {
  const url = linkDialog.url.trim();
  if (url === '') { removeLink(); return; }
  const rel = [linkDialog.blank ? 'noopener' : '', linkDialog.blank ? 'noreferrer' : '', linkDialog.nofollow ? 'nofollow' : '', linkDialog.sponsored ? 'sponsored' : '', linkDialog.ugc ? 'ugc' : ''].filter(Boolean).join(' ') || null;
  const attrs = { href: url, target: linkDialog.blank ? '_blank' : null, rel };
  if (linkDialog.hasSelection) {
    editor.chain().focus().extendMarkRange('link').setLink(attrs).run();
  } else {
    editor.chain().focus().insertContent({ type: 'text', text: linkDialog.text.trim() || url, marks: [{ type: 'link', attrs }] }).run();
  }
  linkDialog.open = false;
};
const removeLink = () => {
  editor.chain().focus().extendMarkRange('link').unsetLink().run();
  linkDialog.open = false;
};

// ---- image dialog ----
const imageDialog = reactive({ open: false, url: '', alt: '' });
const imageUrlInput = ref<HTMLInputElement | null>(null);
const openImage = async () => {
  imageDialog.url = '';
  imageDialog.alt = '';
  imageDialog.open = true;
  await nextTick();
  imageUrlInput.value?.focus();
};
const closeImage = () => { imageDialog.open = false; editor.commands.focus(); };
const insertFromLibrary = async () => {
  imageDialog.open = false;
  const picked = await pickMedia({ multiple: true });
  for (const item of picked) editor.chain().focus().setImage({ src: item.url, alt: item.alt || item.title || '' }).run();
};
const insertFromUrl = () => {
  const url = imageDialog.url.trim();
  if (url === '') return;
  editor.chain().focus().setImage({ src: url, alt: imageDialog.alt.trim() }).run();
  imageDialog.open = false;
};


// ---- colour, font, size, line height ----
const FONTS = ['Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Courier New', 'Verdana', 'Tahoma', 'Trebuchet MS'];
const SIZES = [12, 14, 16, 18, 20, 24, 28, 32, 40, 48];
const LINE_HEIGHTS = ['1', '1.15', '1.3', '1.5', '1.8', '2'];
const textStyle = (name: string): string => String((editor.getAttributes('textStyle') as Record<string, unknown>)[name] ?? '');
const fontValue = (): string => textStyle('fontFamily').replace(/["']/g, '').split(',')[0]?.trim() ?? '';
const normalizeHex = (value: string): string => (/^#[0-9a-fA-F]{6}$/.test(value) ? value : '#000000');
const setColor = (event: Event) => { const value = (event.target as HTMLInputElement).value; editor.chain().focus().setColor(value).run(); rememberColor('text', value); };
const setBackground = (event: Event) => { const value = (event.target as HTMLInputElement).value; editor.chain().focus().setBackgroundColor(value).run(); rememberColor('bg', value); };
const clearColors = () => { editor.chain().focus().unsetColor().unsetBackgroundColor().run(); };
const setFont = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') editor.chain().focus().unsetFontFamily().run();
  else editor.chain().focus().setFontFamily(value).run();
};
const setSize = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') editor.chain().focus().unsetFontSize().run();
  else editor.chain().focus().setFontSize(`${value}px`).run();
};
const setLineHeight = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') editor.chain().focus().unsetLineHeight().run();
  else editor.chain().focus().setLineHeight(value).run();
};

// ---- anchor dialog ----
const anchorDialog = reactive({ open: false, id: '', has: false });
const anchorInput = ref<HTMLInputElement | null>(null);
const openAnchor = async () => {
  const current = (editor.getAttributes('heading').id ?? editor.getAttributes('paragraph').id ?? '') as string;
  anchorDialog.id = current;
  anchorDialog.has = current !== '';
  anchorDialog.open = true;
  await nextTick();
  anchorInput.value?.focus();
};
const closeAnchor = () => { anchorDialog.open = false; editor.commands.focus(); };
const anchorValid = () => /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(anchorDialog.id.trim());
const applyAnchor = () => {
  if (!anchorValid()) return;
  editor.chain().focus().setBlockAnchor(anchorDialog.id.trim()).run();
  anchorDialog.open = false;
};
const removeAnchor = () => {
  editor.chain().focus().unsetBlockAnchor().run();
  anchorDialog.open = false;
};

// ---- video dialog ----
const videoDialog = reactive({ open: false, url: '' });
const videoInput = ref<HTMLInputElement | null>(null);
const openVideo = async () => {
  videoDialog.url = '';
  videoDialog.open = true;
  await nextTick();
  videoInput.value?.focus();
};
const closeVideo = () => { videoDialog.open = false; editor.commands.focus(); };
const insertVideo = () => {
  if (videoEmbedUrl(videoDialog.url) === null) return;
  editor.chain().focus().setVideo(videoDialog.url).run();
  videoDialog.open = false;
};


// ---- compact toolbar: rarely used tools live in the "more" menu ----
const moreOpen = ref(false);
const colorMenu = ref<'' | 'text' | 'bg'>('');
const closeMenus = (event: Event) => {
  if (!(event.target instanceof Element) || !event.target.closest('.rich-editor__menu-host')) { moreOpen.value = false; colorMenu.value = ''; }
};
onMounted(() => document.addEventListener('mousedown', closeMenus));
onBeforeUnmount(() => document.removeEventListener('mousedown', closeMenus));

// ---- recently used colours (kept in this browser only) ----
const RECENT_KEY = 'mc_editor_recent_colors';
const loadRecent = (): { text: string[]; bg: string[] } => {
  try {
    const parsed = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '{}') as { text?: unknown; bg?: unknown };
    const clean = (list: unknown): string[] => (Array.isArray(list) ? list.filter((item): item is string => typeof item === 'string' && /^#[0-9a-fA-F]{6}$/.test(item)).slice(0, 12) : []);
    return { text: clean(parsed.text), bg: clean(parsed.bg) };
  } catch { return { text: [], bg: [] }; }
};
const recentColors = reactive(loadRecent());
const rememberColor = (kind: 'text' | 'bg', value: string) => {
  const color = value.toLowerCase();
  recentColors[kind] = [color, ...recentColors[kind].filter((item) => item !== color)].slice(0, 12);
  try { localStorage.setItem(RECENT_KEY, JSON.stringify({ text: recentColors.text, bg: recentColors.bg })); } catch { /* storage blocked: the list lives for this page only */ }
};
const applyRecent = (kind: 'text' | 'bg', color: string) => {
  if (kind === 'text') editor.chain().focus().setColor(color).run();
  else editor.chain().focus().setBackgroundColor(color).run();
  rememberColor(kind, color);
  colorMenu.value = '';
};

const insertTable = () => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
const words = (): number => {
  const text = editor.getText().trim();
  return text === '' ? 0 : text.split(/\s+/u).length;
};
</script>

<template>
  <div ref="root" class="rich-editor" :class="{ 'rich-editor--fullscreen': fullscreen }">
    <div class="rich-editor__toolbar" role="toolbar" :aria-label="t('vue.components.richtexteditor.formatuvannia_tekstu')">
      <button type="button" :title="tr('undo')" :aria-label="tr('undo')" :disabled="sourceMode || !editor.can().undo()" @click="editor.chain().focus().undo().run()"><Undo2 :size="16" /></button>
      <button type="button" :title="tr('redo')" :aria-label="tr('redo')" :disabled="sourceMode || !editor.can().redo()" @click="editor.chain().focus().redo().run()"><Redo2 :size="16" /></button>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <select class="rich-editor__select" :aria-label="tr('block_style')" :title="tr('block_style')" :disabled="sourceMode" :value="blockValue()" @change="setBlock">
        <option value="p">{{ tr('paragraph') }}</option>
        <option value="h1">{{ tr('heading_n', { n: 1 }) }}</option>
        <option value="h2">{{ tr('heading_n', { n: 2 }) }}</option>
        <option value="h3">{{ tr('heading_n', { n: 3 }) }}</option>
        <option value="h4">{{ tr('heading_n', { n: 4 }) }}</option>
        <option value="pre">{{ tr('code_block') }}</option>
      </select>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <select class="rich-editor__select" :aria-label="tr('font_family')" :title="tr('font_family')" :disabled="sourceMode" :value="fontValue()" @change="setFont">
        <option value="">{{ tr('font_default') }}</option>
        <option v-for="font in FONTS" :key="font" :value="font">{{ font }}</option>
      </select>
      <select class="rich-editor__select rich-editor__select--narrow" :aria-label="tr('font_size')" :title="tr('font_size')" :disabled="sourceMode" :value="textStyle('fontSize').replace('px', '')" @change="setSize">
        <option value="">Aa</option>
        <option v-for="size in SIZES" :key="size" :value="String(size)">{{ size }}</option>
      </select>
      <span class="rich-editor__menu-host rich-editor__colorgroup">
        <label class="rich-editor__color" :title="tr('text_color')"><Palette :size="16" /><input type="color" :aria-label="tr('text_color')" :disabled="sourceMode" :value="normalizeHex(textStyle('color'))" @change="setColor"></label>
        <button type="button" class="rich-editor__tiny" :title="tr('recent_colors')" :aria-label="tr('recent_colors')" :aria-expanded="colorMenu === 'text'" :disabled="sourceMode" @click="colorMenu = colorMenu === 'text' ? '' : 'text'"><ChevronDown :size="14" /></button>
        <div v-if="colorMenu === 'text'" class="rich-editor__swatches" role="group" :aria-label="tr('recent_colors')">
          <button v-for="color in recentColors.text" :key="color" type="button" class="rich-editor__swatch" :style="{ backgroundColor: color }" :title="color" :aria-label="color" @click="applyRecent('text', color)"></button>
          <small v-if="recentColors.text.length === 0">{{ tr('recent_none') }}</small>
        </div>
      </span>
      <span class="rich-editor__menu-host rich-editor__colorgroup">
        <label class="rich-editor__color" :title="tr('bg_color')"><PaintBucket :size="16" /><input type="color" :aria-label="tr('bg_color')" :disabled="sourceMode" :value="normalizeHex(textStyle('backgroundColor') || '#ffff00')" @change="setBackground"></label>
        <button type="button" class="rich-editor__tiny" :title="tr('recent_colors')" :aria-label="tr('recent_colors')" :aria-expanded="colorMenu === 'bg'" :disabled="sourceMode" @click="colorMenu = colorMenu === 'bg' ? '' : 'bg'"><ChevronDown :size="14" /></button>
        <div v-if="colorMenu === 'bg'" class="rich-editor__swatches" role="group" :aria-label="tr('recent_colors')">
          <button v-for="color in recentColors.bg" :key="color" type="button" class="rich-editor__swatch" :style="{ backgroundColor: color }" :title="color" :aria-label="color" @click="applyRecent('bg', color)"></button>
          <small v-if="recentColors.bg.length === 0">{{ tr('recent_none') }}</small>
        </div>
      </span>
      <button type="button" :title="tr('color_reset')" :aria-label="tr('color_reset')" :disabled="sourceMode" @click="clearColors"><X :size="14" /></button>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <button type="button" :title="tr('bold')" :aria-label="tr('bold')" :disabled="sourceMode" :class="{ active: editor.isActive('bold') }" @click="editor.chain().focus().toggleBold().run()"><Bold :size="16" /></button>
      <button type="button" :title="tr('italic')" :aria-label="tr('italic')" :disabled="sourceMode" :class="{ active: editor.isActive('italic') }" @click="editor.chain().focus().toggleItalic().run()"><Italic :size="16" /></button>
      <button type="button" :title="tr('underline')" :aria-label="tr('underline')" :disabled="sourceMode" :class="{ active: editor.isActive('underline') }" @click="editor.chain().focus().toggleUnderline().run()"><Underline :size="16" /></button>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <button type="button" :title="tr('align_left')" :aria-label="tr('align_left')" :disabled="sourceMode" :class="{ active: isAligned('left') }" @click="align('left')"><TextAlignStart :size="16" /></button>
      <button type="button" :title="tr('align_center')" :aria-label="tr('align_center')" :disabled="sourceMode" :class="{ active: isAligned('center') }" @click="align('center')"><TextAlignCenter :size="16" /></button>
      <button type="button" :title="tr('align_right')" :aria-label="tr('align_right')" :disabled="sourceMode" :class="{ active: isAligned('right') }" @click="align('right')"><TextAlignEnd :size="16" /></button>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <button type="button" :title="t('vue.components.richtexteditor.markirovanyi_spysok')" :aria-label="t('vue.components.richtexteditor.markirovanyi_spysok')" :disabled="sourceMode" :class="{ active: editor.isActive('bulletList') }" @click="editor.chain().focus().toggleBulletList().run()"><List :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.numerovanyi_spysok')" :aria-label="t('vue.components.richtexteditor.numerovanyi_spysok')" :disabled="sourceMode" :class="{ active: editor.isActive('orderedList') }" @click="editor.chain().focus().toggleOrderedList().run()"><ListOrdered :size="16" /></button>
      <button type="button" :title="tr('quote')" :aria-label="tr('quote')" :disabled="sourceMode" :class="{ active: editor.isActive('blockquote') }" @click="editor.chain().focus().toggleBlockquote().run()"><Quote :size="16" /></button>
      <span class="rich-editor__sep" aria-hidden="true"></span>
      <button type="button" :title="tr('link')" :aria-label="tr('link')" :disabled="sourceMode" :class="{ active: editor.isActive('link') }" @click="openLink"><Link2 :size="16" /></button>
      <button type="button" :title="tr('unlink')" :aria-label="tr('unlink')" :disabled="sourceMode || !editor.isActive('link')" @click="removeLink"><Unlink :size="16" /></button>
      <button type="button" :title="tr('image')" :aria-label="tr('image')" :disabled="sourceMode" @click="openImage"><ImagePlus :size="16" /></button>
      <button type="button" :title="tr('video')" :aria-label="tr('video')" :disabled="sourceMode" @click="openVideo"><VideoIcon :size="16" /></button>
      <button type="button" :title="tr('table')" :aria-label="tr('table')" :disabled="sourceMode" @click="insertTable"><Table2 :size="16" /></button>
      <span class="rich-editor__menu-host rich-editor__more">
        <button type="button" :title="tr('more_tools')" :aria-label="tr('more_tools')" :aria-expanded="moreOpen" :disabled="sourceMode" @click="moreOpen = !moreOpen"><Ellipsis :size="16" /></button>
        <div v-if="moreOpen" class="rich-editor__more-panel" role="toolbar" :aria-label="tr('more_tools')" @click="moreOpen = false">
        <button type="button" :title="tr('strike')" :aria-label="tr('strike')" :disabled="sourceMode" :class="{ active: editor.isActive('strike') }" @click="editor.chain().focus().toggleStrike().run()"><Strikethrough :size="16" /></button>
        <button type="button" :title="tr('highlight')" :aria-label="tr('highlight')" :disabled="sourceMode" :class="{ active: editor.isActive('highlight') }" @click="editor.chain().focus().toggleHighlight().run()"><Highlighter :size="16" /></button>
        <button type="button" :title="tr('subscript')" :aria-label="tr('subscript')" :disabled="sourceMode" :class="{ active: editor.isActive('subscript') }" @click="editor.chain().focus().toggleSubscript().run()"><SubIcon :size="16" /></button>
        <button type="button" :title="tr('superscript')" :aria-label="tr('superscript')" :disabled="sourceMode" :class="{ active: editor.isActive('superscript') }" @click="editor.chain().focus().toggleSuperscript().run()"><SupIcon :size="16" /></button>
        <button type="button" :title="tr('align_justify')" :aria-label="tr('align_justify')" :disabled="sourceMode" :class="{ active: isAligned('justify') }" @click="align('justify')"><TextAlignJustify :size="16" /></button>
        <button type="button" :title="tr('rule')" :aria-label="tr('rule')" :disabled="sourceMode" @click="editor.chain().focus().setHorizontalRule().run()"><Minus :size="16" /></button>
        <button type="button" :title="tr('anchor')" :aria-label="tr('anchor')" :disabled="sourceMode" :class="{ active: editor.getAttributes('heading').id || editor.getAttributes('paragraph').id }" @click="openAnchor"><AnchorIcon :size="16" /></button>
        <button type="button" :title="tr('clear')" :aria-label="tr('clear')" :disabled="sourceMode" @click="editor.chain().focus().clearNodes().unsetAllMarks().run()"><RemoveFormatting :size="16" /></button>
          <select class="rich-editor__select rich-editor__select--panel" @click.stop :aria-label="tr('line_height')" :title="tr('line_height')" :disabled="sourceMode" :value="textStyle('lineHeight')" @change="setLineHeight">
            <option value="">{{ tr('line_height') }}</option>
            <option v-for="height in LINE_HEIGHTS" :key="height" :value="height">{{ height }}</option>
          </select>
        </div>
      </span>
      <span class="rich-editor__spacer" aria-hidden="true"></span>
      <button type="button" :title="fullscreen ? tr('exit_fullscreen') : tr('fullscreen')" :aria-label="fullscreen ? tr('exit_fullscreen') : tr('fullscreen')" :aria-pressed="fullscreen" @click="setFullscreen(!fullscreen)"><Minimize2 v-if="fullscreen" :size="16" /><Maximize2 v-else :size="16" /></button>
      <select v-if="sourceMode" class="rich-editor__select" :aria-label="tr('code_theme')" :title="tr('code_theme')" :value="codeTheme" @change="changeCodeTheme"><option v-for="(label, name) in CODE_THEME_LABELS" :key="name" :value="name">{{ label }}</option></select>
      <button type="button" class="rich-editor__source-toggle" :title="tr('html')" :aria-label="tr('html')" :aria-pressed="sourceMode" :class="{ active: sourceMode }" @click="toggleSource"><Code :size="16" /> <span>HTML</span></button>
    </div>
    <div v-if="!sourceMode && editor.isActive('table')" class="rich-editor__toolbar rich-editor__toolbar--table" role="toolbar" :aria-label="tr('table')">
      <button type="button" @click="editor.chain().focus().addRowAfter().run()"><Rows3 :size="16" /> <span>{{ tr('table_add_row') }}</span></button>
      <button type="button" @click="editor.chain().focus().addColumnAfter().run()"><Columns3 :size="16" /> <span>{{ tr('table_add_col') }}</span></button>
      <button type="button" @click="editor.chain().focus().deleteRow().run()"><Trash2 :size="16" /> <span>{{ tr('table_del_row') }}</span></button>
      <button type="button" @click="editor.chain().focus().deleteColumn().run()"><Trash2 :size="16" /> <span>{{ tr('table_del_col') }}</span></button>
      <button type="button" @click="editor.chain().focus().toggleHeaderRow().run()"><span>{{ tr('table_header') }}</span></button>
      <button type="button" class="is-danger" @click="editor.chain().focus().deleteTable().run()"><Trash2 :size="16" /> <span>{{ tr('table_del') }}</span></button>
    </div>
    <div v-show="sourceMode" ref="sourceHost" class="rich-editor__code"></div>
    <EditorContent v-show="!sourceMode" :editor="editor" />
    <div class="rich-editor__status">{{ tr('words', { count: words() }) }}</div>

    <div v-if="linkDialog.open" class="rich-editor__modal" role="presentation" @mousedown.self="closeLink">
      <form class="rich-editor__dialog" role="dialog" aria-modal="true" :aria-label="tr('link_title')" @submit.prevent="applyLink" @keydown.esc.stop="closeLink">
        <button type="button" class="admin-modal__close" :title="tr('close')" :aria-label="tr('close')" @click="closeLink"><X :size="18" /></button>
        <h3>{{ tr('link_title') }}</h3>
        <label><span>{{ tr('link_url') }}</span><input ref="linkUrlInput" v-model="linkDialog.url" type="text" inputmode="url" placeholder="https://" autocomplete="off"></label>
        <label v-if="!linkDialog.hasSelection"><span>{{ tr('link_text') }}</span><input v-model="linkDialog.text" type="text" autocomplete="off"></label>
        <label class="rich-editor__check"><input v-model="linkDialog.blank" type="checkbox"><span>{{ tr('link_blank') }}</span></label>
        <label class="rich-editor__check"><input v-model="linkDialog.nofollow" type="checkbox"><span>{{ tr('link_nofollow') }}</span></label>
        <label class="rich-editor__check"><input v-model="linkDialog.sponsored" type="checkbox"><span>{{ tr('link_sponsored') }}</span></label>
        <label class="rich-editor__check"><input v-model="linkDialog.ugc" type="checkbox"><span>{{ tr('link_ugc') }}</span></label>
        <div class="rich-editor__dialog-actions">
          <button v-if="linkDialog.hasLink" type="button" class="admin-button is-danger" @click="removeLink">{{ tr('link_remove') }}</button>
          <button type="button" class="admin-button" @click="closeLink">{{ tr('cancel') }}</button>
          <button type="submit" class="admin-button is-primary">{{ tr('apply') }}</button>
        </div>
      </form>
    </div>

    <div v-if="imageDialog.open" class="rich-editor__modal" role="presentation" @mousedown.self="closeImage">
      <form class="rich-editor__dialog" role="dialog" aria-modal="true" :aria-label="tr('image_title')" @submit.prevent="insertFromUrl" @keydown.esc.stop="closeImage">
        <button type="button" class="admin-modal__close" :title="tr('close')" :aria-label="tr('close')" @click="closeImage"><X :size="18" /></button>
        <h3>{{ tr('image_title') }}</h3>
        <button type="button" class="admin-button is-primary" @click="insertFromLibrary"><FolderOpen :size="16" /> <span>{{ tr('image_library') }}</span></button>
        <p class="rich-editor__or">{{ tr('or') }}</p>
        <label><span>{{ tr('image_url') }}</span><input ref="imageUrlInput" v-model="imageDialog.url" type="text" inputmode="url" placeholder="https://" autocomplete="off"></label>
        <label><span>{{ tr('image_alt') }}</span><input v-model="imageDialog.alt" type="text" autocomplete="off"></label>
        <div class="rich-editor__dialog-actions">
          <button type="button" class="admin-button" @click="closeImage">{{ tr('cancel') }}</button>
          <button type="submit" class="admin-button is-primary" :disabled="imageDialog.url.trim() === ''">{{ tr('image_insert') }}</button>
        </div>
      </form>
    </div>

    <div v-if="anchorDialog.open" class="rich-editor__modal" role="presentation" @mousedown.self="closeAnchor">
      <form class="rich-editor__dialog" role="dialog" aria-modal="true" :aria-label="tr('anchor_title')" @submit.prevent="applyAnchor" @keydown.esc.stop="closeAnchor">
        <button type="button" class="admin-modal__close" :title="tr('close')" :aria-label="tr('close')" @click="closeAnchor"><X :size="18" /></button>
        <h3>{{ tr('anchor_title') }}</h3>
        <label><span>{{ tr('anchor_name') }}</span><input ref="anchorInput" v-model="anchorDialog.id" type="text" maxlength="64" autocomplete="off" placeholder="specs"><small>{{ tr('anchor_help') }}</small></label>
        <div class="rich-editor__dialog-actions">
          <button v-if="anchorDialog.has" type="button" class="admin-button is-danger" @click="removeAnchor">{{ tr('link_remove') }}</button>
          <button type="button" class="admin-button" @click="closeAnchor">{{ tr('cancel') }}</button>
          <button type="submit" class="admin-button is-primary" :disabled="!anchorValid()">{{ tr('apply') }}</button>
        </div>
      </form>
    </div>

    <div v-if="videoDialog.open" class="rich-editor__modal" role="presentation" @mousedown.self="closeVideo">
      <form class="rich-editor__dialog" role="dialog" aria-modal="true" :aria-label="tr('video_title')" @submit.prevent="insertVideo" @keydown.esc.stop="closeVideo">
        <button type="button" class="admin-modal__close" :title="tr('close')" :aria-label="tr('close')" @click="closeVideo"><X :size="18" /></button>
        <h3>{{ tr('video_title') }}</h3>
        <label><span>{{ tr('video_url') }}</span><input ref="videoInput" v-model="videoDialog.url" type="text" inputmode="url" placeholder="https://www.youtube.com/watch?v=…" autocomplete="off"><small>{{ videoDialog.url.trim() !== '' && videoEmbedUrl(videoDialog.url) === null ? tr('video_invalid') : tr('video_help') }}</small></label>
        <div class="rich-editor__dialog-actions">
          <button type="button" class="admin-button" @click="closeVideo">{{ tr('cancel') }}</button>
          <button type="submit" class="admin-button is-primary" :disabled="videoEmbedUrl(videoDialog.url) === null">{{ tr('image_insert') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
