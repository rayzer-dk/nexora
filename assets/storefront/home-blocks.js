// Scroll story (sticky picture follows the step in view) and video hero (lazy, polite autoplay with a pause button).
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

initScrollStories();
initVideoHeroes();
