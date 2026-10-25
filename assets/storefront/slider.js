// Product carousel: scrolls with the arrows or a swipe and goes round without a jump back to the start.
// The slides are repeated before and after the real ones; when the visitor scrolls into a copy, the track is moved by exactly one set
// (instantly, while nothing is moving), so the picture on the screen does not change. Add data-loop="off" to get a plain strip with ends.
class CommerceSlider extends HTMLElement {
    connectedCallback() {
        const track = this.querySelector('[data-slider-track]');
        if (!(track instanceof HTMLElement) || this.dataset.sliderBound === '1') return;
        this.dataset.sliderBound = '1';
        const step = () => Math.max(240, Math.floor(track.clientWidth * 0.82));
        let looping = false;
        let setWidth = 0;
        let settleTimer = 0;

        const build = () => {
            if (looping || this.dataset.loop === 'off') return;
            const originals = Array.from(track.children).filter((el) => el instanceof HTMLElement);
            if (originals.length < 2 || track.scrollWidth <= track.clientWidth + 8) return; // everything fits: nothing to go round
            const copy = (node) => {
                const clone = node.cloneNode(true);
                clone.setAttribute('aria-hidden', 'true');
                clone.setAttribute('inert', '');
                clone.dataset.sliderClone = '';
                clone.removeAttribute('id');
                clone.querySelectorAll('[id]').forEach((el) => el.removeAttribute('id'));
                return clone;
            };
            const before = originals.map(copy);
            const after = originals.map(copy);
            track.prepend(...before);
            track.append(...after);
            looping = true;
            this.dataset.looping = '';
            this.removeAttribute('data-at-start');
            this.removeAttribute('data-at-end');
            measure();
            const start = track.querySelector(':scope > :not([data-slider-clone])');
            if (start) jump(pos(start));
        };

        // Position of a slide inside the scrolled content, independent of which ancestor is the offset parent.
        const pos = (el) => el.getBoundingClientRect().left - track.getBoundingClientRect().left + track.scrollLeft;
        const measure = () => {
            const first = track.querySelector(':scope > :not([data-slider-clone])');
            const clones = track.querySelectorAll(':scope > [data-slider-clone]');
            const firstAfter = clones[clones.length / 2];
            setWidth = first && firstAfter ? pos(firstAfter) - pos(first) : 0;
        };

        const jump = (left) => {
            const previous = track.style.scrollBehavior;
            const snap = track.style.scrollSnapType;
            track.style.scrollBehavior = 'auto';
            track.style.scrollSnapType = 'none';
            track.scrollLeft = left;
            void track.offsetWidth; // apply before the snap comes back
            track.style.scrollSnapType = snap;
            track.style.scrollBehavior = previous;
        };

        const settle = () => {
            if (!looping || setWidth <= 0) return;
            const first = track.querySelector(':scope > :not([data-slider-clone])');
            const origin = first ? pos(first) : 0;
            if (track.scrollLeft < origin - setWidth / 2) jump(track.scrollLeft + setWidth);
            else if (track.scrollLeft > origin + setWidth / 2) jump(track.scrollLeft - setWidth);
        };

        const update = () => {
            if (looping) return;
            this.toggleAttribute('data-at-start', track.scrollLeft < 4);
            this.toggleAttribute('data-at-end', track.scrollLeft + track.clientWidth >= track.scrollWidth - 4);
        };

        // Native scrollBy + scroll-snap can occasionally settle on the same slide in Firefox.
        // Navigate to a measured slide instead, so each arrow selects a real destination.
        const navigate = (direction) => {
            build();
            if (!looping) {
                track.scrollBy({ left: direction * step(), behavior: 'smooth' });
                return;
            }
            const slides = Array.from(track.children).filter((el) => el instanceof HTMLElement);
            if (!slides.length) return;
            const left = track.scrollLeft;
            let current = 0;
            let nearest = Infinity;
            slides.forEach((slide, index) => {
                const distance = Math.abs(pos(slide) - left);
                if (distance < nearest) { nearest = distance; current = index; }
            });
            const stride = slides.length > 1 ? Math.abs(pos(slides[1]) - pos(slides[0])) : step();
            const distance = Math.max(1, Math.round(step() / Math.max(1, stride)));
            const target = slides[Math.max(0, Math.min(slides.length - 1, current + direction * distance))];
            if (target) track.scrollTo({ left: pos(target), behavior: 'smooth' });
        };
        this.querySelector('[data-slider-prev]')?.addEventListener('click', () => navigate(-1));
        this.querySelector('[data-slider-next]')?.addEventListener('click', () => navigate(1));
        track.addEventListener('scroll', () => {
            update();
            window.clearTimeout(settleTimer);
            settleTimer = window.setTimeout(settle, 140);
        }, { passive: true });
        new ResizeObserver(() => { if (looping) measure(); update(); }).observe(track);
        update();
        // Built on the first touch of the track or an arrow, and also at once when the page is idle, so the first swipe already goes round.
        track.addEventListener('pointerdown', build, { passive: true, once: true });
        const idle = window.requestIdleCallback || ((fn) => window.setTimeout(fn, 600));
        idle(build);
    }
}
if (!customElements.get('commerce-slider')) customElements.define('commerce-slider', CommerceSlider);
