import './rich-editor.css';

document.addEventListener('DOMContentLoaded', async () => {
  const fields = Array.from(document.querySelectorAll<HTMLTextAreaElement>('textarea[data-rich-editor]'));
  if (fields.length === 0) return;

  const [{ createApp }, { default: RichTextEditor }] = await Promise.all([
    import('vue'),
    import('./components/RichTextEditor.vue'),
  ]);

  for (const field of fields) {
    if (field.dataset.richEditorMounted === '1') continue;
    field.dataset.richEditorMounted = '1';

    const mount = document.createElement('div');
    mount.className = 'rich-editor-mount';
    field.insertAdjacentElement('afterend', mount);
    field.hidden = true;

    createApp(RichTextEditor, {
      modelValue: field.value,
      placeholder: field.getAttribute('placeholder') || undefined,
      'onUpdate:modelValue': (value: string) => {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
      },
    }).mount(mount);
  }
});
