// Google Pay button on the payment page. Google returns a card token made for Stripe; the server charges it and answers with
// the next step (paid, a bank confirmation page, or an error to show). Only the token leaves the page, never a card number.
const PAY_JS = 'https://pay.google.com/gp/p/js/pay.js';
const BASE_REQUEST = { apiVersion: 2, apiVersionMinor: 0 };

function loadGooglePay() {
  if (window.google?.payments?.api) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = PAY_JS;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('google-pay-script'));
    document.head.append(script);
  });
}

function boot() {
  const root = document.querySelector('[data-google-pay]');
  if (!root) return;
  const config = JSON.parse(root.dataset.config || '{}');
  const holder = root.querySelector('[data-gpay-button]');
  const status = root.querySelector('[data-gpay-status]');
  const text = (key, fallback) => (window.MC_I18N && window.MC_I18N[key]) || fallback;
  const say = (message, isError = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', isError);
  };

  const card = {
    type: 'CARD',
    parameters: { allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'], allowedCardNetworks: ['MASTERCARD', 'VISA'] },
    tokenizationSpecification: {
      type: 'PAYMENT_GATEWAY',
      parameters: { gateway: 'stripe', 'stripe:version': '2018-10-31', 'stripe:publishableKey': config.publishable_key },
    },
  };

  let busy = false;
  const pay = async (client) => {
    if (busy) return;
    busy = true;
    holder.setAttribute('aria-busy', 'true');
    say('');
    try {
      const merchantInfo = { merchantName: config.merchant_name };
      if (config.environment === 'PRODUCTION' && config.merchant_id) merchantInfo.merchantId = config.merchant_id;
      const data = await client.loadPaymentData({
        ...BASE_REQUEST,
        allowedPaymentMethods: [card],
        merchantInfo,
        transactionInfo: { totalPriceStatus: 'FINAL', totalPrice: config.amount, currencyCode: config.currency, countryCode: config.country || undefined },
      });
      say(text('js_gpay_processing', 'Processing the payment…'));
      const response = await fetch(config.charge_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
        body: JSON.stringify({ token: data.paymentMethodData.tokenizationData.token }),
      });
      const result = await response.json().catch(() => ({}));
      if (response.ok && result.redirect) {
        window.location.assign(result.redirect);
        return;
      }
      say(result.message || text('js_gpay_failed', 'The payment was not completed. Try another card.'), true);
    } catch (error) {
      // The shopper closing the Google Pay sheet is not an error worth a message.
      if (error?.statusCode !== 'CANCELED') say(text('js_gpay_failed', 'The payment was not completed. Try another card.'), true);
    } finally {
      busy = false;
      holder.removeAttribute('aria-busy');
    }
  };

  loadGooglePay()
    .then(async () => {
      const client = new window.google.payments.api.PaymentsClient({ environment: config.environment });
      const ready = await client.isReadyToPay({ ...BASE_REQUEST, allowedPaymentMethods: [card] });
      if (!ready.result) {
        say(text('js_gpay_unavailable', 'Google Pay is not available in this browser or on this device. Choose another payment method.'), true);
        return;
      }
      holder.append(client.createButton({ buttonType: 'pay', buttonSizeMode: 'fill', onClick: () => pay(client) }));
    })
    .catch(() => say(text('js_gpay_unavailable', 'Google Pay is not available in this browser or on this device. Choose another payment method.'), true));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
