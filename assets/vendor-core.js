(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;

  const utils = window.KOOPO_VENDOR_UTILS || {};

  async function api(path, opts = {}) {
    const base = String(KOOPO_APPT_VENDOR.rest || '').replace(/\/$/, '');
    const url = `${base}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_APPT_VENDOR.nonce
    }, opts.headers || {});
    const res = await fetch(url, Object.assign({}, opts, { headers, credentials: 'same-origin' }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function loadVendorListings($select){
    $select.empty().append('<option value="">Select listing…</option>');
    const preloaded = Array.isArray(KOOPO_APPT_VENDOR.listings) ? KOOPO_APPT_VENDOR.listings : null;
    let listings = preloaded;
    if (!Array.isArray(listings) || !listings.length) {
      try {
        listings = await api('/vendor/listings', { method:'GET' });
      } catch (e) {
        console.error('Failed to load listings', e);
        $select.append('<option value="">Unable to load listings</option>');
        return [];
      }
    }
    if (!Array.isArray(listings)) listings = [];
    if (!listings.length) {
      $select.append('<option value="">No listings found</option>');
      return listings;
    }
    listings.forEach(l => {
      $select.append(`<option value="${l.id}">${escapeHtml(l.title)}</option>`);
    });
    return listings;
  }

  function escapeHtml(str){
    return String(str||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function formatCurrency(amount, symbol){
    const n = Number(amount||0);
    const s = symbol || (KOOPO_APPT_VENDOR && KOOPO_APPT_VENDOR.currency_symbol) || '$';
    return `${s}${n.toFixed(2)}`;
  }

  utils.api = api;
  utils.loadVendorListings = loadVendorListings;
  utils.escapeHtml = escapeHtml;
  utils.formatCurrency = formatCurrency;

  window.KOOPO_VENDOR_UTILS = utils;
})(jQuery);
