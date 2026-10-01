import { lucideIcon } from '../shared/lucide-icons.js';
const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
class CommerceQuantity extends HTMLElement {
    connectedCallback() {
        const input = this.querySelector('[data-qty-input]');
        const minus = this.querySelector('[data-qty-minus]');
        const plus = this.querySelector('[data-qty-plus]');
        if (!(input instanceof HTMLInputElement) || !(minus instanceof HTMLButtonElement) || !(plus instanceof HTMLButtonElement)) return;

        const normalize = () => {
            const min = Number(input.min || 1);
            const value = Number(input.value || min);
            input.value = String(Math.max(min, Number.isFinite(value) ? value : min));
        };

        minus.addEventListener('click', () => { normalize(); input.stepDown(); normalize(); input.dispatchEvent(new Event('change', { bubbles: true })); });
        plus.addEventListener('click', () => { normalize(); input.stepUp(); normalize(); input.dispatchEvent(new Event('change', { bubbles: true })); });
        input.addEventListener('change', normalize);
    }
}

class CommerceProductGallery extends HTMLElement {
    connectedCallback() {
        const main = this.querySelector('[data-gallery-main]');
        const thumbs = [...this.querySelectorAll('[data-gallery-thumb]')];
        const counter = this.querySelector('[data-gallery-counter]');
        const stage = this.querySelector('[data-gallery-stage]');
        const dialog = this.querySelector('[data-gallery-dialog]');
        if (!(main instanceof HTMLImageElement)) return;
        // Single-image products still open the viewer; a list of one keeps the code path uniform.
        const items = thumbs.length > 0
            ? thumbs.map((button) => ({ src: button.getAttribute('data-src') || '', srcset: button.getAttribute('data-srcset') || '', avif: button.getAttribute('data-avif-srcset') || '', sizes: button.getAttribute('data-sizes') || '', alt: button.getAttribute('data-alt') || '', thumb: button.getAttribute('data-thumb') || button.getAttribute('data-src') || '', full: button.getAttribute('data-full') || button.getAttribute('data-src') || '', video: button.getAttribute('data-video') || '', embed: button.getAttribute('data-embed') || '', title: button.getAttribute('data-title') || '' }))
            : [{ src: main.getAttribute('src') || '', srcset: main.getAttribute('srcset') || '', avif: (main.parentElement && main.parentElement.querySelector('source') ? main.parentElement.querySelector('source').getAttribute('srcset') : '') || '', sizes: main.getAttribute('sizes') || '', alt: main.alt, thumb: main.getAttribute('data-thumb') || main.getAttribute('src') || '', full: main.getAttribute('data-full') || main.getAttribute('src') || '', video: main.getAttribute('data-video') || '', embed: main.getAttribute('data-embed') || '', title: main.getAttribute('data-title') || '' }];
        const total = items.length;
        let index = Math.max(0, thumbs.findIndex((item) => item.classList.contains('is-active')));
        let lightbox = null;

        const openButton = this.querySelector('[data-gallery-open]');
        let player = null;
        const stopVideo = () => { player?.remove(); player = null; stage?.classList.remove('is-playing'); };
        // The player is created only after a click: until then the page loads the preview picture and nothing else.
        const playVideo = (item) => {
            if (!item.video || !item.embed || !(stage instanceof HTMLElement)) return;
            stopVideo();
            player = document.createElement('div');
            player.className = 'product-gallery__player';
            if (item.video === 'file') {
                const video = document.createElement('video');
                video.controls = true; video.autoplay = true; video.playsInline = true; video.preload = 'metadata';
                video.src = item.embed; video.poster = item.src;
                player.append(video);
            } else {
                const frame = document.createElement('iframe');
                frame.src = item.embed + (item.embed.includes('?') ? '&' : '?') + 'autoplay=1';
                frame.title = item.title || item.alt;
                frame.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
                frame.allowFullscreen = true;
                frame.referrerPolicy = 'strict-origin-when-cross-origin';
                player.append(frame);
            }
            stage.append(player);
            stage.classList.add('is-playing');
        };

        const show = (next, fromLightbox = false) => {
            index = (next + total) % total;
            const item = items[index];
            if (!item.src) return;
            stopVideo();
            if (stage instanceof HTMLElement) {
                stage.classList.toggle('is-video', Boolean(item.video));
                openButton?.setAttribute('aria-label', (item.video ? stage.dataset.playLabel : stage.dataset.openLabel) || '');
            }
            const avifSource = main.parentElement ? main.parentElement.querySelector('source[data-gallery-source]') : null;
            if (avifSource) {
                if (item.avif) { avifSource.setAttribute('srcset', item.avif); if (item.sizes) avifSource.setAttribute('sizes', item.sizes); }
                else { avifSource.removeAttribute('srcset'); avifSource.removeAttribute('sizes'); }
            }
            main.src = item.src;
            if (item.srcset) main.srcset = item.srcset; else main.removeAttribute('srcset');
            if (item.sizes) main.sizes = item.sizes; else main.removeAttribute('sizes');
            main.alt = item.alt;
            thumbs.forEach((button, i) => button.classList.toggle('is-active', i === index));
            thumbs[index]?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
            if (counter) counter.textContent = `${index + 1} / ${total}`;
            if (!fromLightbox) lightbox?.sync();
        };

        thumbs.forEach((button, i) => button.addEventListener('click', () => show(i)));
        this.querySelector('[data-gallery-prev]')?.addEventListener('click', () => show(index - 1));
        this.querySelector('[data-gallery-next]')?.addEventListener('click', () => show(index + 1));

        if (stage instanceof HTMLElement) {
            stage.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
                if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
            });
            if (total > 1) attachSwipe(stage, (dir) => show(index + dir));
        }

        if (dialog instanceof HTMLDialogElement) {
            lightbox = createLightbox(dialog, items, () => index, (i) => show(i, true));
            openButton?.addEventListener('click', () => { if (items[index].video) playVideo(items[index]); else lightbox.open(index); });
        } else if (openButton) {
            openButton.addEventListener('click', () => { if (items[index].video) playVideo(items[index]); });
        }
    }
}

/** Horizontal swipe on touch/pen: calls onSwipe(-1|1). Vertical scrolling is left to the browser. */
function attachSwipe(element, onSwipe) {
    let startX = 0; let startY = 0; let active = false; let moved = false;
    element.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse') return;
        active = true; moved = false; startX = event.clientX; startY = event.clientY;
    });
    element.addEventListener('pointermove', (event) => {
        if (!active) return;
        if (Math.abs(event.clientX - startX) > 12 && Math.abs(event.clientX - startX) > Math.abs(event.clientY - startY)) moved = true;
    });
    const finish = (event) => {
        if (!active) return;
        active = false;
        const dx = event.clientX - startX;
        if (moved && Math.abs(dx) > 40) {
            onSwipe(dx < 0 ? 1 : -1);
            // Swallow the click that follows a swipe so the viewer does not open.
            element.addEventListener('click', (e) => e.stopPropagation(), { capture: true, once: true });
        }
    };
    element.addEventListener('pointerup', finish);
    element.addEventListener('pointercancel', () => { active = false; });
}

/** Full-screen viewer: arrows, swipe, keyboard, thumbnails, counter and zoom (buttons, wheel, double click, pinch, drag). */
function createLightbox(dialog, items, getIndex, setIndex) {
    const image = dialog.querySelector('[data-gallery-dialog-image]');
    const stage = dialog.querySelector('[data-lightbox-stage]');
    const counter = dialog.querySelector('[data-lightbox-counter]');
    const thumbBox = dialog.querySelector('[data-lightbox-thumbs]');
    if (!(image instanceof HTMLImageElement) || !(stage instanceof HTMLElement)) return { open() {}, sync() {} };
    const total = items.length;
    let index = 0; let scale = 1; let x = 0; let y = 0;
    const MAX = 4;
    const thumbButtons = [];

    if (thumbBox instanceof HTMLElement) {
        items.forEach((item, i) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'gallery-lightbox__thumb';
            button.setAttribute('aria-label', t('js_photo_number', { number: i + 1 }));
            const img = document.createElement('img');
            img.src = item.thumb || item.src; img.alt = ''; img.loading = 'lazy'; img.width = 64; img.height = 64; img.decoding = 'async';
            button.appendChild(img);
            button.addEventListener('click', () => go(i));
            thumbBox.appendChild(button);
            thumbButtons.push(button);
        });
    }

    const apply = () => {
        image.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
        stage.classList.toggle('is-zoomed', scale > 1);
    };
    const reset = () => { scale = 1; x = 0; y = 0; apply(); };
    const clampPan = () => {
        const rect = stage.getBoundingClientRect();
        const maxX = Math.max(0, (image.offsetWidth * scale - rect.width) / 2);
        const maxY = Math.max(0, (image.offsetHeight * scale - rect.height) / 2);
        x = Math.max(-maxX, Math.min(maxX, x));
        y = Math.max(-maxY, Math.min(maxY, y));
    };
    const zoomTo = (next) => {
        scale = Math.max(1, Math.min(MAX, next));
        if (scale === 1) { x = 0; y = 0; }
        clampPan(); apply();
    };

    const render = () => {
        const item = items[index];
        reset();
        image.src = item.full || item.src;
        image.removeAttribute('srcset');
        image.removeAttribute('sizes');
        image.alt = item.alt;
        if (counter) counter.textContent = `${index + 1} / ${total}`;
        thumbButtons.forEach((button, i) => { button.classList.toggle('is-active', i === index); if (i === index) button.scrollIntoView({ block: 'nearest', inline: 'center' }); });
    };
    const go = (next) => { index = (next + total) % total; render(); setIndex(index); };

    dialog.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => go(index - 1));
    dialog.querySelector('[data-lightbox-next]')?.addEventListener('click', () => go(index + 1));
    dialog.querySelector('[data-lightbox-zoom-in]')?.addEventListener('click', () => zoomTo(scale + 0.75));
    dialog.querySelector('[data-lightbox-zoom-out]')?.addEventListener('click', () => zoomTo(scale - 0.75));
    dialog.querySelector('[data-gallery-close]')?.addEventListener('click', () => dialog.close());
    // Clicking the dark area (not the image or a control) closes the viewer.
    stage.addEventListener('click', (event) => { if (event.target === stage) dialog.close(); });

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') { event.preventDefault(); go(index - 1); }
        else if (event.key === 'ArrowRight') { event.preventDefault(); go(index + 1); }
        else if (event.key === '+' || event.key === '=') zoomTo(scale + 0.75);
        else if (event.key === '-') zoomTo(scale - 0.75);
        else if (event.key === '0') zoomTo(1);
    });
    dialog.addEventListener('close', () => { document.body.classList.remove('has-storefront-modal'); reset(); });

    stage.addEventListener('wheel', (event) => { event.preventDefault(); zoomTo(scale + (event.deltaY < 0 ? 0.5 : -0.5)); }, { passive: false });
    image.addEventListener('dblclick', () => zoomTo(scale > 1 ? 1 : 2.5));

    // Pointer gestures: drag to pan when zoomed, swipe to navigate when not, two fingers to pinch.
    const pointers = new Map();
    let pinchStart = 0; let pinchScale = 1; let dragX = 0; let dragY = 0; let swipeStartX = 0; let swipeDx = 0;
    stage.addEventListener('pointerdown', (event) => {
        if (event.target instanceof Element && event.target.closest('button')) return;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        stage.setPointerCapture?.(event.pointerId);
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinchStart = Math.hypot(a.x - b.x, a.y - b.y); pinchScale = scale;
        } else { dragX = event.clientX - x; dragY = event.clientY - y; swipeStartX = event.clientX; swipeDx = 0; }
    });
    stage.addEventListener('pointermove', (event) => {
        if (!pointers.has(event.pointerId)) return;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 2 && pinchStart > 0) {
            const [a, b] = [...pointers.values()];
            zoomTo(pinchScale * (Math.hypot(a.x - b.x, a.y - b.y) / pinchStart));
        } else if (pointers.size === 1) {
            if (scale > 1) { x = event.clientX - dragX; y = event.clientY - dragY; clampPan(); apply(); }
            else swipeDx = event.clientX - swipeStartX;
        }
    });
    const release = (event) => {
        const had = pointers.delete(event.pointerId);
        if (had && pointers.size === 0 && scale === 1 && Math.abs(swipeDx) > 50 && event.type === 'pointerup') go(index + (swipeDx < 0 ? 1 : -1));
        if (pointers.size < 2) pinchStart = 0;
        swipeDx = 0;
    };
    stage.addEventListener('pointerup', release);
    stage.addEventListener('pointercancel', release);

    return {
        open(start) {
            index = start;
            render();
            document.body.classList.add('has-storefront-modal');
            dialog.showModal();
            stage.focus?.({ preventScroll: true });
        },
        sync() { index = getIndex(); if (dialog.open) render(); },
    };
}

class CommerceStickyBuy extends HTMLElement {
    connectedCallback() {
        const selector = this.getAttribute('data-watch-selector');
        const target = selector ? document.querySelector(selector) : null;
        if (!target) return;

        const action = this.querySelector('[data-sticky-buy-action]');
        if (action instanceof HTMLButtonElement) {
            action.addEventListener('click', () => {
                const primary = target.querySelector('[data-primary-buy]');
                if (primary instanceof HTMLButtonElement && !primary.disabled) {
                    primary.click();
                    return;
                }
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }

        if (!('IntersectionObserver' in window)) return;
        const observer = new IntersectionObserver(([entry]) => {
            this.classList.toggle('is-visible', !entry.isIntersecting);
        }, { threshold: 0.05 });
        observer.observe(target);
    }
}


if (!customElements.get('commerce-quantity')) customElements.define('commerce-quantity', CommerceQuantity);
if (!customElements.get('commerce-product-gallery')) customElements.define('commerce-product-gallery', CommerceProductGallery);
if (!customElements.get('commerce-sticky-buy')) customElements.define('commerce-sticky-buy', CommerceStickyBuy);

function storefrontToast(message, type = 'success') {
    let stack = document.querySelector('[data-storefront-toast-stack]');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'storefront-toast-stack';
        stack.dataset.storefrontToastStack = '';
        document.body.appendChild(stack);
    }
    const item = document.createElement('div');
    item.className = `storefront-toast is-${type}`;
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const text = document.createElement('span');
    text.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'storefront-toast__close';
    close.innerHTML = lucideIcon('x', 16);
    close.setAttribute('aria-label', t('js_close'));
    close.addEventListener('click', () => item.remove());
    item.append(text, close);
    stack.appendChild(item);
    requestAnimationFrame(() => item.classList.add('is-visible'));
    window.setTimeout(() => {
        item.classList.remove('is-visible');
        window.setTimeout(() => item.remove(), 180);
    }, 4200);
}

function updateCartCount(count) {
    document.querySelectorAll('[data-cart-count]').forEach((badge) => {
        const value = Number(count || 0);
        badge.textContent = String(value);
        badge.hidden = value < 1;
    });
}

function initAjaxBuyActions() {
    document.querySelectorAll('form[data-buy-actions]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            const submitter = event.submitter;
            if (!(submitter instanceof HTMLButtonElement)) return;
            if (submitter.name === 'next' && submitter.value === 'checkout') return;
            if (!window.fetch || !window.FormData) return;

            event.preventDefault();
            if (form.dataset.busy === '1') return;
            form.dataset.busy = '1';
            const originalContent = Array.from(submitter.childNodes, (node) => node.cloneNode(true));
            submitter.disabled = true;
            submitter.classList.add('is-loading');
            submitter.textContent = t('js_add_ellipsis');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                let data = null;
                try { data = await response.json(); } catch (_) { data = null; }
                if (!response.ok || !data?.ok) {
                    throw new Error(data?.message || t('js_add_failed'));
                }
                updateCartCount(data.cart?.count || 0);
                document.dispatchEvent(new CustomEvent('mc:cart-updated', { detail: { count: Number(data.cart?.count || 0), source: 'add' } }));
                if (!window.matchMedia('(min-width: 1024px)').matches) storefrontToast(data.message || t('js_added_cart'), 'success');
                submitter.classList.add('is-complete');
                window.setTimeout(() => submitter.classList.remove('is-complete'), 700);
            } catch (error) {
                storefrontToast(error instanceof Error ? error.message : t('js_add_failed_retry'), 'error');
            } finally {
                submitter.disabled = false;
                submitter.classList.remove('is-loading');
                submitter.replaceChildren(...originalContent.map((node) => node.cloneNode(true)));
                form.dataset.busy = '0';
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initAjaxBuyActions);

function initSimpleAjaxForms() {
 document.querySelectorAll('form[data-stock-notify-form],form[data-inquiry-form]').forEach((form)=>{form.addEventListener('submit',async(event)=>{if(!window.fetch||!window.FormData)return;event.preventDefault();const button=form.querySelector('button[type="submit"]');if(button)button.disabled=true;try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||t('js_send_failed'));storefrontToast(data.message||t('js_sent'),'success');form.reset();form.closest('dialog')?.close();}catch(error){storefrontToast(error instanceof Error?error.message:t('js_send_failed'),'error');}finally{if(button)button.disabled=false;window.mcCaptchaReset?.(form);}});});
}
document.addEventListener('DOMContentLoaded', initSimpleAjaxForms);

/** Product detail tabs. Without JS every panel is visible and the tab bar is a list of in-page anchors. */
function initProductTabs() {
    const root = document.querySelector('[data-product-tabs]');
    if (!(root instanceof HTMLElement)) return;
    const tabs = [...root.querySelectorAll('[data-tab]')];
    const panels = [...root.querySelectorAll('[data-tab-panel]')];
    if (tabs.length === 0 || root.dataset.mode === 'list') return;
    root.classList.add('is-enhanced');

    const activate = (id, { focus = false, scroll = false, updateHash = true } = {}) => {
        const exists = panels.some((panel) => panel.getAttribute('data-tab-panel') === id);
        if (!exists) return false;
        tabs.forEach((tab) => {
            const on = tab.getAttribute('data-tab') === id;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (on && focus) tab.focus();
        });
        panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === id));
        if (updateHash) { try { history.replaceState(null, '', `#tab-${id}`); } catch { /* ignore */ } }
        if (scroll) {
            const bar = root.querySelector('.pp-tabs__bar');
            (bar || root).scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return true;
    };

    const fromHash = (scroll) => {
        const hash = decodeURIComponent(window.location.hash || '').replace(/^#/, '');
        if (!hash) return;
        if (hash.startsWith('tab-') && activate(hash.slice(4), { scroll, updateHash: false })) return;
        // A link to an element inside a hidden panel (#reviews, #questions) opens that panel first.
        const target = document.getElementById(hash);
        const panel = target?.closest('[data-tab-panel]');
        if (panel) {
            activate(panel.getAttribute('data-tab-panel') || '', { updateHash: false });
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', (event) => { event.preventDefault(); activate(tab.getAttribute('data-tab') || ''); });
        tab.addEventListener('keydown', (event) => {
            let next = -1;
            if (event.key === 'ArrowRight') next = (i + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            if (next >= 0) { event.preventDefault(); activate(tabs[next].getAttribute('data-tab') || '', { focus: true }); }
        });
    });
    tabs.forEach((tab) => { if (!tab.classList.contains('is-active')) tab.tabIndex = -1; });

    // Links elsewhere on the page that point at a tab (rating stars, delivery line, "all specs").
    document.querySelectorAll('[data-tab-link]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        activate(link.getAttribute('data-tab-link') || '', { scroll: true });
    }));
    window.addEventListener('hashchange', () => fromHash(true));
    fromHash(false);
}

/** Share popover: close on outside click / Escape / after choosing an item. */
function initSharePopovers() {
    const all = () => [...document.querySelectorAll('details[data-share-pop]')];
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Node ? event.target : null;
        all().forEach((pop) => { if (pop.open && target && (!pop.contains(target) || (target instanceof Element && target.closest('.share-pop__menu a, .share-pop__menu button')))) pop.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        all().forEach((pop) => { if (pop.open) { pop.open = false; pop.querySelector('summary')?.focus(); } });
    });
}

document.addEventListener('DOMContentLoaded', () => { initProductTabs(); initSharePopovers(); });
