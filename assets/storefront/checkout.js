const checkout = document.querySelector('[data-checkout]');

if (checkout) {
  const form = checkout.querySelector('[data-checkout-form]');
  const d = checkout.dataset;
  const i18n = {
    branchPlaceholder: d.i18nBranchPlaceholder || '',
    citiesFailed: d.i18nCitiesFailed || '',
    carrierUnavailable: d.i18nCarrierUnavailable || '',
    pointsFailed: d.i18nPointsFailed || '',
    promoFailed: d.i18nPromoFailed || '',
    promoRetry: d.i18nPromoRetry || '',
    required: d.i18nRequired || '',
    invalidEmail: d.i18nInvalidEmail || '',
    invalidPhone: d.i18nInvalidPhone || '',
    pickDelivery: d.i18nPickDelivery || '',
    pickPickup: d.i18nPickPickup || '',
    placing: d.i18nPlacing || '',
    noCity: d.i18nNoCity || '',
    searching: d.i18nSearching || '',
    errorsTitle: d.i18nErrorsTitle || '',
    pickRegion: d.i18nPickRegion || '',
  };
  const country = d.country || 'UA';
  const q = (selector, root = checkout) => root.querySelector(selector);
  const qa = (selector, root = checkout) => Array.from(root.querySelectorAll(selector));

  const carrierRadios = qa('input[name="carrier"]');
  const remotePanel = q('[data-delivery-remote]');
  const pickupPanel = q('[data-delivery-pickup]');
  const cityInput = q('[data-delivery-city]');
  const pointInput = q('[data-delivery-point]');
  const cityResults = q('[data-city-results]');
  const pointResults = q('[data-point-results]');
  const fallback = q('[data-delivery-fallback]');
  const manualInput = q('[data-delivery-manual]');
  const manualToggle = q('[data-delivery-manual-toggle]');
  const status = q('[data-delivery-status]');
  const deliveryError = q('[data-delivery-error]');
  const cityId = q('[data-city-id]');
  const cityName = q('[data-city-name]');
  const pointId = q('[data-point-id]');
  const pointName = q('[data-point-name]');
  const serviceType = q('[data-service-type]');

  let selectedCity = cityId?.value ? { id: cityId.value, name: cityName?.value || cityInput?.value || '' } : null;
  let cityTimer = null;
  let pointTimer = null;
  let cityRun = 0;
  let pointRun = 0;

  const currentCarrier = () => carrierRadios.find((radio) => radio.checked)?.value || '';
  const isPickup = () => currentCarrier() === 'self_pickup';
  const hasDelivery = () => carrierRadios.length > 0;

  const setStatus = (text) => { if (status) status.textContent = text || ''; };
  const clearResults = (box, input) => { if (!box) return; box.innerHTML = ''; box.hidden = true; input?.setAttribute('aria-expanded', 'false'); };
  const showManual = (show, focus = false) => {
    if (!fallback) return;
    fallback.hidden = !show;
    manualToggle?.setAttribute('aria-expanded', show ? 'true' : 'false');
    if (show && focus) manualInput?.focus();
  };
  const fetchJson = async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
  };
  const renderOptions = (box, input, items, select) => {
    box.innerHTML = '';
    for (const item of items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'delivery-result';
      button.setAttribute('role', 'option');
      button.textContent = item.address ? `${item.name} — ${item.address}` : item.name;
      button.addEventListener('click', () => { select(item); clearResults(box, input); });
      box.append(button);
    }
    box.hidden = items.length === 0;
    input.setAttribute('aria-expanded', items.length === 0 ? 'false' : 'true');
  };

  const syncPickup = () => {
    const radio = q('input[name="pickup_choice"]:checked');
    if (!radio || !pointId) return;
    pointId.value = radio.value;
    if (pointName) pointName.value = radio.dataset.pickupName || '';
    if (cityId) cityId.value = '';
    if (cityName) cityName.value = '';
  };
  const resetRemote = () => {
    selectedCity = null;
    if (cityInput) cityInput.value = '';
    if (pointInput) { pointInput.value = ''; pointInput.disabled = true; pointInput.placeholder = i18n.branchPlaceholder; }
    if (cityId) cityId.value = '';
    if (cityName) cityName.value = '';
    if (pointId) pointId.value = '';
    if (pointName) pointName.value = '';
    clearResults(cityResults, cityInput);
    clearResults(pointResults, pointInput);
    setStatus('');
  };
  const applyCarrier = (initial = false) => {
    const pickup = isPickup();
    carrierRadios.forEach((radio) => radio.closest('.ck-choice')?.classList.toggle('is-selected', radio.checked));
    if (remotePanel) remotePanel.hidden = pickup;
    if (pickupPanel) pickupPanel.hidden = !pickup;
    if (serviceType) serviceType.value = pickup ? 'store_pickup' : 'pickup_point';
    if (pickup) syncPickup(); else if (!initial) resetRemote();
    if (deliveryError) deliveryError.hidden = true;
    clearInvalid(q('[data-delivery-block]'));
  };

  const searchCities = async () => {
    const query = cityInput.value.trim();
    const run = ++cityRun;
    selectedCity = null;
    if (cityId) cityId.value = '';
    if (cityName) cityName.value = '';
    if (pointInput) { pointInput.disabled = true; pointInput.value = ''; }
    if (pointId) pointId.value = '';
    if (pointName) pointName.value = '';
    clearResults(pointResults, pointInput);
    if (query.length < 2) { clearResults(cityResults, cityInput); setStatus(''); return; }
    setStatus(i18n.searching);
    try {
      const data = await fetchJson(`/api/shipping/cities?provider=${encodeURIComponent(currentCarrier())}&country=${encodeURIComponent(country)}&q=${encodeURIComponent(query)}&limit=12`);
      if (run !== cityRun) return;
      const items = data.items || [];
      renderOptions(cityResults, cityInput, items, (item) => {
        selectedCity = item;
        cityInput.value = item.name;
        if (cityId) cityId.value = item.id || '';
        if (cityName) cityName.value = item.name || '';
        pointInput.disabled = false;
        pointInput.placeholder = i18n.branchPlaceholder;
        setStatus('');
        pointInput.focus();
        searchPoints();
      });
      if (items.length === 0) { setStatus(i18n.noCity); showManual(true); } else setStatus('');
    } catch (_) {
      if (run !== cityRun) return;
      clearResults(cityResults, cityInput);
      setStatus(i18n.citiesFailed);
      showManual(true);
    }
  };
  const searchPoints = async () => {
    if (!selectedCity) return;
    const query = pointInput.value.trim();
    const run = ++pointRun;
    if (pointId) pointId.value = '';
    if (pointName) pointName.value = '';
    try {
      const params = new URLSearchParams({ provider: currentCarrier(), country, city_id: selectedCity.id || '', city_name: selectedCity.name || '', q: query, limit: '20' });
      const data = await fetchJson(`/api/shipping/points?${params.toString()}`);
      if (run !== pointRun) return;
      const items = data.items || [];
      renderOptions(pointResults, pointInput, items, (item) => {
        const label = item.address ? `${item.name} — ${item.address}` : item.name;
        pointInput.value = label;
        if (pointId) pointId.value = item.id || '';
        if (pointName) pointName.value = label || '';
        setStatus('');
        clearInvalid(q('[data-delivery-block]'));
      });
      if (data.availability && data.availability !== 'available') { setStatus(data.notice || i18n.carrierUnavailable); showManual(true); }
      else if (items.length === 0 && query === '') { setStatus(i18n.pointsFailed); showManual(true); }
      else setStatus('');
    } catch (_) {
      if (run !== pointRun) return;
      clearResults(pointResults, pointInput);
      setStatus(i18n.pointsFailed);
      showManual(true);
    }
  };

  carrierRadios.forEach((radio) => radio.addEventListener('change', () => applyCarrier()));
  qa('input[name="pickup_choice"]').forEach((radio) => radio.addEventListener('change', syncPickup));
  cityInput?.addEventListener('input', () => { clearTimeout(cityTimer); cityTimer = setTimeout(searchCities, 250); });
  pointInput?.addEventListener('input', () => { clearTimeout(pointTimer); pointTimer = setTimeout(searchPoints, 250); });
  pointInput?.addEventListener('focus', () => { if (selectedCity && !pointInput.value && !pointId?.value) searchPoints(); });
  // "Enter the address manually" only ever opens the field: a city lookup may already have opened it on its own, and a toggle would then close it.
  manualToggle?.addEventListener('click', () => showManual(true, true));
  [cityInput, pointInput].forEach((input) => input?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { clearResults(cityResults, cityInput); clearResults(pointResults, pointInput); }
    if (event.key === 'ArrowDown') { const box = input === cityInput ? cityResults : pointResults; const first = box?.querySelector('button'); if (first) { event.preventDefault(); first.focus(); } }
  }));
  [cityResults, pointResults].forEach((box) => box?.addEventListener('keydown', (event) => {
    const buttons = Array.from(box.querySelectorAll('button'));
    const index = buttons.indexOf(document.activeElement);
    if (event.key === 'ArrowDown') { event.preventDefault(); buttons[Math.min(index + 1, buttons.length - 1)]?.focus(); }
    if (event.key === 'ArrowUp') { event.preventDefault(); if (index <= 0) (box === cityResults ? cityInput : pointInput).focus(); else buttons[index - 1].focus(); }
    if (event.key === 'Escape') { clearResults(box, box === cityResults ? cityInput : pointInput); (box === cityResults ? cityInput : pointInput).focus(); }
  }));
  document.addEventListener('click', (event) => {
    if (!event.target.closest?.('.delivery-search')) { clearResults(cityResults, cityInput); clearResults(pointResults, pointInput); }
  });
  if (hasDelivery()) applyCarrier(true);

  // ---- Validation: visible, localized, focusable --------------------------------------------------------------
  const errorsBox = q('[data-checkout-errors]');
  function clearInvalid(root) {
    if (!root) return;
    root.querySelectorAll('.is-invalid').forEach((node) => { node.classList.remove('is-invalid'); node.removeAttribute('aria-invalid'); });
    root.querySelectorAll('.ck-error[data-auto]').forEach((node) => node.remove());
  }
  const markInvalid = (control, message, id) => {
    const field = control.closest('.ck-field') || control.parentElement;
    control.classList.add('is-invalid');
    control.setAttribute('aria-invalid', 'true');
    const note = document.createElement('p');
    note.className = 'ck-error';
    note.dataset.auto = '';
    note.id = `ck-err-${id}`;
    note.textContent = message;
    field.append(note);
    control.setAttribute('aria-describedby', note.id);
    return { control, message };
  };
  const validate = () => {
    clearInvalid(form);
    if (deliveryError) deliveryError.hidden = true;
    const problems = [];
    let n = 0;
    qa('input[required], select[required], textarea[required]', form).forEach((control) => {
      if (control.closest('[hidden]')) return;
      const value = control.value.trim();
      if (value === '') { problems.push(markInvalid(control, control.name === 'delivery_region' ? i18n.pickRegion : i18n.required, `f${n++}`)); return; }
      if (control.type === 'email' && !control.checkValidity()) problems.push(markInvalid(control, i18n.invalidEmail, `f${n++}`));
    });
    const email = form.querySelector('input[name="email"]');
    if (email && email.value.trim() !== '' && !email.checkValidity() && !email.classList.contains('is-invalid')) problems.push(markInvalid(email, i18n.invalidEmail, `f${n++}`));
    const phone = form.querySelector('input[name="phone"]');
    if (phone && phone.value.trim() !== '' && phone.value.replace(/\D/g, '').length < 9 && !phone.classList.contains('is-invalid')) problems.push(markInvalid(phone, i18n.invalidPhone, `f${n++}`));
    if (hasDelivery()) {
      if (isPickup()) syncPickup();
      const manual = (manualInput?.value || '').trim();
      const ok = isPickup() ? Boolean(pointId?.value) : Boolean(pointId?.value) || manual !== '';
      if (!ok) {
        const message = isPickup() ? i18n.pickPickup : i18n.pickDelivery;
        const anchor = isPickup() ? q('.ck-points') : (cityInput && !cityInput.value.trim() ? cityInput : (pointInput && !pointInput.disabled ? pointInput : cityInput));
        if (deliveryError) { deliveryError.textContent = message; deliveryError.hidden = false; }
        anchor?.classList.add('is-invalid');
        if (!isPickup() && anchor) anchor.setAttribute('aria-invalid', 'true');
        problems.push({ control: isPickup() ? q('input[name="pickup_choice"]:checked') || anchor : anchor, message });
      }
    }
    return problems;
  };
  const showProblems = (problems) => {
    if (!errorsBox) return;
    errorsBox.innerHTML = '';
    const title = document.createElement('strong');
    title.textContent = i18n.errorsTitle;
    const list = document.createElement('ul');
    problems.forEach(({ control, message }) => {
      const item = document.createElement('li');
      const label = control?.closest('.ck-field')?.querySelector('span')?.textContent?.replace('*', '').trim();
      item.textContent = label ? `${label}: ${message}` : message;
      list.append(item);
    });
    errorsBox.append(title, list);
    errorsBox.hidden = false;
  };
  const scrollToControl = (control) => {
    if (!control) return;
    control.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    if (typeof control.focus === 'function') control.focus({ preventScroll: true });
  };
  form?.addEventListener('input', (event) => {
    const control = event.target;
    if (control?.closest?.('[data-delivery-block]') && deliveryError) { deliveryError.hidden = true; q('[data-delivery-block]')?.querySelectorAll('.is-invalid').forEach((node) => { node.classList.remove('is-invalid'); node.removeAttribute('aria-invalid'); }); }
    if (control?.classList?.contains('is-invalid')) {
      control.classList.remove('is-invalid');
      control.removeAttribute('aria-invalid');
      control.closest('.ck-field')?.querySelector('.ck-error[data-auto]')?.remove();
    }
  });
  form?.addEventListener('submit', (event) => {
    if (form.dataset.submitting === '1') { event.preventDefault(); return; }
    const problems = validate();
    if (problems.length > 0) {
      event.preventDefault();
      showProblems(problems);
      scrollToControl(problems[0].control);
      return;
    }
    if (errorsBox) errorsBox.hidden = true;
    form.dataset.submitting = '1';
    qa('[data-place-order]').forEach((button) => { button.disabled = true; button.classList.add('is-loading'); button.textContent = i18n.placing; });
  });
  // pageshow: coming back from the payment provider with the bfcache must not leave the button disabled.
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted || !form) return;
    form.dataset.submitting = '0';
    qa('[data-place-order]').forEach((button) => { button.disabled = false; button.classList.remove('is-loading'); });
  });
  q('[data-checkout-flash]')?.scrollIntoView({ block: 'center' });

  // ---- Delivery and payment fees: the totals follow the chosen methods (advisory; the order is recalculated on the server) ----
  const methodParams = () => ({
    carrier: form.querySelector('[name="carrier"]:checked')?.value || form.querySelector('[name="carrier"][type="hidden"]')?.value || '',
    payment_method: form.querySelector('[name="payment_method"]:checked')?.value || '',
  });
  const applyShipping = (data) => {
    const row = q('[data-shipping-row]', form);
    const value = q('[data-summary-shipping]', form);
    if (row) row.hidden = !(Number(data.shipping_minor || 0) > 0);
    if (value) value.textContent = data.shipping || '';
  };
  let refreshTimer = 0;
  const refreshTotals = () => {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(async () => {
      const token = q('[data-promotion-token]', form);
      if (!token) return;
      try {
        const body = new URLSearchParams({
          _token: token.value,
          coupon_code: q('[data-coupon-code]', form)?.value || '',
          gift_card_code: q('[data-reward-gift]', form)?.value || '',
          loyalty_points: q('[data-reward-points]', form)?.value || '0',
          email: form.querySelector('[name="email"]')?.value || '',
          ...methodParams(),
        });
        const response = await fetch('/checkout/rewards/preview', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
        const data = await response.json();
        applyShipping(data);
        if (data.total) qa('[data-summary-total]', form).forEach((node) => { node.textContent = data.total; });
      } catch (_) { /* the totals stay as they were */ }
    }, 250);
  };
  form.addEventListener('change', (event) => { if (event.target?.matches?.('[name="carrier"], [name="payment_method"]')) refreshTotals(); });
  refreshTotals();

  // ---- Promotion preview (advisory only; CheckoutOrderService recalculates everything transactionally) ------------
  const promotionButton = q('[data-coupon-apply]');
  promotionButton?.addEventListener('click', async () => {
    const code = q('[data-coupon-code]', form);
    const token = q('[data-promotion-token]', form);
    const message = q('[data-coupon-message]', form);
    if (!code || !token || !message) return;
    promotionButton.disabled = true;
    try {
      const body = new URLSearchParams({ _token: token.value, coupon_code: code.value, email: form.querySelector('[name="email"]')?.value || '', ...methodParams() });
      const response = await fetch('/checkout/promotion/preview', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      message.textContent = data.message || i18n.promoFailed;
      message.dataset.state = data.ok ? 'success' : 'error';
      const discountRow = q('[data-discount-row]', form);
      if (discountRow) discountRow.hidden = !(Number(data.discount_minor || 0) > 0);
      const discount = q('[data-summary-discount]', form);
      if (discount) discount.textContent = data.discount ? `−${data.discount}` : '';
      applyShipping(data);
      if (data.total) qa('[data-summary-total]', form).forEach((node) => { node.textContent = data.total; });
    } catch (_) {
      message.textContent = i18n.promoRetry;
      message.dataset.state = 'error';
    } finally {
      promotionButton.disabled = false;
    }
  });

  // ---- Gift card and bonus points: the same advisory preview as the promo code ---------------------------------
  qa('[data-reward-apply]', form).forEach((button) => button.addEventListener('click', async () => {
    const token = q('[data-promotion-token]', form);
    const message = q('[data-reward-message]', form);
    if (!token || !message) return;
    button.disabled = true;
    try {
      const body = new URLSearchParams({
        _token: token.value,
        gift_card_code: q('[data-reward-gift]', form)?.value || '',
        loyalty_points: q('[data-reward-points]', form)?.value || '0',
        coupon_code: q('[data-coupon-code]', form)?.value || '',
        email: form.querySelector('[name="email"]')?.value || '',
        ...methodParams(),
      });
      const response = await fetch('/checkout/rewards/preview', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      message.textContent = data.message || i18n.promoFailed;
      message.dataset.state = data.ok ? 'success' : 'error';
      applyShipping(data);
      if (data.total) qa('[data-summary-total]', form).forEach((node) => { node.textContent = data.total; });
    } catch (_) {
      message.textContent = i18n.promoRetry;
      message.dataset.state = 'error';
    } finally {
      button.disabled = false;
    }
  }));
  // Keep what the shopper typed even if they leave: the shop can then see who abandoned the cart and write to them.
  const leadToken = q('[data-lead-token]', form);
  if (leadToken && form) {
    let leadTimer = 0;
    let leadSent = '';
    const sendLead = () => {
      const field = (name) => (form.elements[name] ? String(form.elements[name].value || '').trim() : '');
      const payload = { name: field('name'), email: field('email'), phone: field('phone') };
      const digits = payload.phone.replace(/\D/g, '');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email) && digits.length < 7) return;
      const key = JSON.stringify(payload);
      if (key === leadSent) return;
      leadSent = key;
      const body = new URLSearchParams({ ...payload, _token: leadToken.value });
      try {
        if (navigator.sendBeacon) {
          navigator.sendBeacon('/checkout/lead', body);
        } else {
          window.fetch('/checkout/lead', { method: 'POST', body, credentials: 'same-origin', keepalive: true });
        }
      } catch (_) { /* the lead is a bonus, never block the checkout */ }
    };
    ['name', 'email', 'phone'].forEach((name) => {
      const input = form.elements[name];
      if (!input || typeof input.addEventListener !== 'function') return;
      input.addEventListener('change', () => { window.clearTimeout(leadTimer); leadTimer = window.setTimeout(sendLead, 400); });
    });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') sendLead(); });
    window.addEventListener('pagehide', sendLead);
    sendLead();
  }
}
