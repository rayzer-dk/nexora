class CommerceSlider extends HTMLElement {
    connectedCallback() {
        const track = this.querySelector('[data-slider-track]');
        if (!(track instanceof HTMLElement)) return;
        const step = () => Math.max(240, Math.floor(track.clientWidth * 0.82));
        this.querySelector('[data-slider-prev]')?.addEventListener('click', () => track.scrollBy({ left: -step(), behavior: 'smooth' }));
        this.querySelector('[data-slider-next]')?.addEventListener('click', () => track.scrollBy({ left: step(), behavior: 'smooth' }));
        const update = () => {
            this.toggleAttribute('data-at-start', track.scrollLeft < 4);
            this.toggleAttribute('data-at-end', track.scrollLeft + track.clientWidth >= track.scrollWidth - 4);
        };
        track.addEventListener('scroll', update, { passive: true });
        new ResizeObserver(update).observe(track);
        update();
    }
}
if (!customElements.get('commerce-slider')) customElements.define('commerce-slider', CommerceSlider);
