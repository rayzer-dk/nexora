import { Extension, Node, mergeAttributes } from '@tiptap/core';

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    inlineIcon: {
      insertInlineIcon: (name: string) => ReturnType;
    };
    blockAnchor: {
      setBlockAnchor: (id: string) => ReturnType;
      unsetBlockAnchor: () => ReturnType;
    };
    video: {
      setVideo: (src: string, title?: string) => ReturnType;
    };
  }
}

const ANCHOR_TYPES = ['paragraph', 'heading'];

/** A page anchor: the id of a heading or paragraph, so that a link can point at it as #id. */
export const BlockAnchor = Extension.create({
  name: 'blockAnchor',
  addGlobalAttributes() {
    return [
      {
        types: ANCHOR_TYPES,
        attributes: {
          id: {
            default: null,
            parseHTML: (element) => {
              const value = element.getAttribute('id');
              return value !== null && /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(value) ? value : null;
            },
            renderHTML: (attributes) => (attributes.id ? { id: attributes.id as string } : {}),
          },
        },
      },
    ];
  },
  addCommands() {
    return {
      setBlockAnchor:
        (id) =>
        ({ commands }) =>
          ANCHOR_TYPES.map((type) => commands.updateAttributes(type, { id })).some(Boolean),
      unsetBlockAnchor:
        () =>
        ({ commands }) =>
          ANCHOR_TYPES.map((type) => commands.resetAttributes(type, 'id')).some(Boolean),
    };
  },
});

/** Turns a YouTube or Vimeo address into its embeddable player address, or null when it is neither. */
export function videoEmbedUrl(input: string): string | null {
  const text = input.trim();
  let match = /^https:\/\/(?:www\.|m\.)?youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{6,20})/.exec(text);
  if (match) return `https://www.youtube-nocookie.com/embed/${match[1]}`;
  match = /^https:\/\/(?:www\.|m\.)?youtube\.com\/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{6,20})/.exec(text);
  if (match) return `https://www.youtube-nocookie.com/embed/${match[1]}`;
  match = /^https:\/\/(?:www\.)?youtu\.be\/([A-Za-z0-9_-]{6,20})/.exec(text);
  if (match) return `https://www.youtube-nocookie.com/embed/${match[1]}`;
  match = /^https:\/\/(?:www\.)?youtube\.com\/shorts\/([A-Za-z0-9_-]{6,20})/.exec(text);
  if (match) return `https://www.youtube-nocookie.com/embed/${match[1]}`;
  match = /^https:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?([0-9]{4,12})/.exec(text);
  if (match) return `https://player.vimeo.com/video/${match[1]}`;
  return null;
}

/** An embedded video player (iframe); the server keeps only YouTube and Vimeo player addresses. */
export const Video = Node.create({
  name: 'video',
  group: 'block',
  atom: true,
  draggable: true,
  addAttributes() {
    return { src: { default: null }, title: { default: null } };
  },
  parseHTML() {
    return [{ tag: 'iframe[src]', getAttrs: (element) => (videoEmbedUrl((element as HTMLElement).getAttribute('src') ?? '') ? null : false) }];
  },
  renderHTML({ HTMLAttributes }) {
    return ['iframe', mergeAttributes({ class: 'rte-video', loading: 'lazy', allowfullscreen: 'true' }, HTMLAttributes)];
  },
  addCommands() {
    return {
      setVideo:
        (src, title) =>
        ({ commands }) => {
          const embed = videoEmbedUrl(src);
          return embed ? commands.insertContent({ type: this.name, attrs: { src: embed, title: title || null } }) : false;
        },
    };
  },
});

let iconLibrary: Promise<Record<string, string>> | null = null;
const loadIcons = (): Promise<Record<string, string>> => {
  iconLibrary ??= window.fetch('/admin/icons.json', { credentials: 'same-origin' })
    .then((response) => (response.ok ? response.json() : { icons: {} }))
    .then((data: { icons?: Record<string, string> }) => data.icons ?? {})
    .catch(() => ({}));
  return iconLibrary;
};

/** A Lucide icon inside a line of text. Stored as <span class="mc-inline-icon" data-icon="name">; the pages draw the picture from the name. */
export const InlineIcon = Node.create({
  name: 'inlineIcon',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addAttributes() {
    return { name: { default: '' } };
  },
  parseHTML() {
    return [{ tag: 'span.mc-inline-icon[data-icon]', getAttrs: (element) => ({ name: (element as HTMLElement).getAttribute('data-icon') ?? '' }) }];
  },
  renderHTML({ node }) {
    return ['span', { class: 'mc-inline-icon', 'data-icon': String(node.attrs.name) }];
  },
  addNodeView() {
    return ({ node }) => {
      const dom = document.createElement('span');
      dom.className = 'mc-inline-icon';
      dom.setAttribute('data-icon', String(node.attrs.name));
      dom.contentEditable = 'false';
      void loadIcons().then((icons) => {
        const body = icons[String(node.attrs.name)];
        if (body) {
          const svg = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>', 'image/svg+xml').documentElement;
          if (svg.nodeName.toLowerCase() === 'svg') dom.replaceChildren(document.importNode(svg, true));
        }
      });
      return { dom };
    };
  },
  addCommands() {
    return {
      insertInlineIcon:
        (name) =>
        ({ commands }) =>
          /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) ? commands.insertContent({ type: this.name, attrs: { name } }) : false,
    };
  },
});
