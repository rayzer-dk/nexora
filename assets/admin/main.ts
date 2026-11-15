import './features/lang-tabs';
import './features/slug-autofill';

// A field of a language that is not on screen (a hidden [data-lang-panel]) gets its editor when that language is opened:
// a form with five languages starts one editor, not five.
const onScreen = (field: HTMLElement): boolean => !field.closest<HTMLElement>('[data-lang-panel]')?.hidden;

document.addEventListener('DOMContentLoaded', async () => {
  const fields = Array.from(document.querySelectorAll<HTMLTextAreaElement>('textarea[data-rich-editor]'));
  if (fields.length === 0) return;

  const [{ createApp, h, ref }, { default: RichTextEditor }] = await Promise.all([
    import('vue'),
    import('./components/RichTextEditor.vue'),
  ]);

  const mountVisible = (): void => fields.filter(onScreen).forEach(mount);
  document.addEventListener('lang-tabs:change', mountVisible);
  mountVisible();

  function mount(field: HTMLTextAreaElement): void {
    if (field.dataset.richEditorMounted === '1') return;
    field.dataset.richEditorMounted = '1';

    const host = document.createElement('div');
    host.className = 'rich-editor-mount';
    field.insertAdjacentElement('afterend', host);
    field.hidden = true;

    // The textarea stays the source of truth for the form. Text put into it from outside (AI drafts, translations,
    // "copy from the default language") reaches the visible editor through the input event; the editor's own
    // changes go back into the textarea without echoing.
    const value = ref(field.value);
    let fromEditor = false;
    const placeholder = field.getAttribute('placeholder');
    field.addEventListener('input', () => {
      if (!fromEditor && field.value !== value.value) value.value = field.value;
    });

    createApp({
      render: () => h(RichTextEditor, {
        modelValue: value.value,
        ...(placeholder ? { placeholder } : {}),
        'onUpdate:modelValue': (next: string) => {
          value.value = next;
          field.value = next;
          fromEditor = true;
          try {
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
          } finally {
            fromEditor = false;
          }
        },
      }),
    }).mount(host);
  }
});
