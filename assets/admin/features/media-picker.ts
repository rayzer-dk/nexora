import { lucideIconNode } from '../../shared/lucide-icons.js';
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

interface Folder {
  id: number;
  parent_id: number | null;
  name: string;
}

const STORAGE_KEY = 'mc_media_picker_folder';

const el = <K extends keyof HTMLElementTagNameMap>(tag: K, className = '', attrs: Record<string, string> = {}): HTMLElementTagNameMap[K] => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
  return node;
};

const readFolder = (): number | null => {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value !== null && /^\d+$/.test(value) ? Number(value) : null;
  } catch {
    return null;
  }
};
const writeFolder = (id: number | null): void => {
  try {
    if (id === null) localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, String(id));
  } catch {
    /* storage may be unavailable */
  }
};

/** A toolbar button that shows only an icon; the name appears as a tooltip on hover and for screen readers. */
const iconButton = (icon: string, label: string, className = ''): HTMLButtonElement => {
  const button = el('button', `mc-picker__tool ${className}`.trim(), { type: 'button', title: label, 'aria-label': label });
  button.append(lucideIconNode(icon, 18));
  return button;
};

/**
 * Opens the media library as a file manager: folders with a path and a way up, upload from the computer, a new folder,
 * search and a choice of images. The last opened folder is remembered. Resolves with the chosen images (empty when closed).
 */
export function pickMedia(options: { multiple?: boolean } = {}): Promise<PickedMedia[]> {
  const multiple = options.multiple === true;
  return new Promise((resolve) => {
    const dialog = el('dialog', 'admin-dialog mc-picker', { 'aria-label': t('js_media_pick_title') });
    const body = el('div', 'mc-picker__body');

    const header = el('header', 'mc-picker__head');
    const title = el('strong');
    title.textContent = t('js_media_pick_title');
    const close = iconButton('x', t('js_close'), 'admin-modal__close');
    header.append(title, close);

    const bar = el('div', 'mc-picker__bar');
    const up = iconButton('arrow-up', t('js_media_up'));
    const refresh = iconButton('refresh-cw', t('js_media_refresh'));
    const newFolder = iconButton('folder-plus', t('js_media_new_folder'));
    const upload = iconButton('upload', t('js_media_upload_pc'), 'is-primary');
    const search = el('input', 'mc-picker__search', { type: 'search', placeholder: t('js_media_pick_search'), 'aria-label': t('js_media_pick_search') });
    const fileInput = el('input', '', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif,.heic,.heif', multiple: 'multiple', hidden: '' });
    if (!multiple) fileInput.removeAttribute('multiple');
    bar.append(up, refresh, newFolder, upload, search, fileInput);

    const path = el('nav', 'mc-picker__path', { 'aria-label': t('js_media_pick_folder') });
    const folderForm = el('form', 'mc-picker__newfolder');
    folderForm.hidden = true;
    const folderName = el('input', '', { type: 'text', maxlength: '190', placeholder: t('js_media_folder_name'), 'aria-label': t('js_media_folder_name'), required: '' });
    const folderSave = el('button', 'admin-button is-primary', { type: 'submit' });
    folderSave.textContent = t('js_media_folder_create');
    folderForm.append(folderName, folderSave);

    const status = el('small', 'mc-picker__status');
    const grid = el('div', 'mc-picker__grid');
    const footer = el('footer', 'mc-picker__foot');
    const count = el('span', 'mc-picker__count');
    const more = el('button', 'admin-button', { type: 'button' });
    more.textContent = t('js_media_pick_more');
    more.hidden = true;
    const confirm = el('button', 'admin-button is-primary', { type: 'button' });
    confirm.textContent = t('js_media_pick_confirm');
    confirm.disabled = true;
    if (!multiple) confirm.hidden = true;
    footer.append(count, more, confirm);
    body.append(header, bar, path, folderForm, status, grid, footer);
    dialog.append(body);
    document.body.append(dialog);

    const selected = new Map<number, PickedMedia>();
    const known = new Map<number, PickedMedia>();
    let folders: Folder[] = [];
    let current: number | null = readFolder(); // null = the top level (files without a folder and the first-level folders)
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
    const updateSelection = () => {
      confirm.disabled = selected.size === 0;
      count.textContent = selected.size > 0 ? t('js_media_pick_selected', { count: selected.size }) : '';
    };
    const note = (text: string) => {
      const p = el('p', 'mc-picker__note');
      p.textContent = text;
      grid.replaceChildren(p);
    };
    const folderById = (id: number | null): Folder | undefined => folders.find((f) => f.id === id);

    const renderPath = () => {
      const crumbs: HTMLElement[] = [];
      const root = el('button', 'mc-picker__crumb', { type: 'button' });
      root.append(lucideIconNode('home', 14));
      const rootLabel = el('span');
      rootLabel.textContent = t('js_media_root');
      root.append(rootLabel);
      root.addEventListener('click', () => open(null));
      crumbs.push(root);
      const chain: Folder[] = [];
      let node = folderById(current);
      while (node && chain.length < 10) {
        chain.unshift(node);
        node = node.parent_id === null ? undefined : folderById(node.parent_id);
      }
      for (const folder of chain) {
        const sep = el('span', 'mc-picker__sep');
        sep.append(lucideIconNode('chevron-right', 14));
        const crumb = el('button', 'mc-picker__crumb', { type: 'button' });
        crumb.textContent = folder.name;
        crumb.addEventListener('click', () => open(folder.id));
        crumbs.push(sep, crumb);
      }
      path.replaceChildren(...crumbs);
      up.disabled = current === null;
    };

    const folderTile = (folder: Folder): HTMLButtonElement => {
      const button = el('button', 'mc-picker__folder', { type: 'button', title: folder.name });
      button.append(lucideIconNode('folder', 40));
      const label = el('span');
      label.textContent = folder.name;
      button.append(label);
      button.addEventListener('click', () => open(folder.id));
      return button;
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
      known.set(picked.id, picked);
      const name = picked.title || String(item.storage_key ?? '').split('/').pop() || '';
      const button = el('button', 'mc-picker__tile', { type: 'button', 'aria-pressed': 'false', title: name });
      const img = el('img', '', { loading: 'lazy', alt: picked.alt });
      img.src = picked.url;
      const label = el('span');
      label.textContent = name;
      button.append(img, label);
      if (selected.has(picked.id)) button.setAttribute('aria-pressed', 'true');
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
        updateSelection();
      });
      return button;
    };

    /** Files of the open folder, or every file that matches the search text. */
    const load = async (reset: boolean) => {
      const mine = ++requestId;
      const searching = search.value.trim() !== '';
      if (reset) {
        page = 1;
        loaded = 0;
        note(t('js_loading'));
      }
      const folderParam = searching ? '' : current === null ? 'none' : String(current);
      try {
        const response = await fetch(`/admin/media.json?q=${encodeURIComponent(search.value.trim())}&folder=${encodeURIComponent(folderParam)}&page=${page}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error('media');
        const data = (await response.json()) as { items?: LibraryItem[]; total?: number; folders?: Folder[] };
        if (mine !== requestId) return;
        folders = (data.folders ?? []).map((f) => ({ id: Number(f.id), parent_id: f.parent_id === null || f.parent_id === undefined ? null : Number(f.parent_id), name: String(f.name) }));
        if (current !== null && !folderById(current)) current = null;
        renderPath();
        const items = data.items ?? [];
        total = Number(data.total ?? items.length);
        const images = items.filter((item) => String(item.mime_type ?? '').startsWith('image/'));
        loaded += items.length;
        const nodes: HTMLElement[] = [];
        if (reset && !searching) {
          const parent = current === null ? null : (folderById(current)?.parent_id ?? null);
          if (current !== null) {
            const back = el('button', 'mc-picker__folder is-up', { type: 'button', title: t('js_media_up') });
            back.append(lucideIconNode('arrow-up', 40));
            const label = el('span');
            label.textContent = t('js_media_up');
            back.append(label);
            back.addEventListener('click', () => open(parent));
            nodes.push(back);
          }
          nodes.push(...folders.filter((f) => f.parent_id === current).map(folderTile));
        }
        nodes.push(...images.map(tile));
        if (reset) grid.replaceChildren(...nodes);
        else grid.append(...nodes);
        if (reset && nodes.length === 0) note(searching ? t('js_no_images') : t('js_media_empty_folder'));
        more.hidden = loaded >= total || items.length === 0;
      } catch {
        if (mine === requestId) note(t('js_media_unavailable'));
      }
    };

    const open = (id: number | null) => {
      current = id;
      writeFolder(id);
      search.value = '';
      void load(true);
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
    up.addEventListener('click', () => open(folderById(current)?.parent_id ?? null));
    refresh.addEventListener('click', () => void load(true));
    newFolder.addEventListener('click', () => {
      folderForm.hidden = !folderForm.hidden;
      if (!folderForm.hidden) folderName.focus();
    });
    folderForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const name = folderName.value.trim();
      if (name === '') return;
      const token = document.querySelector<HTMLMetaElement>('meta[name="mc-media-csrf"]')?.content ?? '';
      const form = new FormData();
      form.set('_csrf_token', token);
      form.set('name', name);
      if (current !== null) form.set('parent_id', String(current));
      try {
        const response = await fetch('/admin/media/folder.json', { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = (await response.json()) as { ok?: boolean; id?: number };
        if (!response.ok || !data.ok) throw new Error('folder');
        folderName.value = '';
        folderForm.hidden = true;
        status.textContent = '';
        open(Number(data.id));
      } catch {
        status.textContent = t('js_media_folder_failed');
      }
    });
    upload.addEventListener('click', () => fileInput.click());
    const sendFiles = async (files: FileList | File[]) => {
      const list = Array.from(files);
      if (list.length === 0) return;
      const token = document.querySelector<HTMLMetaElement>('meta[name="mc-media-csrf"]')?.content ?? '';
      const form = new FormData();
      form.set('_csrf_token', token);
      if (current !== null) form.set('folder_id', String(current));
      for (const file of list) form.append('files[]', file);
      status.textContent = t('js_loading');
      upload.disabled = true;
      try {
        const response = await fetch('/admin/media/upload.json', { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = (await response.json()) as { uploaded?: number[]; failed?: number };
        status.textContent = data.failed ? t('js_upload_error') : '';
        await load(true);
        const fresh = (data.uploaded ?? []).map((id) => known.get(id)).filter((p): p is PickedMedia => Boolean(p));
        if (!multiple && fresh[0]) finish([fresh[0]]);
        else if (multiple) {
          for (const p of fresh) selected.set(p.id, p);
          grid.querySelectorAll<HTMLButtonElement>('.mc-picker__tile').forEach((b) => {
            const img = b.querySelector('img');
            if (img && fresh.some((p) => p.url === img.getAttribute('src'))) b.setAttribute('aria-pressed', 'true');
          });
          updateSelection();
        }
      } catch {
        status.textContent = t('js_upload_error');
      } finally {
        upload.disabled = false;
        fileInput.value = '';
      }
    };
    fileInput.addEventListener('change', () => void sendFiles(fileInput.files ?? []));
    dialog.addEventListener('dragover', (event) => event.preventDefault());
    dialog.addEventListener('drop', (event) => {
      event.preventDefault();
      if (event.dataTransfer?.files?.length) void sendFiles(event.dataTransfer.files);
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
