// Scroll story (sticky picture follows the step in view), video hero (lazy, polite autoplay with a pause button) and banner slider (swipe, arrows, dots, polite autoplay).
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const saveData = Boolean(navigator.connection && (navigator.connection.saveData || /(^|-)2g$/.test(navigator.connection.effectiveType || '')));

function initScrollStories() {
  document.querySelectorAll('[data-scroll-story]').forEach((story) => {
    const steps = [...story.querySelectorAll('[data-story-step]')];
    const images = [...story.querySelectorAll('[data-story-image]')];
    if (steps.length < 2 || !('IntersectionObserver' in window)) return;
    const show = (index) => {
      steps.forEach((step, i) => step.classList.toggle('is-active', i === index));
      images.forEach((img) => img.classList.toggle('is-active', Number(img.dataset.storyImage) === index));
    };
    // The step whose middle crosses the middle band of the viewport is the active one.
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) show(Number(entry.target.dataset.storyStep));
      });
    }, { rootMargin: '-45% 0px -45% 0px', threshold: 0 });
    steps.forEach((step) => observer.observe(step));
  });
}

function initVideoHeroes() {
  document.querySelectorAll('[data-video-hero]').forEach((hero) => {
    const video = hero.querySelector('video[data-video-src]');
    const toggle = hero.querySelector('[data-video-toggle]');
    if (!video) return;
    const wide = window.matchMedia('(min-width: 1024px)').matches;
    const allowed = !reduceMotion && !saveData && (wide || hero.dataset.videoMobile === '1');
    if (!allowed) return; // the poster picture stays
    const start = () => {
      if (!video.src) video.src = video.dataset.videoSrc || '';
      const played = video.play();
      if (played && typeof played.then === 'function') {
        played.then(() => {
          hero.classList.add('is-playing');
          hero.classList.remove('is-paused');
          if (toggle) toggle.hidden = false;
        }).catch(() => { /* autoplay refused: the poster stays */ });
      }
    };
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            if (!video.src || video.paused && !hero.classList.contains('is-paused')) start();
          } else if (!video.paused) {
            video.pause();
          }
        });
      }, { threshold: 0.25 });
      observer.observe(hero);
    } else {
      start();
    }
    toggle?.addEventListener('click', () => {
      const paused = !video.paused;
      if (paused) video.pause(); else video.play().catch(() => undefined);
      hero.classList.toggle('is-paused', paused);
      toggle.setAttribute('aria-label', toggle.dataset[paused ? 'labelPlay' : 'labelPause'] || '');
    });
  });
}

// Banner slider: the slides sit in a scroll-snap strip (swipe works natively); arrows and dots are added here. It changes slide by itself
// every data-interval seconds, but never when the visitor prefers reduced motion or saves data, and it stops while the pointer or the keyboard
// focus is inside, while the tab is hidden and while the slider is off screen. Slides hidden for the current device are skipped.
function initBannerSliders() {
  document.querySelectorAll('[data-banner-slider]').forEach((slider) => {
    const track = slider.querySelector('[data-banner-track]');
    const prev = slider.querySelector('[data-banner-prev]');
    const next = slider.querySelector('[data-banner-next]');
    const dotsBox = slider.querySelector('[data-banner-dots]');
    if (!(track instanceof HTMLElement)) return;
    const interval = Math.max(0, Number(slider.dataset.interval || 0)) * 1000;
    const label = slider.dataset.labelSlide || '';
    let slides = [];
    let dots = [];
    let active = 0;
    let timer = 0;
    let hovered = false;
    let visible = true;
    let touched = false;

    const collect = () => Array.from(track.children).filter((el) => el instanceof HTMLElement && el.offsetParent !== null);
    // Position of a slide inside the scrolled content, independent of which ancestor is the offset parent.
    const pos = (el) => el.getBoundingClientRect().left - track.getBoundingClientRect().left + track.scrollLeft;
    const indexNearest = () => {
      const left = track.scrollLeft;
      let best = 0;
      let bestDistance = Infinity;
      slides.forEach((slide, i) => {
        const distance = Math.abs(pos(slide) - left);
        if (distance < bestDistance) { best = i; bestDistance = distance; }
      });
      return best;
    };
    const mark = (index) => {
      active = index;
      slides.forEach((slide, i) => {
        slide.toggleAttribute('inert', i !== index); // links of the slides out of sight stay out of the tab order
        slide.setAttribute('aria-hidden', i === index ? 'false' : 'true');
      });
      dots.forEach((dot, i) => {
        if (i === index) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current');
      });
    };
    const go = (index, smooth = true) => {
      if (!slides.length) return;
      const target = (index + slides.length) % slides.length;
      track.scrollTo({ left: pos(slides[target]), behavior: smooth && !reduceMotion ? 'smooth' : 'auto' });
      mark(target);
    };
    const build = () => {
      slides = collect();
      const many = slides.length > 1;
      if (prev) prev.hidden = !many;
      if (next) next.hidden = !many;
      if (dotsBox) {
        dotsBox.hidden = !many;
        dotsBox.replaceChildren();
        dots = many ? slides.map((_, i) => {
          const dot = document.createElement('button');
          dot.type = 'button';
          dot.className = 'banner-slider__dot';
          dot.setAttribute('aria-label', label.replace('%number%', String(i + 1)));
          dot.addEventListener('click', () => { touched = true; go(i); });
          dotsBox.append(dot);
          return dot;
        }) : [];
      }
      mark(Math.min(active, Math.max(0, slides.length - 1)));
    };
    const stop = () => { window.clearInterval(timer); timer = 0; };
    const run = () => {
      stop();
      if (!interval || reduceMotion || saveData || touched || hovered || !visible || document.hidden || slides.length < 2) return;
      timer = window.setInterval(() => go(active + 1), interval);
    };

    prev?.addEventListener('click', () => { touched = true; stop(); go(active - 1); });
    next?.addEventListener('click', () => { touched = true; stop(); go(active + 1); });
    let scrollTimer = 0;
    track.addEventListener('scroll', () => {
      window.clearTimeout(scrollTimer);
      scrollTimer = window.setTimeout(() => { if (slides.length) mark(indexNearest()); }, 90);
    }, { passive: true });
    track.addEventListener('pointerdown', () => { touched = true; stop(); }, { passive: true });
    slider.addEventListener('mouseenter', () => { hovered = true; stop(); });
    slider.addEventListener('mouseleave', () => { hovered = false; run(); });
    slider.addEventListener('focusin', () => { hovered = true; stop(); });
    slider.addEventListener('focusout', () => { hovered = false; run(); });
    document.addEventListener('visibilitychange', run);
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((entries) => { visible = entries.some((entry) => entry.isIntersecting); run(); }, { threshold: 0.3 }).observe(slider);
    }
    if ('ResizeObserver' in window) {
      let size = track.clientWidth;
      new ResizeObserver(() => {
        if (track.clientWidth === size) return;
        size = track.clientWidth;
        build();
        go(active, false);
      }).observe(track);
    }
    build();
    run();
  });
}

initScrollStories();
initVideoHeroes();
initBannerSliders();
