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
        const dialog = this.querySelector('[data-gallery-dialog]');
        const dialogImage = this.querySelector('[data-gallery-dialog-image]');
        if (!(main instanceof HTMLImageElement) || thumbs.length === 0) return;
        let index = Math.max(0, thumbs.findIndex((item) => item.classList.contains('is-active')));

        const show = (next) => {
            index = (next + thumbs.length) % thumbs.length;
            const button = thumbs[index];
            const src = button?.getAttribute('data-src');
            const alt = button?.getAttribute('data-alt') || '';
            const srcset = button?.getAttribute('data-srcset') || '';
            const sizes = button?.getAttribute('data-sizes') || '';
            if (!src) return;
            main.src = src;
            if (srcset) main.srcset = srcset; else main.removeAttribute('srcset');
            if (sizes) main.sizes = sizes; else main.removeAttribute('sizes');
            main.alt = alt;
            thumbs.forEach((item, i) => item.classList.toggle('is-active', i === index));
            button?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
            if (counter) counter.textContent = `${index + 1} / ${thumbs.length}`;
        };

        thumbs.forEach((button, i) => button.addEventListener('click', () => show(i)));
        this.querySelector('[data-gallery-prev]')?.addEventListener('click', () => show(index - 1));
        this.querySelector('[data-gallery-next]')?.addEventListener('click', () => show(index + 1));

        this.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') show(index - 1);
            if (event.key === 'ArrowRight') show(index + 1);
        });

        this.querySelector('[data-gallery-open]')?.addEventListener('click', () => {
            if (!(dialog instanceof HTMLDialogElement) || !(dialogImage instanceof HTMLImageElement)) return;
            dialogImage.src = main.currentSrc || main.src;
            dialogImage.alt = main.alt;
            dialog.showModal();
        });
        this.querySelector('[data-gallery-close]')?.addEventListener('click', () => {
            if (dialog instanceof HTMLDialogElement) dialog.close();
        });
    }
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
                storefrontToast(data.message || t('js_added_cart'), 'success');
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
 document.querySelectorAll('form[data-stock-notify-form],form[data-inquiry-form]').forEach((form)=>{form.addEventListener('submit',async(event)=>{if(!window.fetch||!window.FormData)return;event.preventDefault();const button=form.querySelector('button[type="submit"]');if(button)button.disabled=true;try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||t('js_send_failed'));storefrontToast(data.message||t('js_sent'),'success');form.reset();}catch(error){storefrontToast(error instanceof Error?error.message:t('js_send_failed'),'error');}finally{if(button)button.disabled=false;}});});
}
document.addEventListener('DOMContentLoaded', initSimpleAjaxForms);