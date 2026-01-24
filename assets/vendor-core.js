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

  function formatMoney(amount, currency){
    const n = Number(amount||0);
    const c = String(currency||'').trim();
    return (c ? escapeHtml(c) + ' ' : '$') + n.toFixed(2);
  }

  function parseDateTime(str){
    if (!str) return null;
    const iso = String(str).replace(' ', 'T');
    const d = new Date(iso);
    return isNaN(d.getTime()) ? null : d;
  }

  function toYmd(date){
    const d = new Date(date);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function toYmdHms(date){
    const d = new Date(date);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd} ${hh}:${mi}:00`;
  }

  function formatTime(str){
    const d = parseDateTime(str);
    if (!d) return '';
    return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function renderAvatar(booking, opts = {}){
    const showName = !!opts.showName;
    const name = (booking && (booking.customer_name || booking.customer_email)) || 'Guest';
    if (!booking || !booking.customer_avatar || !booking.customer_profile) {
      return `<span>${escapeHtml(name)}</span>`;
    }
    return `
      <a class="koopo-avatar" href="${escapeHtml(booking.customer_profile)}" target="_blank" rel="noopener">
        <img class="koopo-avatar__img" src="${escapeHtml(booking.customer_avatar)}" alt="${escapeHtml(name)}" />
        ${showName ? `<span>${escapeHtml(name)}</span>` : ''}
        <span class="koopo-avatar__pop">View ${escapeHtml(name)}&#39;s profile</span>
      </a>
    `;
  }

  utils.api = api;
  utils.loadVendorListings = loadVendorListings;
  utils.escapeHtml = escapeHtml;
  utils.formatCurrency = formatCurrency;
  utils.formatMoney = formatMoney;
  utils.parseDateTime = parseDateTime;
  utils.toYmd = toYmd;
  utils.toYmdHms = toYmdHms;
  utils.formatTime = formatTime;
  utils.renderAvatar = renderAvatar;

  window.KOOPO_VENDOR_UTILS = utils;
})(jQuery);
