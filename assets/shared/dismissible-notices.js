/**
 * Adds a close (x) button to every inline notice that stays visible on the page
 * (form errors, warnings, login errors). Notices mirrored into toasts are hidden and skipped.
 */
export function initDismissibleNotices(root = document) {
  const label = window.MC_I18N?.js_close || document.documentElement.dataset.closeLabel || '\u00d7';
  root.querySelectorAll('.admin-notice, .store-notice').forEach((notice) => {
    if (notice.classList.contains('is-toast-mirrored') || notice.querySelector(':scope > .notice-close')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'notice-close';
    button.setAttribute('aria-label', label);
    button.textContent = '×';
    button.addEventListener('click', () => { notice.hidden = true; notice.style.display = 'none'; });
    notice.classList.add('has-notice-close');
    notice.appendChild(button);
  });
}
