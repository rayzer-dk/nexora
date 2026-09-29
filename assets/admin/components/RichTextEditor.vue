<script setup lang="ts">
import { t } from '../i18n';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import { Bold, Italic, Underline, Strikethrough, Heading2, Heading3, List, ListOrdered, Quote, Minus, Table2, Image as ImageIcon, Link2, RemoveFormatting, Code, Undo2, Redo2 } from '@lucide/vue';

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), { placeholder: t('vue.components.richtexteditor.pochnit_vvodyty_tekst') });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const editor = new Editor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({ link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener noreferrer' } } }),
    Image.configure({ allowBase64: false }),
    TableKit.configure({ table: { resizable: false } }),
    Placeholder.configure({ placeholder: props.placeholder }),
  ],
  editorProps: {
    attributes: { class: 'rich-editor__content', spellcheck: 'true' },
  },
  onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()),
});

watch(() => props.modelValue, (value) => {
  if (editor.getHTML() !== value) editor.commands.setContent(value, { emitUpdate: false });
});
onBeforeUnmount(() => editor.destroy());

// HTML source mode: edit the markup directly; the server sanitises it on save (commerce.rich_text sanitizer).
const sourceMode = ref(false);
const source = ref('');
const toggleSource = () => {
  if (!sourceMode.value) {
    source.value = editor.getHTML();
    sourceMode.value = true;
    return;
  }
  editor.commands.setContent(source.value, { emitUpdate: true });
  sourceMode.value = false;
};
const onSource = (event: Event) => {
  source.value = (event.target as HTMLTextAreaElement).value;
  emit('update:modelValue', source.value);
};
const setImage = () => {
  const url = window.prompt('URL', 'https://');
  if (url) editor.chain().focus().setImage({ src: url }).run();
};
const insertTable = () => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();

const setLink = () => {
  const previous = editor.getAttributes('link').href as string | undefined;
  const url = window.prompt('URL', previous ?? 'https://');
  if (url === null) return;
  if (url === '') editor.chain().focus().extendMarkRange('link').unsetLink().run();
  else editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};
</script>

<template>
  <div class="rich-editor">
    <div class="rich-editor__toolbar" role="toolbar" :aria-label="t('vue.components.richtexteditor.formatuvannia_tekstu')">
      <button type="button" :title="t('vue.components.richtexteditor.zhyrnyi')" :aria-label="t('vue.components.richtexteditor.zhyrnyi')" :class="{active: editor.isActive('bold')}" @click="editor.chain().focus().toggleBold().run()"><Bold :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.kursyv')" :aria-label="t('vue.components.richtexteditor.kursyv')" :class="{active: editor.isActive('italic')}" @click="editor.chain().focus().toggleItalic().run()"><Italic :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.pidkreslennia')" :aria-label="t('vue.components.richtexteditor.pidkreslennia')" :class="{active: editor.isActive('underline')}" @click="editor.chain().focus().toggleUnderline().run()"><Underline :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.strike')" :aria-label="t('vue.components.richtexteditor.strike')" :disabled="sourceMode" :class="{active: editor.isActive('strike')}" @click="editor.chain().focus().toggleStrike().run()"><Strikethrough :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.zaholovok_h2')" :aria-label="t('vue.components.richtexteditor.zaholovok_h2')" :class="{active: editor.isActive('heading', { level: 2 })}" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"><Heading2 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.heading3')" :aria-label="t('vue.components.richtexteditor.heading3')" :disabled="sourceMode" :class="{active: editor.isActive('heading', { level: 3 })}" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"><Heading3 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.markirovanyi_spysok')" :aria-label="t('vue.components.richtexteditor.markirovanyi_spysok')" @click="editor.chain().focus().toggleBulletList().run()"><List :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.numerovanyi_spysok')" :aria-label="t('vue.components.richtexteditor.numerovanyi_spysok')" @click="editor.chain().focus().toggleOrderedList().run()"><ListOrdered :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.posylannia')" :aria-label="t('vue.components.richtexteditor.posylannia')" @click="setLink"><Link2 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.quote')" :aria-label="t('vue.components.richtexteditor.quote')" :disabled="sourceMode" :class="{active: editor.isActive('blockquote')}" @click="editor.chain().focus().toggleBlockquote().run()"><Quote :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.rule')" :aria-label="t('vue.components.richtexteditor.rule')" :disabled="sourceMode" @click="editor.chain().focus().setHorizontalRule().run()"><Minus :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.table')" :aria-label="t('vue.components.richtexteditor.table')" :disabled="sourceMode" @click="insertTable"><Table2 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.image')" :aria-label="t('vue.components.richtexteditor.image')" :disabled="sourceMode" @click="setImage"><ImageIcon :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.clear')" :aria-label="t('vue.components.richtexteditor.clear')" :disabled="sourceMode" @click="editor.chain().focus().clearNodes().unsetAllMarks().run()"><RemoveFormatting :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.skasuvaty')" :aria-label="t('vue.components.richtexteditor.skasuvaty')" :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()"><Undo2 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.povtoryty')" :aria-label="t('vue.components.richtexteditor.povtoryty')" :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()"><Redo2 :size="16" /></button>
      <button type="button" class="rich-editor__source-toggle" :title="t('vue.components.richtexteditor.html')" :aria-label="t('vue.components.richtexteditor.html')" :aria-pressed="sourceMode" :class="{active: sourceMode}" @click="toggleSource"><Code :size="16" /> <span>HTML</span></button>
    </div>
    <textarea v-if="sourceMode" class="rich-editor__source" spellcheck="false" :aria-label="t('vue.components.richtexteditor.html')" :value="source" @input="onSource"></textarea>
    <EditorContent v-show="!sourceMode" :editor="editor" />
  </div>
</template>
