import { EditorState } from '@codemirror/state';
import { EditorView, drawSelection, highlightActiveLine, highlightActiveLineGutter, keymap, lineNumbers } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { HighlightStyle, bracketMatching, foldGutter, indentOnInput, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

/** Monokai palette, the same look as the OpenCart source view. */
const monokai = {
  background: '#272822',
  foreground: '#f8f8f2',
  selection: '#49483e',
  cursor: '#f8f8f0',
  line: '#3e3d32',
  comment: '#75715e',
  tag: '#f92672',
  attribute: '#a6e22e',
  string: '#e6db74',
  keyword: '#66d9ef',
  number: '#ae81ff',
};

const theme = EditorView.theme(
  {
    '&': { color: monokai.foreground, backgroundColor: monokai.background, fontSize: '13px' },
    '.cm-content': { caretColor: monokai.cursor, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', padding: '8px 0' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: monokai.cursor },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: monokai.selection },
    '.cm-activeLine': { backgroundColor: monokai.line },
    '.cm-gutters': { backgroundColor: '#1e1f1c', color: '#90908a', border: 'none' },
    '.cm-activeLineGutter': { backgroundColor: monokai.line, color: monokai.foreground },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { overflow: 'auto', maxHeight: '60vh', minHeight: '240px' },
    '.cm-matchingBracket, .cm-matchingTag': { backgroundColor: '#49483e', outline: '1px solid #75715e' },
  },
  { dark: true },
);

const highlight = HighlightStyle.define([
  { tag: [tags.tagName, tags.angleBracket], color: monokai.tag },
  { tag: tags.attributeName, color: monokai.attribute },
  { tag: [tags.attributeValue, tags.string], color: monokai.string },
  { tag: [tags.comment, tags.blockComment], color: monokai.comment, fontStyle: 'italic' },
  { tag: [tags.keyword, tags.operator], color: monokai.keyword },
  { tag: [tags.number, tags.bool, tags.atom], color: monokai.number },
  { tag: tags.content, color: monokai.foreground },
  { tag: tags.invalid, color: '#f8f8f0', backgroundColor: '#f92672' },
]);

export interface HtmlCodeEditor {
  getValue(): string;
  setValue(value: string): void;
  focus(): void;
  destroy(): void;
}

export function createHtmlCodeEditor(parent: HTMLElement, value: string, onChange: (value: string) => void, label: string): HtmlCodeEditor {
  const view = new EditorView({
    parent,
    state: EditorState.create({
      doc: value,
      extensions: [
        lineNumbers(),
        highlightActiveLineGutter(),
        foldGutter(),
        history(),
        drawSelection(),
        indentOnInput(),
        bracketMatching(),
        highlightActiveLine(),
        html({ autoCloseTags: true, matchClosingTags: true }),
        syntaxHighlighting(highlight),
        theme,
        EditorView.lineWrapping,
        EditorView.contentAttributes.of({ 'aria-label': label, spellcheck: 'false' }),
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) onChange(update.state.doc.toString());
        }),
      ],
    }),
  });
  return {
    getValue: () => view.state.doc.toString(),
    setValue: (next: string) => view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: next } }),
    focus: () => view.focus(),
    destroy: () => view.destroy(),
  };
}
