import { t } from '../i18n';

export interface PickedMedia {
  id: number;
  url: string;
  alt: string;
  title: string;
  mime: string;
  width: number;
  height: number;
}

interface LibraryItem {
  id?: number;
  url?: string;
  alt_text?: string;
  title?: string;
  storage_key?: string;
  mime_type?: string;
  width?: number;
  height?: number;
}

const closeIcon = (): SVGSVGElement => {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  for (const [name, value] of Object.entries({ width: '18', height: '18', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) svg.setAttribute(name, value);
  for (const d of ['M18 6 6 18', 'm6 6 12 12']) {
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', d);
    svg.append(path);
  }
  return svg;
};

const el = <K extends keyof HTMLElementTagNameMap>(tag: K, className = '', attrs: Record<string, string> = {}): HTMLElementTagNameMap[K] => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
  return node;
};

/**
 * Opens the media library as a modal picker over already uploaded files.
 * Resolves with the chosen images (empty array when the dialog is closed without a choice).
 */
export function pickMedia(options: { multiple?: boolean } = {}): Promise<PickedMedia[]> {
  const multiple = options.multiple === true;
  return new Promise((resolve) => {
    const dialog = el('dialog', 'admin-dialog mc-picker', { 'aria-label': t('js_media_pick_title') });
    const form = el('div', 'mc-picker__body');
    const header = el('header', 'mc-picker__head');
    const title = el('strong');
    title.textContent = t('js_media_pick_title');
    const close = el('button', 'admin-modal__close', { type: 'button', 'aria-label': t('js_close'), title: t('js_close') });
    close.append(closeIcon());
    header.append(title, close);
    const search = el('input', '', { type: 'search', placeholder: t('js_media_pick_search'), 'aria-label': t('js_media_pick_search') });
    const grid = el('div', 'media-picker-grid');
    const footer = el('footer', 'mc-picker__foot');
    const count = el('span', 'mc-picker__count');
    const more = el('button', 'admin-button', { type: 'button' });
    more.textContent = t('js_media_pick_more');
    more.hidden = true;
    const confirm = el('button', 'admin-button is-primary', { type: 'button' });
    confirm.textContent = t('js_media_pick_confirm');
    confirm.disabled = true;
    footer.append(count, more, confirm);
    form.append(header, search, grid, footer);
    dialog.append(form);
    document.body.append(dialog);

    const selected = new Map<number, PickedMedia>();
    let page = 1;
    let total = 0;
    let loaded = 0;
    let done = false;
    let requestId = 0;

    const finish = (result: PickedMedia[]) => {
      if (done) return;
      done = true;
      if (dialog.open) dialog.close();
      dialog.remove();
      resolve(result);
    };
    const refresh = () => {
      confirm.disabled = selected.size === 0;
      count.textContent = selected.size > 0 ? t('js_media_pick_selected', { count: selected.size }) : '';
    };
    const note = (text: string) => {
      const p = el('p');
      p.textContent = text;
      grid.replaceChildren(p);
    };
    const tile = (item: LibraryItem): HTMLButtonElement => {
      const picked: PickedMedia = {
        id: Number(item.id ?? 0),
        url: String(item.url ?? ''),
        alt: String(item.alt_text ?? ''),
        title: String(item.title ?? ''),
        mime: String(item.mime_type ?? ''),
        width: Number(item.width ?? 0),
        height: Number(item.height ?? 0),
      };
      const button = el('button', 'mc-picker__tile', { type: 'button', 'aria-pressed': 'false' });
      const img = el('img', '', { loading: 'lazy', alt: picked.alt });
      img.src = picked.url;
      const label = el('span');
      label.textContent = picked.title || String(item.storage_key ?? '').split('/').pop() || '';
      button.append(img, label);
      button.addEventListener('click', () => {
        if (!multiple) {
          finish([picked]);
          return;
        }
        if (selected.has(picked.id)) {
          selected.delete(picked.id);
          button.setAttribute('aria-pressed', 'false');
        } else {
          selected.set(picked.id, picked);
          button.setAttribute('aria-pressed', 'true');
        }
        refresh();
      });
      return button;
    };
    const load = async (reset: boolean) => {
      const mine = ++requestId;
      if (reset) {
        page = 1;
        loaded = 0;
        note(t('js_loading'));
      }
      try {
        const response = await fetch(`/admin/media.json?q=${encodeURIComponent(search.value)}&page=${page}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error('media');
        const data = (await response.json()) as { items?: LibraryItem[]; total?: number };
        if (mine !== requestId) return;
        const items = data.items ?? [];
        total = Number(data.total ?? items.length);
        const images = items.filter((item) => String(item.mime_type ?? '').startsWith('image/'));
        loaded += items.length;
        if (reset) grid.replaceChildren();
        if (reset && images.length === 0) note(t('js_no_images'));
        else grid.append(...images.map(tile));
        more.hidden = loaded >= total || items.length === 0;
      } catch {
        if (mine === requestId) note(t('js_media_unavailable'));
      }
    };

    let timer = 0;
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => void load(true), 220);
    });
    more.addEventListener('click', () => {
      page += 1;
      void load(false);
    });
    confirm.addEventListener('click', () => finish([...selected.values()]));
    close.addEventListener('click', () => finish([]));
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      finish([]);
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) finish([]);
    });
    dialog.showModal();
    search.focus();
    void load(true);
  });
}
