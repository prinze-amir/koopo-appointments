(function($){
  function showNotice($wrap, msg, type) {
    const $n = $wrap.find('.koopo-appt__notice');
    $n.removeClass('koopo-appt__notice--hidden')
      .removeClass('koopo-appt__notice--error koopo-appt__notice--success')
      .addClass(type === 'success' ? 'koopo-appt__notice--success' : 'koopo-appt__notice--error')
      .text(msg);
  }

  function clearNotice($wrap){
    $wrap.find('.koopo-appt__notice')
      .addClass('koopo-appt__notice--hidden')
      .text('');
  }

  function setLoading($wrap, on){
    $wrap.find('.koopo-appt__loading').toggleClass('koopo-appt__loading--hidden', !on);
    $wrap.find('.koopo-appt__submit').prop('disabled', on);
  }

  function fmtDate(d){
  const pad = (n)=>String(n).padStart(2,'0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
}
function addDays(d, n){
  const x = new Date(d.getTime());
  x.setDate(x.getDate() + n);
  return x;
}
function startOfWeek(d){
  // week starts Monday
  const x = new Date(d.getTime());
  const day = x.getDay(); // 0 Sun, 1 Mon...
  const diff = (day === 0 ? -6 : 1) - day; // move to Monday
  x.setDate(x.getDate() + diff);
  x.setHours(0,0,0,0);
  return x;
}
function dayLabel(d){
  return d.toLocaleDateString(undefined, { weekday: 'short' });
}
function monthDay(d){
  return d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
}
function timeBucket(label){
  // label like "9:00 AM"
  const m = label.match(/(\d+):(\d+)\s*(AM|PM)/i);
  if (!m) return 'Other';
  let hour = parseInt(m[1], 10);
  const ampm = m[3].toUpperCase();
  if (ampm === 'PM' && hour !== 12) hour += 12;
  if (ampm === 'AM' && hour === 12) hour = 0;

  if (hour < 12) return 'Morning';
  if (hour < 17) return 'Afternoon';
  return 'Evening';
}

function getState($root){
  if (!$root.data('koopoState')) {
    $root.data('koopoState', {
      weekStart: startOfWeek(new Date()),
      selectedDate: null,
      selectedService: null,
      listingSettings: null,
    });
  }
  return $root.data('koopoState');
}

async function loadListingSettings($root){
  const listingId = $root.data('listing-id') || KOOPO_APPT.listingId;
  if ($root.data('listingSettingsLoaded')) return;

  try {
    const s = await api(`/appointments/settings/${listingId}`, { method: 'GET' });
    $root.data('listingSettings', s);
    $root.data('listingSettingsLoaded', true);
    if (s && s.enabled === false) {
  $root.find('.koopo-appt__slots').html('<div class="koopo-appt__slots-empty">Booking is currently disabled for this listing.</div>');
}

  } catch(e) {
    // Non-fatal; availability endpoint will still return empty when disabled
  }
}

function renderWeek($root){
  const state = getState($root);
  const ws = state.weekStart;
  const $week = $root.find('.koopo-appt__week');
  $week.empty();

  for (let i=0;i<7;i++){
    const d = addDays(ws, i);
    const iso = fmtDate(d);
    const isSelected = state.selectedDate === iso;

    $week.append(`
      <button type="button" class="koopo-appt__day ${isSelected ? 'is-selected':''}" data-date="${iso}">
        <div class="koopo-appt__dayname">${dayLabel(d)}</div>
        <div class="koopo-appt__daynum">${monthDay(d)}</div>
      </button>
    `);
  }
}

function isClosedDay(settings, isoDate){
  if (!settings) return false; // unknown -> don’t block
  if (!settings.enabled) return true;
  if ((settings.days_off || []).includes(isoDate)) return true;

  // map date to day key
  const dt = new Date(isoDate + 'T00:00:00');
  const map = ['sun','mon','tue','wed','thu','fri','sat'];
  const dayKey = map[dt.getDay()];
  const hours = (settings.hours && settings.hours[dayKey]) ? settings.hours[dayKey] : [];
  return !hours.length;
}

async function loadSlotsPolished($root){
  const state = getState($root);
  const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
  const date = state.selectedDate;

  const $slots = $root.find('.koopo-appt__slots');
  $root.find('.koopo-appt__slot-start').val('');
  $root.find('.koopo-appt__slot-end').val('');

  if (!serviceId) {
    $slots.html('<div class="koopo-appt__slots-empty">Select a service to view availability.</div>');
    return;
  }
  if (!date) {
    $slots.html('<div class="koopo-appt__slots-empty">Select a day to view times.</div>');
    return;
  }

  const settings = $root.data('listingSettings');
  if (isClosedDay(settings, date)) {
    $slots.html('<div class="koopo-appt__slots-empty">Closed on this day.</div>');
    return;
  }

  $slots.html('<div class="koopo-appt__slots-empty">Loading times…</div>');

  const data = await api(`/availability/by-service/${serviceId}?date=${encodeURIComponent(date)}`, { method:'GET' });
  const slots = (data && data.slots) ? data.slots : [];

  if (!slots.length) {
    $slots.html('<div class="koopo-appt__slots-empty">No times available (fully booked).</div>');
    return;
  }

  // Group slots
  const groups = { Morning: [], Afternoon: [], Evening: [], Other: [] };
  slots.forEach(s => {
    const b = timeBucket(s.label);
    groups[b] = groups[b] || [];
    groups[b].push(s);
  });

  const groupHtml = Object.keys(groups)
    .filter(k => groups[k].length)
    .map(k => `
      <div class="koopo-appt__slotgroup">
        <div class="koopo-appt__slotgroup-title">${k}</div>
        <div class="koopo-appt__slotgroup-grid">
          ${groups[k].map(s => `
            <button type="button" class="koopo-appt__slot" data-start="${s.start}" data-end="${s.end}">
              ${s.label}
            </button>
          `).join('')}
        </div>
      </div>
    `).join('');

  $slots.html(groupHtml);
}

async function autoPickFirstAvailableDay($root){
  const state = getState($root);
  const settings = $root.data('listingSettings');

  // Try current week then next week
  for (let w=0; w<2; w++){
    const ws = (w === 0) ? state.weekStart : addDays(state.weekStart, 7);

    for (let i=0;i<7;i++){
      const d = addDays(ws, i);
      const iso = fmtDate(d);

      if (isClosedDay(settings, iso)) continue;

      // Lightweight probe: call availability once we have service
      const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
      if (!serviceId) return;

      try {
        const data = await api(`/availability/by-service/${serviceId}?date=${encodeURIComponent(iso)}`, { method:'GET' });
        if (data.slots && data.slots.length) {
          state.weekStart = ws;
          state.selectedDate = iso;
          $root.find('.koopo-appt__date').val(iso);
          renderWeek($root);
          await loadSlotsPolished($root);
          refreshSummary($root);
          return;
        }
      } catch(e) {}
    }
  }

  // Nothing found
  const $slots = $root.find('.koopo-appt__slots');
  $slots.html('<div class="koopo-appt__slots-empty">No availability found in the next 2 weeks.</div>');
}

  async function api(path, opts = {}) {
    const url = `${KOOPO_APPT.restUrl}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_APPT.nonce
    }, opts.headers || {});

    const res = await fetch(url, Object.assign({}, opts, { headers, credentials: 'same-origin' }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const msg = (data && data.error) ? data.error : 'Request failed';
      // If auth is required, send to login.
      if ((res.status === 401 || res.status === 403) && KOOPO_APPT && KOOPO_APPT.loginUrl) {
        window.location.href = KOOPO_APPT.loginUrl;
        return Promise.reject(new Error('Login required'));
      }
      const err = new Error(msg);
      err.code = (data && data.code) ? data.code : String(res.status);
      throw err;
    }
    return data;
  }

  function openModal($root){
    const $overlay = $root.find('.koopo-appt__overlay');
    $overlay.attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('koopo-appt--modal-open');
  }

  function closeModal($root){
    const $overlay = $root.find('.koopo-appt__overlay');
    $overlay.attr('aria-hidden', 'true').removeClass('is-open');
    $('body').removeClass('koopo-appt--modal-open');
  }

  async function loadServices($root){
    const listingId = $root.data('listing-id') || KOOPO_APPT.listingId;
    const services = await api(`/services/by-listing/${listingId}`, { method: 'GET' });

    const $sel = $root.find('.koopo-appt__service');
    $sel.empty();

    if (!services.length) {
      $sel.append(`<option value="">No services available</option>`);
      return [];
    }

    $sel.append(`<option value="">Select a service</option>`);
    services.forEach(s => {
      $sel.append(`<option value="${s.id}" data-price="${s.price}" data-duration="${s.duration_minutes}">${s.title}</option>`);
    });
    return services;
  }

  function refreshSummary($root){

    const $opt = $root.find('.koopo-appt__service option:selected');
    const price = $opt.data('price');
    const duration = $opt.data('duration');

    $root.find('.koopo-appt__price').text(
      (price !== undefined) ? `${KOOPO_APPT.currency}${Number(price).toFixed(2)}` : '—'
    );
    $root.find('.koopo-appt__duration').text(
      (duration !== undefined) ? `${duration} min` : '—'
    );

    const hasService = !!$root.find('.koopo-appt__service').val();
    const hasDate = !!$root.find('.koopo-appt__date').val();
    const hasSlot = !!$root.find('.koopo-appt__slot-start').val();
    $root.find('.koopo-appt__submit').prop('disabled', !(hasService && hasDate && hasSlot));

  }

  async function createBookingAndCheckout($root){

    clearNotice($root);
    setLoading($root, true);

    try {
      if (KOOPO_APPT && parseInt(KOOPO_APPT.userId, 10) === 0 && KOOPO_APPT.loginUrl) {
        window.location.href = KOOPO_APPT.loginUrl;
        return;
      }
      const listingId = $root.data('listing-id') || KOOPO_APPT.listingId;
      const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
      const date = $root.find('.koopo-appt__date').val();
      if (!serviceId || !date) {
        throw new Error('Please select a service and date.');
      }

      const price = parseFloat($root.find('.koopo-appt__service option:selected').data('price')) || 0;

      // 1) Create booking (pending)
      const start = $root.find('.koopo-appt__slot-start').val();
      const end   = $root.find('.koopo-appt__slot-end').val();

      if (!start || !end) throw new Error('Please select an available time.');

      const booking = await api('/bookings', {
        method: 'POST',
        body: JSON.stringify({
          listing_id: listingId,
          service_id: serviceId,
          start_datetime: start,
          end_datetime: end,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
          price: price
        })
      });


      // 2) Add to cart + go checkout
      const checkout = await api(`/bookings/${booking.booking_id}/checkout-cart`, {
        method: 'POST'
      });

      // Redirect to checkout
      window.location.href = checkout.checkout_url;

    } catch (e) {
      let msg = e && e.message ? e.message : 'Something went wrong.';
      const code = e && e.code ? e.code : '';
      if (code === 'koopo_hold_expired') {
        const mins = (KOOPO_APPT && KOOPO_APPT.holdMinutes) ? parseInt(KOOPO_APPT.holdMinutes, 10) : 10;
        msg = `Your ${mins}-minute hold expired. Please choose another time.`;
      } else if (code === 'koopo_service_unavailable') {
        msg = 'This service is temporarily unavailable. Please try again later.';
      }
      showNotice($root, msg, 'error');
      setLoading($root, false);
    }
  }

  $(document).on('click', '.koopo-appt__open', async function(){
  const $root = $(this).closest('.koopo-appt');
  openModal($root);
  clearNotice($root);

  const state = getState($root);

  try {
    await loadListingSettings($root);
    await loadServices($root);
    renderWeek($root);

    // If previously selected date exists, reload
    if (state.selectedDate) {
      await loadSlotsPolished($root);
    } else {
      $root.find('.koopo-appt__slots').html('<div class="koopo-appt__slots-empty">Select a service to view availability.</div>');
    }

    refreshSummary($root);
  } catch (e) {
    showNotice($root, e.message || 'Failed to load booking UI.', 'error');
  }
});

$(document).on('click', '.koopo-appt__prev', function(){
  const $root = $(this).closest('.koopo-appt');
  const state = getState($root);
  state.weekStart = addDays(state.weekStart, -7);
  renderWeek($root);
});

$(document).on('click', '.koopo-appt__next', function(){
  const $root = $(this).closest('.koopo-appt');
  const state = getState($root);
  state.weekStart = addDays(state.weekStart, 7);
  renderWeek($root);
});

$(document).on('click', '.koopo-appt__day', function(){
  const $root = $(this).closest('.koopo-appt');
  const state = getState($root);
  const iso = $(this).data('date');

  state.selectedDate = iso;
  $root.find('.koopo-appt__date').val(iso);

  renderWeek($root);
  loadSlotsPolished($root).catch(()=>{});
  refreshSummary($root);
});

$(document).on('change', '.koopo-appt__service', function(){
  const $root = $(this).closest('.koopo-appt');
  const state = getState($root);
  state.selectedService = $(this).val();

  refreshSummary($root);
  autoPickFirstAvailableDay($root).catch(()=>{});
});

  $(document).on('click', '.koopo-appt__close', function(){
    closeModal($(this).closest('.koopo-appt'));
  });

  $(document).on('click', '.koopo-appt__overlay', function(e){
    // click outside modal closes
    if ($(e.target).hasClass('koopo-appt__overlay')) {
      closeModal($(this).closest('.koopo-appt'));
    }
  });

  $(document).on('change input', '.koopo-appt__service, .koopo-appt__date, .koopo-appt__time', function(){
    refreshSummary($(this).closest('.koopo-appt'));
  });

  $(document).on('click', '.koopo-appt__submit', function(){
    const $root = $(this).closest('.koopo-appt');
    createBookingAndCheckout($root);
  });

  // If we want a post-checkout message, we can show it on order-received pages.
  // For listing pages, you’ll typically land on /checkout/order-received/...
async function loadSlots($root){
  const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
  const date = $root.find('.koopo-appt__date').val();
  const $slots = $root.find('.koopo-appt__slots');

  $slots.html('<div class="koopo-appt__slots-empty">Loading…</div>');
  $root.find('.koopo-appt__slot-start').val('');
  $root.find('.koopo-appt__slot-end').val('');

  if (!serviceId || !date) {
    $slots.html('<div class="koopo-appt__slots-empty">Select a service and date to view available times.</div>');
    return;
  }

  const data = await api(`/availability/by-service/${serviceId}?date=${encodeURIComponent(date)}`, { method: 'GET' });

  if (!data.slots || !data.slots.length) {
    $slots.html('<div class="koopo-appt__slots-empty">No times available for this date.</div>');
    return;
  }

  const html = data.slots.map(s => (
    `<button type="button" class="koopo-appt__slot"
        data-start="${s.start}" data-end="${s.end}">
        ${s.label}
     </button>`
  )).join('');

  $slots.html(html);
}

$(document).on('click', '.koopo-appt__slot', function(){
  const $root = $(this).closest('.koopo-appt');
  $root.find('.koopo-appt__slot').removeClass('is-selected');
  $(this).addClass('is-selected');

  $root.find('.koopo-appt__slot-start').val($(this).data('start'));
  $root.find('.koopo-appt__slot-end').val($(this).data('end'));

  refreshSummary($root);
});


$(document).on('change input', '.koopo-appt__service, .koopo-appt__date', function(){
  const $root = $(this).closest('.koopo-appt');
  refreshSummary($root);
  loadSlots($root).catch(() => {});
});


})(jQuery);
