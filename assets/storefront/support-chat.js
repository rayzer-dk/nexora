// Website side of the Telegram support chat: a dialog with the conversation, a form and a light poll for the answers.
(() => {
  const dialog = document.querySelector('[data-support-chat]');
  if (!(dialog instanceof HTMLDialogElement)) return;
  const log = dialog.querySelector('[data-support-log]');
  const form = dialog.querySelector('[data-support-form]');
  const intro = dialog.querySelector('[data-support-intro]');
  const status = dialog.querySelector('[data-support-status]');
  const field = form?.querySelector('textarea[name="message"]');
  if (!(log instanceof HTMLElement) || !(form instanceof HTMLFormElement) || !(field instanceof HTMLTextAreaElement)) return;

  const pollUrl = dialog.dataset.poll || '/support/chat/poll';
  const sendUrl = dialog.dataset.send || '/support/chat/send';
  const seen = new Set();
  let lastId = 0;
  let timer = 0;
  let busy = false;

  const say = (text) => { if (status) { status.textContent = text; status.hidden = text === ''; } };

  const add = (message) => {
    if (seen.has(message.id)) return;
    seen.add(message.id);
    lastId = Math.max(lastId, message.id);
    const bubble = document.createElement('div');
    bubble.className = `support-chat__msg ${message.dir === 'in' ? 'is-mine' : 'is-staff'}`;
    const text = document.createElement('p');
    text.textContent = message.body;
    const time = document.createElement('time');
    const date = new Date(`${message.at.replace(' ', 'T')}Z`);
    time.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    bubble.append(text, time);
    log.append(bubble);
  };

  const scrollDown = () => { log.scrollTop = log.scrollHeight; };

  const poll = async () => {
    try {
      const response = await fetch(`${pollUrl}?after=${lastId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      const before = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
      (data.messages || []).forEach(add);
      if (data.started && intro) intro.hidden = true;
      if ((data.messages || []).length && before) scrollDown();
    } catch (_) { /* offline: the next tick tries again */ }
  };

  const start = () => { poll().then(scrollDown); window.clearInterval(timer); timer = window.setInterval(() => { if (!document.hidden) poll(); }, 4000); };
  const stop = () => window.clearInterval(timer);

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.closest('[data-cw-support]')) {
      if (!dialog.open) dialog.showModal();
      start();
      field.focus();
    } else if (target.closest('[data-support-close]') || target === dialog) {
      dialog.close();
    }
  });
  dialog.addEventListener('close', stop);

  field.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); form.requestSubmit(); }
  });
  field.addEventListener('input', () => { field.style.height = 'auto'; field.style.height = `${Math.min(field.scrollHeight, 120)}px`; });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = field.value.trim();
    if (text === '' || busy) return;
    busy = true;
    say('');
    const data = new FormData(form);
    data.set('message', text);
    data.set('page', location.href);
    try {
      const response = await fetch(sendUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      if (response.ok) {
        field.value = '';
        field.style.height = 'auto';
        if (intro) intro.hidden = true;
        await poll();
        scrollDown();
      } else {
        say(response.status === 429 ? (dialog.dataset.wait || '') : (dialog.dataset.error || ''));
      }
    } catch (_) {
      say(dialog.dataset.error || '');
    } finally {
      busy = false;
    }
  });
})();
