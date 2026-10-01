import { Extension } from '@tiptap/core';

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    blockAlign: {
      setBlockAlign: (align: 'left' | 'center' | 'right' | 'justify') => ReturnType;
      unsetBlockAlign: () => ReturnType;
    };
  }
}

const TYPES = ['paragraph', 'heading', 'image'];

/**
 * Paragraph alignment stored as the legacy `align` attribute: unlike `style`/`class` it survives the
 * server-side HTML sanitizer, so the alignment chosen in the editor is what visitors see.
 */
export const BlockAlign = Extension.create({
  name: 'blockAlign',
  addGlobalAttributes() {
    return [
      {
        types: TYPES,
        attributes: {
          align: {
            default: null,
            parseHTML: (element) => {
              const value = element.getAttribute('align');
              return value !== null && ['left', 'center', 'right', 'justify'].includes(value) ? value : null;
            },
            renderHTML: (attributes) => (attributes.align ? { align: attributes.align as string } : {}),
          },
        },
      },
    ];
  },
  addCommands() {
    return {
      setBlockAlign:
        (align) =>
        ({ commands }) =>
          TYPES.map((type) => commands.updateAttributes(type, { align })).some(Boolean),
      unsetBlockAlign:
        () =>
        ({ commands }) =>
          TYPES.map((type) => commands.resetAttributes(type, 'align')).some(Boolean),
    };
  },
});
