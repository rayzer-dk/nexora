(() => {
  'use strict';

  const storageKey = 'mc_consent_v1';
  const categories = ['preferences', 'analytics', 'marketing'];

  const subjectKey = 'mc_consent_subject_v1';
  const subjectId = () => {
    try {
      let value = localStorage.getItem(subjectKey);
      if (!value) {
        value = globalThis.crypto?.randomUUID?.() || `anon-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        localStorage.setItem(subjectKey, value);
      }
      return value;
    } catch (_) {
      return `ephemeral-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }
  };

  const safeParse = value => {
    try { return JSON.parse(value); } catch (_) { return null; }
  };

  const readSaved = () => {
    try {
      const value = safeParse(localStorage.getItem(storageKey));
      if (!value || typeof value !== 'object') return null;
      const savedAt = Date.parse(value.saved_at || '');
      const maxAgeMs = 180 * 24 * 60 * 60 * 1000;
      if (!Number.isFinite(savedAt) || Date.now() - savedAt > maxAgeMs) {
        try { localStorage.removeItem(storageKey); } catch (_) {}
        return null;
      }
      return value;
    } catch (_) { return null; }
  };

  const current = () => {
    const saved = readSaved();
    return {
      necessary: true,
      preferences: Boolean(saved?.preferences),
      analytics: Boolean(saved?.analytics),
      marketing: Boolean(saved?.marketing),
      saved: Boolean(saved)
    };
  };

  const googleSignals = state => ({
    security_storage: 'granted',
    functionality_storage: state.preferences ? 'granted' : 'denied',
    personalization_storage: state.preferences ? 'granted' : 'denied',
    analytics_storage: state.analytics ? 'granted' : 'denied',
    ad_storage: state.marketing ? 'granted' : 'denied',
    ad_user_data: state.marketing ? 'granted' : 'denied',
    ad_personalization: state.marketing ? 'granted' : 'denied'
  });

  const notifyGoogle = state => {
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'update', googleSignals(state));
  };

  const activateScripts = state => {
    document.querySelectorAll('script[type="text/plain"][data-consent-category]').forEach(node => {
      const category = node.dataset.consentCategory;
      if (!state[category] || node.dataset.consentActivated === '1') return;

      const script = document.createElement('script');
      for (const attr of node.attributes) {
        if (attr.name === 'type' || attr.name === 'data-consent-category' || attr.name === 'data-consent-activated') continue;
        script.setAttribute(attr.name, attr.value);
      }
      script.textContent = node.textContent;
      node.dataset.consentActivated = '1';
      node.after(script);
    });
  };

  const persist = async state => {
    const record = {
      preferences: Boolean(state.preferences),
      analytics: Boolean(state.analytics),
      marketing: Boolean(state.marketing),
      saved_at: new Date().toISOString(),
      client_id: subjectId(),
      locale: document.documentElement.lang || 'uk-UA'
    };
    try { localStorage.setItem(storageKey, JSON.stringify(record)); } catch (_) {}
    notifyGoogle(record);
    activateScripts(record);
    document.documentElement.dataset.consentReady = '1';
    window.dispatchEvent(new CustomEvent('commerce:consent-changed', { detail: record }));

    try {
      await fetch('/privacy/consent', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify(record)
      });
    } catch (_) {
      // Consent remains effective locally; server receipt can be retried later.
    }
  };

  const banner = document.querySelector('[data-commerce-consent]');
  const state = current();
  notifyGoogle(state);
  activateScripts(state);

  if (!banner) return;
  if (!state.saved) banner.hidden = false;

  const setAll = value => persist({preferences: value, analytics: value, marketing: value});

  banner.querySelector('[data-consent-accept-all]')?.addEventListener('click', async () => {
    await setAll(true); banner.hidden = true;
  });
  banner.querySelector('[data-consent-reject-optional]')?.addEventListener('click', async () => {
    await setAll(false); banner.hidden = true;
  });
  banner.querySelector('[data-consent-save]')?.addEventListener('click', async () => {
    const selection = {};
    categories.forEach(category => {
      selection[category] = Boolean(banner.querySelector(`[name="consent_${category}"]`)?.checked);
    });
    await persist(selection); banner.hidden = true;
  });

  document.querySelectorAll('[data-consent-open]').forEach(button => {
    button.addEventListener('click', () => {
      const now = current();
      categories.forEach(category => {
        const input = banner.querySelector(`[name="consent_${category}"]`);
        if (input) input.checked = Boolean(now[category]);
      });
      banner.hidden = false;
      banner.querySelector('details')?.setAttribute('open', '');
      banner.querySelector('button, input')?.focus();
    });
  });
})();
