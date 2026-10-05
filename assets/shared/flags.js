// Small inline flags for language tabs (no network, no emoji: emoji flags do not render on Windows).
const H = (...c) => ({ h: c });
const V = (...c) => ({ v: c });
const FLAGS = {
  UA: H('#0057b7', '#ffd700'),
  RU: H('#ffffff', '#0039a6', '#d52b1e'),
  DE: H('#000000', '#dd0000', '#ffce00'),
  NL: H('#ae1c28', '#ffffff', '#21468b'),
  PL: H('#ffffff', '#dc143c'),
  FR: V('#0055a4', '#ffffff', '#ef4135'),
  IT: V('#009246', '#ffffff', '#ce2b37'),
  RO: V('#002b7f', '#fcd116', '#ce1126'),
  BG: H('#ffffff', '#00966e', '#d62612'),
  HU: H('#cd2a3e', '#ffffff', '#436f4d'),
  AT: H('#ed2939', '#ffffff', '#ed2939'),
  LT: H('#fdb913', '#006a44', '#c1272d'),
  EE: H('#0072ce', '#000000', '#ffffff'),
  LV: H('#9e3039', '#ffffff', '#9e3039'),
  CZ: H('#ffffff', '#d7141a'),
  SK: H('#ffffff', '#0b4ea2', '#ee1c25'),
  ES: H('#aa151b', '#f1bf00', '#aa151b'),
  PT: V('#046a38', '#da291c'),
  SE: H('#006aa7'),
  DK: H('#c8102e'),
  FI: H('#ffffff'),
  NO: H('#ba0c2f'),
  GR: H('#0d5eaf', '#ffffff', '#0d5eaf', '#ffffff', '#0d5eaf'),
  TR: H('#e30a17'),
  KZ: H('#00afca'),
  MD: V('#0046ae', '#ffcc00', '#cc092f'),
  RS: H('#c6363c', '#0c4076', '#ffffff'),
  HR: H('#ff0000', '#ffffff', '#171796'),
  SI: H('#ffffff', '#0000ff', '#ff0000'),
  BY: H('#cf101a', '#ffffff'),
};
const LANG_TO_COUNTRY = { uk: 'UA', ru: 'RU', de: 'DE', nl: 'NL', pl: 'PL', fr: 'FR', it: 'IT', ro: 'RO', bg: 'BG', hu: 'HU', lt: 'LT', et: 'EE', lv: 'LV', cs: 'CZ', sk: 'SK', es: 'ES', pt: 'PT', sv: 'SE', da: 'DK', fi: 'FI', nb: 'NO', no: 'NO', el: 'GR', tr: 'TR', kk: 'KZ', sr: 'RS', hr: 'HR', sl: 'SI', be: 'BY' };

function union(us) {
  const red = us ? '#b22234' : '#c8102e';
  const blue = us ? '#3c3b6e' : '#012169';
  if (us) {
    const stripes = Array.from({ length: 7 }, (_, i) => `<rect y="${i * 2 * 5.4}" width="30" height="5.4" fill="${red}"/>`).join('');
    return `<rect width="30" height="20" fill="#fff"/>${stripes}<rect width="13" height="10.8" fill="${blue}"/>`;
  }
  return `<rect width="30" height="20" fill="${blue}"/><path d="M0 0l30 20M30 0L0 20" stroke="#fff" stroke-width="4"/><path d="M0 0l30 20M30 0L0 20" stroke="${red}" stroke-width="1.6"/><path d="M15 0v20M0 10h30" stroke="#fff" stroke-width="6"/><path d="M15 0v20M0 10h30" stroke="${red}" stroke-width="3.4"/>`;
}

function body(country) {
  if (country === 'GB') return union(false);
  if (country === 'US') return union(true);
  const def = FLAGS[country];
  if (!def) return null;
  if (def.h) {
    const n = def.h.length;
    return def.h.map((c, i) => `<rect y="${(20 / n) * i}" width="30" height="${20 / n + 0.2}" fill="${c}"/>`).join('');
  }
  const n = def.v.length;
  return def.v.map((c, i) => `<rect x="${(30 / n) * i}" width="${30 / n + 0.2}" height="20" fill="${c}"/>`).join('');
}

/** Data URI of the flag for a locale code like uk-UA / en-US / de; null when unknown. */
export function flagFor(code) {
  const [lang, region] = String(code || '').replace('_', '-').split('-');
  const country = (lang || '').toLowerCase() === 'en' ? 'GB' : (region || '').toUpperCase() || LANG_TO_COUNTRY[(lang || '').toLowerCase()] || '';
  const inner = body(country);
  if (!inner) return null;
  return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20">${inner}</svg>`)}`;
}

/** Put a flag in front of the language code of every language tab on the page. */
export function decorateLangTabs(root = document) {
  root.querySelectorAll('.lang-tabs__tab[lang]').forEach((tab) => {
    if (tab.querySelector('.lang-flag')) return;
    const src = flagFor(tab.getAttribute('lang'));
    if (!src) return;
    const img = document.createElement('img');
    img.className = 'lang-flag';
    img.src = src;
    img.alt = '';
    img.width = 21;
    img.height = 14;
    tab.prepend(img);
    tab.querySelector('.lang-tabs__code')?.classList.add('is-flagged');
  });
}
