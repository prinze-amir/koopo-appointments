(function($) {
  'use strict';

  if (typeof KOOPO_CUSTOMER === 'undefined') return;

  const utils = window.KOOPO_CUSTOMER_UTILS || {};

  async function api(path, opts = {}) {
    const url = `${KOOPO_CUSTOMER.api_url}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_CUSTOMER.nonce
    }, opts.headers || {});
    const res = await fetch(url, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || data.error || 'Request failed');
    return data;
  }

  function fmtMoney(amount) {
    const val = Number(amount || 0);
    return `${KOOPO_CUSTOMER.currency_symbol}${val.toFixed(2)}`;
  }

  utils.api = api;
  utils.fmtMoney = fmtMoney;
  window.KOOPO_CUSTOMER_UTILS = utils;
})(jQuery);
