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

const cobalt: Palette = { dark: true, background: '#193549', gutter: '#15232d', gutterText: '#5d7e99', foreground: '#ffffff', selection: '#0050a4', cursor: '#ffc600', line: '#1f4662', comment: '#0088ff', tag: '#ff9d00', attribute: '#ffc600', string: '#3ad900', keyword: '#ff9d00', number: '#ff628c' };
const materialDark: Palette = { dark: true, background: '#263238', gutter: '#1e282d', gutterText: '#546e7a', foreground: '#eeffff', selection: '#314549', cursor: '#ffcc00', line: '#2c3b41', comment: '#546e7a', tag: '#f07178', attribute: '#ffcb6b', string: '#c3e88d', keyword: '#c792ea', number: '#f78c6c' };
const materialLighter: Palette = { dark: false, background: '#fafafa', gutter: '#f0f0f0', gutterText: '#b0bec5', foreground: '#546e7a', selection: '#cceae7', cursor: '#272727', line: '#f3f6f7', comment: '#b0bec5', tag: '#e53935', attribute: '#f6a434', string: '#91b859', keyword: '#7c4dff', number: '#f76d47' };
const ayuDark: Palette = { dark: true, background: '#0b0e14', gutter: '#0a0c11', gutterText: '#3e4b59', foreground: '#bfbdb6', selection: '#1a2a3a', cursor: '#e6b450', line: '#10141c', comment: '#5c6773', tag: '#39bae6', attribute: '#ffb454', string: '#aad94c', keyword: '#ff8f40', number: '#d2a6ff' };
const ayuLight: Palette = { dark: false, background: '#fafafa', gutter: '#f0f0f0', gutterText: '#abb0b6', foreground: '#5c6166', selection: '#d1e4f4', cursor: '#ff9940', line: '#f3f4f5', comment: '#abb0b6', tag: '#55b4d4', attribute: '#f2ae49', string: '#86b300', keyword: '#fa8d3e', number: '#a37acc' };
const monokaiPro: Palette = { dark: true, background: '#2d2a2e', gutter: '#221f22', gutterText: '#727072', foreground: '#fcfcfa', selection: '#5b595c', cursor: '#fcfcfa', line: '#363337', comment: '#727072', tag: '#ff6188', attribute: '#a9dc76', string: '#ffd866', keyword: '#78dce8', number: '#ab9df2' };
const synthwave: Palette = { dark: true, background: '#262335', gutter: '#1f1d2c', gutterText: '#6d77b3', foreground: '#ffffff', selection: '#463465', cursor: '#ff7edb', line: '#2f2b43', comment: '#848bbd', tag: '#fede5d', attribute: '#fe4450', string: '#ff8b39', keyword: '#36f9f6', number: '#f97e72' };
const catppuccinMocha: Palette = { dark: true, background: '#1e1e2e', gutter: '#181825', gutterText: '#6c7086', foreground: '#cdd6f4', selection: '#45475a', cursor: '#f5e0dc', line: '#313244', comment: '#6c7086', tag: '#f38ba8', attribute: '#f9e2af', string: '#a6e3a1', keyword: '#cba6f7', number: '#fab387' };
const catppuccinLatte: Palette = { dark: false, background: '#eff1f5', gutter: '#e6e9ef', gutterText: '#9ca0b0', foreground: '#4c4f69', selection: '#ccd0da', cursor: '#dc8a78', line: '#e6e9ef', comment: '#9ca0b0', tag: '#d20f39', attribute: '#df8e1d', string: '#40a02b', keyword: '#8839ef', number: '#fe640b' };
const rosePine: Palette = { dark: true, background: '#191724', gutter: '#16141f', gutterText: '#6e6a86', foreground: '#e0def4', selection: '#403d52', cursor: '#ebbcba', line: '#1f1d2e', comment: '#6e6a86', tag: '#eb6f92', attribute: '#f6c177', string: '#9ccfd8', keyword: '#c4a7e7', number: '#ebbcba' };
const everforest: Palette = { dark: true, background: '#2d353b', gutter: '#272e33', gutterText: '#859289', foreground: '#d3c6aa', selection: '#475258', cursor: '#d3c6aa', line: '#343f44', comment: '#859289', tag: '#e67e80', attribute: '#dbbc7f', string: '#a7c080', keyword: '#83c092', number: '#d699b6' };
const oneLight: Palette = { dark: false, background: '#fafafa', gutter: '#f0f0f0', gutterText: '#9d9d9f', foreground: '#383a42', selection: '#e5e5e6', cursor: '#526fff', line: '#f2f2f2', comment: '#a0a1a7', tag: '#e45649', attribute: '#986801', string: '#50a14f', keyword: '#a626a4', number: '#986801' };
const tomorrowNight: Palette = { dark: true, background: '#1d1f21', gutter: '#17181a', gutterText: '#707880', foreground: '#c5c8c6', selection: '#373b41', cursor: '#aeafad', line: '#282a2e', comment: '#969896', tag: '#cc6666', attribute: '#de935f', string: '#b5bd68', keyword: '#b294bb', number: '#de935f' };
const zenburn: Palette = { dark: true, background: '#3f3f3f', gutter: '#383838', gutterText: '#7f9f7f', foreground: '#dcdccc', selection: '#5f5f5f', cursor: '#8faf9f', line: '#4a4a4a', comment: '#7f9f7f', tag: '#cc9393', attribute: '#efef8f', string: '#cc9393', keyword: '#f0dfaf', number: '#8cd0d3' };
const githubDark: Palette = { dark: true, background: '#0d1117', gutter: '#010409', gutterText: '#6e7681', foreground: '#e6edf3', selection: '#264f78', cursor: '#e6edf3', line: '#161b22', comment: '#8b949e', tag: '#7ee787', attribute: '#79c0ff', string: '#a5d6ff', keyword: '#ff7b72', number: '#79c0ff' };
const panda: Palette = { dark: true, background: '#292a2b', gutter: '#232425', gutterText: '#676b79', foreground: '#e6e6e6', selection: '#3c3d3f', cursor: '#ff75b5', line: '#323334', comment: '#676b79', tag: '#ff2c6d', attribute: '#ffb86c', string: '#19f9d8', keyword: '#45a9f9', number: '#ffb86c' };
const shadesOfPurple: Palette = { dark: true, background: '#2d2b55', gutter: '#26244b', gutterText: '#7b7aa6', foreground: '#ffffff', selection: '#3a3873', cursor: '#fad000', line: '#34325f', comment: '#b362ff', tag: '#9effff', attribute: '#f8d000', string: '#a5ff90', keyword: '#ff9d00', number: '#ff628c' };
const darcula: Palette = { dark: true, background: '#2b2b2b', gutter: '#313335', gutterText: '#606366', foreground: '#a9b7c6', selection: '#214283', cursor: '#bbbbbb', line: '#323232', comment: '#808080', tag: '#e8bf6a', attribute: '#bababa', string: '#6a8759', keyword: '#cc7832', number: '#6897bb' };
const gruvboxLight: Palette = { dark: false, background: '#fbf1c7', gutter: '#ebdbb2', gutterText: '#928374', foreground: '#3c3836', selection: '#d5c4a1', cursor: '#3c3836', line: '#f2e5bc', comment: '#928374', tag: '#9d0006', attribute: '#b57614', string: '#79740e', keyword: '#8f3f71', number: '#8f3f71' };
const nordLight: Palette = { dark: false, background: '#eceff4', gutter: '#e5e9f0', gutterText: '#9aa5b8', foreground: '#2e3440', selection: '#d8dee9', cursor: '#2e3440', line: '#e5e9f0', comment: '#9aa5b8', tag: '#5e81ac', attribute: '#5e81ac', string: '#a3be8c', keyword: '#81a1c1', number: '#b48ead' };
export const CODE_THEMES = { monokai, monokaiPro, dracula, oneDark, nord, tokyoNight, nightOwl, gruvbox, solarizedDark, cobalt, materialDark, ayuDark, synthwave, catppuccinMocha, rosePine, everforest, tomorrowNight, zenburn, githubDark, panda, shadesOfPurple, darcula, github, visualStudioLight, atomLight, solarizedLight, materialLighter, ayuLight, catppuccinLatte, oneLight, gruvboxLight, nordLight } as const;
export type CodeThemeName = keyof typeof CODE_THEMES;

/** Names shown in the theme list (dark themes first, then the light ones). */
export const CODE_THEME_LABELS: Record<CodeThemeName, string> = {
  monokai: 'Monokai', dracula: 'Dracula', oneDark: 'One Dark', nord: 'Nord', tokyoNight: 'Tokyo Night', nightOwl: 'Night Owl', gruvbox: 'Gruvbox', solarizedDark: 'Solarized Dark',
  github: 'GitHub Light', visualStudioLight: 'VS Light', atomLight: 'Atom Light', solarizedLight: 'Solarized Light',
  monokaiPro: 'Monokai Pro', cobalt: 'Cobalt', materialDark: 'Material Dark', ayuDark: 'Ayu Dark', synthwave: 'SynthWave \'84', catppuccinMocha: 'Catppuccin Mocha', rosePine: 'Rosé Pine', everforest: 'Everforest',
  tomorrowNight: 'Tomorrow Night', zenburn: 'Zenburn', githubDark: 'GitHub Dark', panda: 'Panda', shadesOfPurple: 'Shades of Purple', darcula: 'Darcula',
  materialLighter: 'Material Lighter', ayuLight: 'Ayu Light', catppuccinLatte: 'Catppuccin Latte', oneLight: 'One Light', gruvboxLight: 'Gruvbox Light', nordLight: 'Nord Light',
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
