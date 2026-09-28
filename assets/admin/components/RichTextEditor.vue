<script setup lang="ts">
import { t } from '../i18n';
import { onBeforeUnmount, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import { Undo2, Redo2 } from '@lucide/vue';

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
      <button type="button" :title="t('vue.components.richtexteditor.zhyrnyi')" :class="{active: editor.isActive('bold')}" @click="editor.chain().focus().toggleBold().run()"><strong>B</strong></button>
      <button type="button" :title="t('vue.components.richtexteditor.kursyv')" :class="{active: editor.isActive('italic')}" @click="editor.chain().focus().toggleItalic().run()"><em>I</em></button>
      <button type="button" :title="t('vue.components.richtexteditor.pidkreslennia')" :class="{active: editor.isActive('underline')}" @click="editor.chain().focus().toggleUnderline().run()"><u>U</u></button>
      <button type="button" :title="t('vue.components.richtexteditor.zaholovok_h2')" :class="{active: editor.isActive('heading', { level: 2 })}" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
      <button type="button" :title="t('vue.components.richtexteditor.markirovanyi_spysok')" @click="editor.chain().focus().toggleBulletList().run()">• List</button>
      <button type="button" :title="t('vue.components.richtexteditor.numerovanyi_spysok')" @click="editor.chain().focus().toggleOrderedList().run()">1. List</button>
      <button type="button" :title="t('vue.components.richtexteditor.posylannia')" @click="setLink">Link</button>
      <button type="button" :title="t('vue.components.richtexteditor.skasuvaty')" :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()"><Undo2 :size="16" /></button>
      <button type="button" :title="t('vue.components.richtexteditor.povtoryty')" :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()"><Redo2 :size="16" /></button>
    </div>
    <EditorContent :editor="editor" />
  </div>
</template>
