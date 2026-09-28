const checkout = document.querySelector('[data-checkout]');
if (checkout) {
  const carriers = [...checkout.querySelectorAll('[data-carrier]')];
  const cityInput = checkout.querySelector('[data-delivery-city]');
  const pointInput = checkout.querySelector('[data-delivery-point]');
  const cityResults = checkout.querySelector('[data-city-results]');
  const pointResults = checkout.querySelector('[data-point-results]');
  const fallback = checkout.querySelector('[data-delivery-fallback]');
  const country = checkout.dataset.country || 'UA';
  const i18n = {
    branchPlaceholder: checkout.dataset.i18nBranchPlaceholder || '',
    citiesFailed: checkout.dataset.i18nCitiesFailed || '',
    carrierUnavailable: checkout.dataset.i18nCarrierUnavailable || '',
    pointsFailed: checkout.dataset.i18nPointsFailed || '',
    promoFailed: checkout.dataset.i18nPromoFailed || '',
    promoRetry: checkout.dataset.i18nPromoRetry || '',
  };
  let carrier = carriers.find((item) => item.classList.contains('is-selected'))?.dataset.carrier || 'nova_post';
  let selectedCity = null;
  const carrierValue = checkout.querySelector('[data-carrier-value]');
  const cityId = checkout.querySelector('[data-city-id]');
  const cityName = checkout.querySelector('[data-city-name]');
  const pointId = checkout.querySelector('[data-point-id]');
  const pointName = checkout.querySelector('[data-point-name]');
  const manualInput = checkout.querySelector('[data-delivery-manual]');
  let timer = null;

  const clearResults = (box) => { box.innerHTML = ''; box.hidden = true; };
  const showFallback = (show, message = '') => {
    fallback.hidden = !show;
    if (manualInput) manualInput.required = show;
    if (show && message) fallback.querySelector('span').textContent = message;
  };
  const fetchJson = async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
  };
  const renderOptions = (box, items, select) => {
    box.innerHTML = '';
    for (const item of items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'delivery-result';
      button.textContent = item.address ? `${item.name} — ${item.address}` : item.name;
      button.addEventListener('click', () => { select(item); clearResults(box); });
      box.append(button);
    }
    box.hidden = items.length === 0;
  };
  const searchCities = async () => {
    const q = cityInput.value.trim();
    selectedCity = null; pointInput.disabled = true; pointInput.value = ''; clearResults(pointResults);
    if (q.length < 2) { clearResults(cityResults); return; }
    try {
      const data = await fetchJson(`/api/shipping/cities?provider=${encodeURIComponent(carrier)}&country=${encodeURIComponent(country)}&q=${encodeURIComponent(q)}&limit=12`);
      renderOptions(cityResults, data.items || [], (item) => { selectedCity = item; cityInput.value = item.name; if (cityId) cityId.value = item.id || ''; if (cityName) cityName.value = item.name || ''; pointInput.disabled = false; pointInput.placeholder = i18n.branchPlaceholder; pointInput.focus(); showFallback(false); });
      showFallback(false);
    } catch (_) {
      clearResults(cityResults); showFallback(true, i18n.citiesFailed);
    }
  };
  const searchPoints = async () => {
    if (!selectedCity) return;
    const q = pointInput.value.trim();
    try {
      const params = new URLSearchParams({ provider: carrier, country, city_id: selectedCity.id || '', city_name: selectedCity.name || '', q, limit: '20' });
      const data = await fetchJson(`/api/shipping/points?${params.toString()}`);
      if (data.manual_fallback_allowed && data.availability !== 'available') showFallback(true, data.notice || i18n.carrierUnavailable); else showFallback(false);
      renderOptions(pointResults, data.items || [], (item) => { const label = item.address ? `${item.name} — ${item.address}` : item.name; pointInput.value = label; pointInput.dataset.pointId = item.id || ''; if (pointId) pointId.value = item.id || ''; if (pointName) pointName.value = label || ''; showFallback(false); });
    } catch (_) {
      clearResults(pointResults); showFallback(true, i18n.pointsFailed);
    }
  };
  carriers.forEach((button) => button.addEventListener('click', () => {
    carrier = button.dataset.carrier || carrier; if (carrierValue) carrierValue.value = carrier; carriers.forEach((item) => item.classList.toggle('is-selected', item === button));
    cityInput.value = ''; pointInput.value = ''; pointInput.disabled = true; selectedCity = null; if (cityId) cityId.value=''; if (cityName) cityName.value=''; if (pointId) pointId.value=''; if (pointName) pointName.value=''; clearResults(cityResults); clearResults(pointResults); showFallback(false);
  }));
  cityInput?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(searchCities, 250); });
  pointInput?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(searchPoints, 250); });
}

// Promotion preview is advisory only; CheckoutOrderService recalculates all discounts transactionally.
const promotionButton = document.querySelector('[data-coupon-apply]');
if (promotionButton) {
  promotionButton.addEventListener('click', async () => {
    const form = promotionButton.closest('form');
    const code = form?.querySelector('[data-coupon-code]');
    const token = form?.querySelector('[data-promotion-token]');
    const message = form?.querySelector('[data-coupon-message]');
    if (!form || !code || !token || !message) return;
    promotionButton.disabled = true;
    try {
      const body = new URLSearchParams({_token: token.value, coupon_code: code.value, email: form.querySelector('[name="email"]')?.value || ''});
      const response = await fetch('/checkout/promotion/preview', {method:'POST', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
      const data = await response.json();
      message.textContent = data.message || checkout.dataset.i18nPromoFailed || '';
      message.dataset.state = data.ok ? 'success' : 'error';
      const discountRow = form.querySelector('[data-discount-row]');
      if (discountRow) discountRow.hidden = !(Number(data.discount_minor || 0) > 0);
      const discount = form.querySelector('[data-summary-discount]');
      const total = form.querySelector('[data-summary-total]');
      if (discount) discount.textContent = data.discount || '';
      if (total && data.total) total.textContent = data.total;
    } catch (_) {
      message.textContent = checkout.dataset.i18nPromoRetry || '';
      message.dataset.state = 'error';
    } finally {
      promotionButton.disabled = false;
    }
  });
}
