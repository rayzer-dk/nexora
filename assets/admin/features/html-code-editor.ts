import { Compartment, EditorState } from '@codemirror/state';
import { EditorView, drawSelection, highlightActiveLine, highlightActiveLineGutter, keymap, lineNumbers } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { HighlightStyle, bracketMatching, foldGutter, indentOnInput, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

type Palette = typeof monokai;

/** Monokai palette, the same look as the OpenCart source view. */
const monokai = {
  dark: true,
  background: '#272822',
  gutter: '#1e1f1c',
  gutterText: '#90908a',
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

const github: Palette = {
  dark: false,
  background: '#ffffff',
  gutter: '#f6f8fa',
  gutterText: '#6e7781',
  foreground: '#24292f',
  selection: '#b6d6fd',
  cursor: '#24292f',
  line: '#f3f6fa',
  comment: '#6e7781',
  tag: '#116329',
  attribute: '#0550ae',
  string: '#0a3069',
  keyword: '#cf222e',
  number: '#0550ae',
};

const dracula: Palette = {
  dark: true,
  background: '#282a36',
  gutter: '#21222c',
  gutterText: '#6272a4',
  foreground: '#f8f8f2',
  selection: '#44475a',
  cursor: '#f8f8f0',
  line: '#343746',
  comment: '#6272a4',
  tag: '#ff79c6',
  attribute: '#50fa7b',
  string: '#f1fa8c',
  keyword: '#8be9fd',
  number: '#bd93f9',
};

const nord: Palette = { dark: true, background: '#2e3440', gutter: '#292e39', gutterText: '#616e88', foreground: '#d8dee9', selection: '#434c5e', cursor: '#d8dee9', line: '#3b4252', comment: '#616e88', tag: '#81a1c1', attribute: '#8fbcbb', string: '#a3be8c', keyword: '#88c0d0', number: '#b48ead' };
const oneDark: Palette = { dark: true, background: '#282c34', gutter: '#21252b', gutterText: '#636d83', foreground: '#abb2bf', selection: '#3e4451', cursor: '#528bff', line: '#2c313c', comment: '#5c6370', tag: '#e06c75', attribute: '#d19a66', string: '#98c379', keyword: '#56b6c2', number: '#d19a66' };
const solarizedDark: Palette = { dark: true, background: '#002b36', gutter: '#073642', gutterText: '#586e75', foreground: '#93a1a1', selection: '#073642', cursor: '#93a1a1', line: '#073642', comment: '#586e75', tag: '#268bd2', attribute: '#b58900', string: '#2aa198', keyword: '#859900', number: '#d33682' };
const solarizedLight: Palette = { dark: false, background: '#fdf6e3', gutter: '#eee8d5', gutterText: '#93a1a1', foreground: '#586e75', selection: '#eee8d5', cursor: '#586e75', line: '#f5efdc', comment: '#93a1a1', tag: '#268bd2', attribute: '#b58900', string: '#2aa198', keyword: '#859900', number: '#d33682' };
const gruvbox: Palette = { dark: true, background: '#282828', gutter: '#1d2021', gutterText: '#7c6f64', foreground: '#ebdbb2', selection: '#504945', cursor: '#ebdbb2', line: '#32302f', comment: '#928374', tag: '#fb4934', attribute: '#b8bb26', string: '#fabd2f', keyword: '#83a598', number: '#d3869b' };
const tokyoNight: Palette = { dark: true, background: '#1a1b26', gutter: '#16161e', gutterText: '#565f89', foreground: '#c0caf5', selection: '#283457', cursor: '#c0caf5', line: '#202233', comment: '#565f89', tag: '#f7768e', attribute: '#e0af68', string: '#9ece6a', keyword: '#7dcfff', number: '#ff9e64' };
const nightOwl: Palette = { dark: true, background: '#011627', gutter: '#010e1a', gutterText: '#4b6479', foreground: '#d6deeb', selection: '#1d3b53', cursor: '#80a4c2', line: '#0b2942', comment: '#637777', tag: '#7fdbca', attribute: '#addb67', string: '#ecc48d', keyword: '#c792ea', number: '#f78c6c' };
const visualStudioLight: Palette = { dark: false, background: '#ffffff', gutter: '#f3f3f3', gutterText: '#237893', foreground: '#000000', selection: '#add6ff', cursor: '#000000', line: '#f5f5f5', comment: '#008000', tag: '#800000', attribute: '#e50000', string: '#0000ff', keyword: '#0000ff', number: '#098658' };
const atomLight: Palette = { dark: false, background: '#fafafa', gutter: '#f0f0f0', gutterText: '#9d9d9f', foreground: '#383a42', selection: '#e5e5e6', cursor: '#526fff', line: '#f2f2f2', comment: '#a0a1a7', tag: '#e45649', attribute: '#986801', string: '#50a14f', keyword: '#0184bc', number: '#986801' };

export const CODE_THEMES = { monokai, dracula, oneDark, nord, tokyoNight, nightOwl, gruvbox, solarizedDark, github, visualStudioLight, atomLight, solarizedLight } as const;
export type CodeThemeName = keyof typeof CODE_THEMES;

/** Names shown in the theme list (dark themes first, then the light ones). */
export const CODE_THEME_LABELS: Record<CodeThemeName, string> = {
  monokai: 'Monokai', dracula: 'Dracula', oneDark: 'One Dark', nord: 'Nord', tokyoNight: 'Tokyo Night', nightOwl: 'Night Owl', gruvbox: 'Gruvbox', solarizedDark: 'Solarized Dark',
  github: 'GitHub Light', visualStudioLight: 'VS Light', atomLight: 'Atom Light', solarizedLight: 'Solarized Light',
};

const themeExtension = (name: CodeThemeName) => {
  const palette = CODE_THEMES[name];
  const view = EditorView.theme(
    {
      '&': { color: palette.foreground, backgroundColor: palette.background, fontSize: '13px' },
      '.cm-content': { caretColor: palette.cursor, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', padding: '8px 0' },
      '.cm-cursor, .cm-dropCursor': { borderLeftColor: palette.cursor },
      '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: palette.selection },
      '.cm-activeLine': { backgroundColor: palette.line },
      '.cm-gutters': { backgroundColor: palette.gutter, color: palette.gutterText, border: 'none' },
      '.cm-activeLineGutter': { backgroundColor: palette.line, color: palette.foreground },
      '&.cm-focused': { outline: 'none' },
      '.cm-scroller': { overflow: 'auto', maxHeight: '60vh', minHeight: '240px' },
      '.cm-matchingBracket, .cm-matchingTag': { backgroundColor: palette.selection, outline: `1px solid ${palette.comment}` },
    },
    { dark: palette.dark },
  );
  const highlight = HighlightStyle.define([
    { tag: [tags.tagName, tags.angleBracket], color: palette.tag },
    { tag: tags.attributeName, color: palette.attribute },
    { tag: [tags.attributeValue, tags.string], color: palette.string },
    { tag: [tags.comment, tags.blockComment], color: palette.comment, fontStyle: 'italic' },
    { tag: [tags.keyword, tags.operator], color: palette.keyword },
    { tag: [tags.number, tags.bool, tags.atom], color: palette.number },
    { tag: tags.content, color: palette.foreground },
    { tag: tags.invalid, color: '#f8f8f0', backgroundColor: '#f92672' },
  ]);
  return [view, syntaxHighlighting(highlight)];
};

const THEME_KEY = 'mc_code_theme';

/** The theme the admin chose last time (kept in the browser only). */
export function savedCodeTheme(): CodeThemeName {
  try {
    const value = localStorage.getItem(THEME_KEY);
    if (value !== null && value in CODE_THEMES) return value as CodeThemeName;
  } catch { /* storage blocked: the default theme */ }
  return 'monokai';
}

export interface HtmlCodeEditor {
  getValue(): string;
  setValue(value: string): void;
  focus(): void;
  setTheme(name: CodeThemeName): void;
  destroy(): void;
}

export function createHtmlCodeEditor(parent: HTMLElement, value: string, onChange: (value: string) => void, label: string, readOnly = false): HtmlCodeEditor {
  const themeSlot = new Compartment();
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
        themeSlot.of(themeExtension(savedCodeTheme())),
        EditorView.lineWrapping,
        ...(readOnly ? [EditorState.readOnly.of(true), EditorView.editable.of(false)] : []),
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
    setTheme: (name: CodeThemeName) => {
      try { localStorage.setItem(THEME_KEY, name); } catch { /* the choice lasts for this page only */ }
      view.dispatch({ effects: themeSlot.reconfigure(themeExtension(name)) });
    },
    destroy: () => view.destroy(),
  };
}

const readers = new WeakMap<HTMLElement, HtmlCodeEditor>();

/** Shows highlighted, read-only HTML inside a container (used by the e-mail template preview). */
export function highlightHtml(host: HTMLElement, code: string): void {
  const existing = readers.get(host);
  if (existing) {
    existing.setValue(code);
    return;
  }
  host.textContent = '';
  host.classList.add('rich-editor__code');
  readers.set(host, createHtmlCodeEditor(host, code, () => {}, 'HTML', true));
}
