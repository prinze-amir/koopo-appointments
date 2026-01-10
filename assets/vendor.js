(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;

  async function api(path, opts = {}) {
    const url = `${KOOPO_APPT_VENDOR.rest}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_APPT_VENDOR.nonce
    }, opts.headers || {});
    const res = await fetch(url, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function loadVendorListings($select){
    $select.empty().append(`<option value="">Select listing…</option>`);
    const listings = await api('/vendor/listings', { method:'GET' });
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

  // ---------- Services page ----------
  const $servicesPicker = $('#koopo-listing-picker');
  const $servicesGrid   = $('#koopo-services-grid');
  const $modal          = $('#koopo-service-modal');

  const state = {
    listingId: null,
    editingServiceId: null,
    services: [],
  };

  function setServiceModalLoading(isLoading, message){
    const $loading = $modal.find('.koopo-modal__loading');
    if (!$loading.length) return;
    if (message) $loading.find('.koopo-modal__loading-text').text(message);
    $loading.toggle(!!isLoading);
    $modal.find('.koopo-modal__card :input, .koopo-modal__card button').prop('disabled', !!isLoading);
  }

  function renderServices(){
    if (!$servicesGrid.length) return;
    if (!state.listingId) {
      $servicesGrid.html('<div class="koopo-card koopo-muted">Pick a listing to load services.</div>');
      return;
    }
    if (!state.services.length) {
      $servicesGrid.html('<div class="koopo-card koopo-muted">No services yet. Click "Add Service".</div>');
      return;
    }
    const cards = state.services.map(s => {
      const badge = (s.status === 'inactive') ? '<span class="koopo-badge koopo-badge--gray">Inactive</span>' : '<span class="koopo-badge koopo-badge--green">Active</span>';
      const addonBadge = s.is_addon ? '<span class="koopo-badge koopo-badge--purple">Add-on</span>' : '';
      const price = (s.price_label && s.price_label.trim()) ? escapeHtml(s.price_label) : `$${Number(s.price||0).toFixed(2)}`;
      const colorDot = s.color ? `<span class="koopo-dot" style="background:${escapeHtml(s.color)}"></span>` : '';
      return `
        <div class="koopo-card koopo-card--click" data-service-id="${s.id}">
          <div class="koopo-card__top">
            <div class="koopo-card__title">${colorDot}${escapeHtml(s.title)}</div>
            <div class="koopo-card__badges">${badge}${addonBadge}</div>
          </div>
          <div class="koopo-card__meta">
            <div><strong>Price:</strong> ${price}</div>
            <div><strong>Duration:</strong> ${Number(s.duration_minutes||0)} min</div>
          </div>
        </div>
      `;
    }).join('');
    $servicesGrid.html(`<div class="koopo-grid">${cards}</div>`);
  }

  async function refreshServices(){
    if (!state.listingId) return;
    state.services = await api(`/services/by-listing/${state.listingId}`, { method:'GET' });
    renderServices();
  }

  async function openModal(service){
    state.editingServiceId = service ? service.id : null;
    $modal.show();
    setServiceModalLoading(true, 'Loading service...');
    $('#koopo-service-modal-title').text(service ? 'Edit Service' : 'Add Service');
    $('#koopo-service-name').val(service ? service.title : '');
    $('#koopo-service-description').val(service ? (service.description||'') : '');
    $('#koopo-service-color').val(service && service.color ? service.color : '#F4B400');
    $('#koopo-service-status').val(service && service.status ? service.status : 'active');
    $('#koopo-service-duration').val(service ? (service.duration_minutes||0) : 30);
    $('#koopo-service-price').val(service ? (service.price||0) : 0);
    $('#koopo-service-price-label').val(service ? (service.price_label||'') : '');
    $('#koopo-service-buffer-before').val(service ? (service.buffer_before||0) : 0);
    $('#koopo-service-buffer-after').val(service ? (service.buffer_after||0) : 0);
    $('#koopo-service-instant').prop('checked', !!(service && service.instant));
    $('#koopo-service-addon').prop('checked', !!(service && service.is_addon));
    $('#koopo-service-delete').toggle(!!service);

    // Load categories with spinner
    const $catSelect = $('#koopo-service-categories');
    $catSelect.prop('disabled', true).html('<option value="">Loading categories...</option>');

    try {
      const data = await api('/service-categories', { method: 'GET' });
      const categories = data.categories || [];

      $catSelect.empty();
      $catSelect.append('<option value="">Select a category...</option>');

      categories.forEach(cat => {
        $catSelect.append(`<option value="${cat.id}">${escapeHtml(cat.name)}</option>`);
      });

      $catSelect.prop('disabled', false);

      // Set selected category when editing (single selection)
      if (service && service.category_ids && service.category_ids.length > 0) {
        $catSelect.val(service.category_ids[0]);
      }
    } catch (e) {
      console.error('Failed to load categories:', e);
      $catSelect.html('<option value="">Failed to load categories</option>');
      $catSelect.prop('disabled', false);
    } finally {
      setServiceModalLoading(false);
    }
  }
  function closeModal(){
    setServiceModalLoading(false);
    $modal.hide();
  }

  async function saveService(){
    if (!state.listingId) throw new Error('Select a listing first.');

    // Get selected category ID (single selection)
    const categoryId = $('#koopo-service-categories').val();
    const categoryIds = categoryId ? [parseInt(categoryId, 10)] : [];

    const payload = {
      title: $('#koopo-service-name').val(),
      description: $('#koopo-service-description').val(),
      color: $('#koopo-service-color').val(),
      status: $('#koopo-service-status').val(),
      duration_minutes: parseInt($('#koopo-service-duration').val(),10) || 0,
      price: parseFloat($('#koopo-service-price').val()) || 0,
      price_label: $('#koopo-service-price-label').val(),
      buffer_before: parseInt($('#koopo-service-buffer-before').val(),10) || 0,
      buffer_after: parseInt($('#koopo-service-buffer-after').val(),10) || 0,
      instant: $('#koopo-service-instant').is(':checked') ? 1 : 0,
      is_addon: $('#koopo-service-addon').is(':checked') ? 1 : 0,
      listing_id: state.listingId,
      category_ids: categoryIds
    };

    if (!payload.title || !payload.duration_minutes) throw new Error('Service name and duration are required.');

    setServiceModalLoading(true, 'Saving service...');
    try {
      if (state.editingServiceId) {
        await api(`/services/${state.editingServiceId}`, { method:'POST', body: JSON.stringify(payload) });
      } else {
        await api('/services', { method:'POST', body: JSON.stringify(payload) });
      }
      await refreshServices();
      closeModal();
    } finally {
      setServiceModalLoading(false);
    }
  }

  async function deleteService(){
    if (!state.editingServiceId) return;
    if (!confirm('Trash this service?')) return;
    await api(`/services/${state.editingServiceId}`, { method:'DELETE' });
    state.editingServiceId = null;
    await refreshServices();
    closeModal();
  }

  if ($servicesPicker.length) {
    loadVendorListings($servicesPicker).catch(()=>{});
    $servicesPicker.on('change', async function(){
      state.listingId = parseInt($(this).val(),10) || null;
      await refreshServices();
    });
    $('#koopo-add-service').on('click', async function(){
      if (!state.listingId) { alert('Select a listing first.'); return; }
      await openModal(null);
    });
    $servicesGrid.on('click', '[data-service-id]', async function(){
      const id = parseInt($(this).data('service-id'),10);
      const svc = state.services.find(s => s.id === id);
      if (svc) await openModal(svc);
    });
    $modal.on('click', '.koopo-modal__close, #koopo-service-cancel', closeModal);
    $('#koopo-service-save').on('click', function(){
      saveService().catch(e => alert(e.message || 'Save failed'));
    });
    $('#koopo-service-delete').on('click', function(){
      deleteService().catch(e => alert(e.message || 'Delete failed'));
    });
  }

  // ---------- Booking settings page ----------
  const $settingsPicker = $('#koopo-settings-listing-picker');
  if ($settingsPicker.length) {
    loadVendorListings($settingsPicker).catch(()=>{});
    $settingsPicker.on('change', async function(){
      const listingId = parseInt($(this).val(),10) || 0;
      if (!listingId) return;
      const data = await api(`/appointments/settings/${listingId}`, { method:'GET' });
      $('#koopo-setting-enabled').prop('checked', !!data.enabled);
    });
    $('#koopo-settings-save').on('click', async function(){
      const listingId = parseInt($settingsPicker.val(),10) || 0;
      if (!listingId) { alert('Select a listing first.'); return; }
      await api(`/appointments/settings/${listingId}`, {
        method:'POST',
        body: JSON.stringify({ enabled: $('#koopo-setting-enabled').is(':checked') })
      });
      alert('Saved');
    });
  }

  // ---------- Appointments (Bookings) page ----------
  const $apptPicker = $('#koopo-appointments-picker');
  const $apptStatus = $('#koopo-appointments-status');
  const $apptSearch = $('#koopo-appointments-search');
  const $apptMonth = $('#koopo-appointments-month');
  const $apptYear = $('#koopo-appointments-year');
  const $apptExport = $('#koopo-appointments-export');
  const $apptTable  = $('#koopo-appointments-table');
  const $apptPager  = $('#koopo-appointments-pagination');
  const $apptCalendar = $('#koopo-appointments-calendar');
  const $calendarBody = $('#koopo-calendar-body');
  const $calendarTitle = $apptCalendar.find('.koopo-calendar-title');
  const $viewButtons = $('.koopo-view-btn');
  const $calViewButtons = $('.koopo-cal-view');
  const $calPrev = $apptCalendar.find('.koopo-cal-prev');
  const $calNext = $apptCalendar.find('.koopo-cal-next');
  const $calToday = $apptCalendar.find('.koopo-cal-today');
  const $apptCreate = $('#koopo-appt-create');
  const $apptCreateModal = $('#koopo-appt-create-modal');
  const $apptDetailsModal = $('#koopo-appt-details-modal');
  const $apptDetailsActions = $('#koopo-appt-details-actions');

  const apptState = { listingId: null, status: 'all', search: '', month: '', year: '', page: 1, perPage: 20, totalPages: 1, view: 'table' };
  const calendarState = { view: 'month', cursor: new Date(), items: [], mobileDay: null };
  let apptServices = [];
  let apptServiceMap = {};
  let apptTimezone = '';
  let apptItemsMap = {};
  let apptAddons = [];
  let selectedAddonIds = [];

  // Populate year dropdown with current and future years
  function populateYearDropdown() {
    if (!$apptYear.length) return;
    const currentYear = new Date().getFullYear();
    for (let i = currentYear - 2; i <= currentYear + 2; i++) {
      $apptYear.append(`<option value="${i}">${i}</option>`);
    }
  }
  populateYearDropdown();

  function badgeForStatus(status){
    const s = String(status||'').toLowerCase();
    if (s === 'confirmed') return '<span class="koopo-badge koopo-badge--green">Confirmed</span>';
    if (s === 'pending_payment') return '<span class="koopo-badge koopo-badge--yellow">Pending</span>';
    if (s === 'expired') return '<span class="koopo-badge koopo-badge--gray">Expired</span>';
    if (s === 'cancelled') return '<span class="koopo-badge koopo-badge--red">Cancelled</span>';
    if (s === 'refunded') return '<span class="koopo-badge koopo-badge--purple">Refunded</span>';
    if (s === 'conflict') return '<span class="koopo-badge koopo-badge--conflict">⚠️ Conflict</span>';
    return '<span class="koopo-badge koopo-badge--gray">'+escapeHtml(status)+'</span>';
  }

  function actionsForBooking(b){
    const id = Number(b && b.id ? b.id : 0);
    if (!id) return '—';
    const st = String(b.status||'').toLowerCase();
    let html = '';

    if (st === 'conflict') {
      html += `<span class="koopo-conflict-badge">⚠️ Requires Action</span><br>`;
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="reschedule" data-id="${id}">Reschedule</button> `;
      html += `<button class="koopo-btn koopo-btn--sm koopo-btn--danger koopo-appt-action" data-action="refund" data-id="${id}">Refund</button>`;
      return html;
    }

    if (st === 'pending_payment' || st === 'confirmed') {
      html += `<button class="koopo-btn koopo-btn--sm koopo-btn--danger koopo-appt-action" data-action="cancel" data-id="${id}">Cancel</button> `;
    }

    if (st === 'pending_payment') {
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="confirm" data-id="${id}">Confirm</button> `;
    }

    if (st === 'confirmed') {
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="reschedule" data-id="${id}">Reschedule</button> `;
    }
   

    // Show Refund when there is a WooCommerce order and the booking is in a refundable state.
    // (Actual eligibility is validated server-side via /refund-info.)
    if (b.wc_order_id && st !== 'refunded' && (st === 'confirmed' || st === 'cancelled')) {
      html += `<button class="koopo-btn koopo-btn--sm koopo-btn--danger koopo-appt-action" data-action="refund" data-id="${id}">Refund</button> `;
    }    

    if (b.wc_order_id) {
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="note" data-id="${id}">Add Note</button>`;
    }

    return html || '—';
  }

  function fmtMoney(price, currency){
    const n = Number(price||0);
    const c = String(currency||'').trim();
    return (c ? escapeHtml(c)+' ' : '$') + n.toFixed(2);
  }

  function renderPager(){
    if (!$apptPager.length) return;
    const p = apptState.page, tp = apptState.totalPages;
    if (tp <= 1) { $apptPager.html(''); return; }
    let html = '<div class="koopo-pager">';
    html += `<button class="koopo-btn koopo-btn--sm" data-page="${Math.max(1, p-1)}" ${p<=1?'disabled':''}>Prev</button>`;
    html += `<span class="koopo-pager__info">Page ${p} of ${tp}</span>`;
    html += `<button class="koopo-btn koopo-btn--sm" data-page="${Math.min(tp, p+1)}" ${p>=tp?'disabled':''}>Next</button>`;
    html += '</div>';
    $apptPager.html(html);
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

  function parseDateTime(str){
    if (!str) return null;
    const iso = String(str).replace(' ', 'T');
    const d = new Date(iso);
    return isNaN(d.getTime()) ? null : d;
  }

  function formatTime(str){
    const d = parseDateTime(str);
    if (!d) return '';
    return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

    function buildAvatarHtml(booking){
      const name = booking.customer_name || booking.customer_email || 'Guest';
      if (!booking.customer_avatar || !booking.customer_profile) {
        return `<span>${escapeHtml(name)}</span>`;
      }
      return `
      <a class="koopo-avatar" href="${escapeHtml(booking.customer_profile)}" target="_blank" rel="noopener">
        <img class="koopo-avatar__img" src="${escapeHtml(booking.customer_avatar)}" alt="${escapeHtml(name)}" />
        <span class="koopo-avatar__pop">View ${escapeHtml(name)}&#39;s profile</span>
      </a>
    `;
  }

  function buildAvatarWithNameHtml(booking){
    const name = booking.customer_name || booking.customer_email || 'Guest';
    if (!booking.customer_avatar || !booking.customer_profile) {
      return `<span>${escapeHtml(name)}</span>`;
    }
    return `
      <a class="koopo-avatar" href="${escapeHtml(booking.customer_profile)}" target="_blank" rel="noopener">
        <img class="koopo-avatar__img" src="${escapeHtml(booking.customer_avatar)}" alt="${escapeHtml(name)}" />
        <span>${escapeHtml(name)}</span>
        <span class="koopo-avatar__pop">View ${escapeHtml(name)}&#39;s profile</span>
      </a>
    `;
  }

  function hexToRgba(hex, alpha){
    const h = String(hex||'').replace('#','');
    if (h.length !== 6) return `rgba(44,122,60,${alpha})`;
    const r = parseInt(h.slice(0,2), 16);
    const g = parseInt(h.slice(2,4), 16);
    const b = parseInt(h.slice(4,6), 16);
    return `rgba(${r},${g},${b},${alpha})`;
  }

  function setAppointmentsView(view){
    apptState.view = view;
    $viewButtons.removeClass('is-active');
    $viewButtons.filter(`[data-view="${view}"]`).addClass('is-active');
    if (view === 'calendar') {
      $apptTable.hide();
      $apptPager.hide();
      $apptCalendar.show();
      loadCalendar();
    } else {
      $apptCalendar.hide();
      $apptTable.show();
      $apptPager.show();
      loadAppointments();
    }
  }

  function setCalendarView(view){
    calendarState.view = view;
    $calViewButtons.removeClass('is-active');
    $calViewButtons.filter(`[data-view="${view}"]`).addClass('is-active');
    loadCalendar();
  }

  function formatHourLabel(h){
    const hour = h % 12 || 12;
    const ampm = h < 12 ? 'AM' : 'PM';
    return `${hour} ${ampm}`;
  }

  function formatDurationLabel(minutes){
    const mins = Math.max(0, Math.round(minutes));
    if (mins < 60) return `${mins}m`;
    const hours = Math.floor(mins / 60);
    const rem = mins % 60;
    return rem ? `${hours}h ${rem}m` : `${hours}h`;
  }

  function weekStart(date){
    const d = new Date(date);
    const day = d.getDay();
    const diff = (day === 0 ? -6 : 1) - day;
    d.setDate(d.getDate() + diff);
    d.setHours(0,0,0,0);
    return d;
  }

  function monthStart(date){
    const d = new Date(date.getFullYear(), date.getMonth(), 1);
    d.setHours(0,0,0,0);
    return d;
  }

  function monthGridStart(date){
    const start = monthStart(date);
    const day = start.getDay();
    start.setDate(start.getDate() - day);
    return start;
  }

  function renderMonthView(items, cursor){
    const start = monthGridStart(cursor);
    const month = cursor.getMonth();
    const today = toYmd(new Date());
    const todayDow = new Date().getDay();

    const byDate = {};
    items.forEach(b => {
      const d = String(b.start_datetime || '').slice(0,10);
      if (!d) return;
      if (!byDate[d]) byDate[d] = [];
      byDate[d].push(b);
    });

    let html = '<div class="koopo-calendar-grid">';
    const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    dayNames.forEach((name, idx) => {
      const headClass = idx === todayDow ? 'koopo-calendar-day-head is-today' : 'koopo-calendar-day-head';
      html += `<div class="${headClass}">${name}</div>`;
    });
    for (let i = 0; i < 42; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      const dateKey = toYmd(d);
      const dayItems = (byDate[dateKey] || []).slice().sort((a, b) => String(a.start_datetime).localeCompare(String(b.start_datetime)));
      const isOutside = d.getMonth() !== month;
      const isToday = dateKey === today;
      html += `<div class="koopo-calendar-day ${isOutside ? 'is-outside' : ''} ${isToday ? 'is-today' : ''}">
        <div class="koopo-calendar-day__num">${d.getDate()}</div>
        ${dayItems.slice(0,3).map(b => {
          const color = b.service_color || '#2c7a3c';
          const bg = hexToRgba(color, 0.18);
          const time = formatTime(b.start_datetime);
          const customer = b.customer_name || b.customer_email || 'Guest';
          const customerLabel = b.customer_is_guest ? `${customer} (Guest)` : customer;
          const avatar = buildAvatarHtml(b);
          return `<div class="koopo-cal-event" data-id="${b.id}" style="--event-color:${color};--event-bg:${bg}">
            <div><strong>${escapeHtml(b.service_title || 'Appointment')}</strong></div>
            <div class="koopo-cal-event__customer">${avatar}${b.customer_is_guest ? ' <span class="koopo-guest-badge">Guest</span>' : ''}</div>
            <div class="koopo-cal-event__time">${escapeHtml(time)}</div>
          </div>`;
        }).join('')}
        ${dayItems.length > 3 ? `<div class="koopo-cal-event__time">+${dayItems.length - 3} more</div>` : ''}
      </div>`;
    }
    html += '</div>';
    $calendarBody.html(html);
  }

  function renderWeekView(items, cursor){
    if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
      renderWeekViewMobile(items, cursor);
      return;
    }
    const start = weekStart(cursor);
    const days = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      days.push(d);
    }

    let header = '<div class="koopo-week-view">';
    header += '<div class="koopo-week-header"><div class="koopo-week-header__time"></div>';
    days.forEach(d => {
      const isToday = toYmd(d) === toYmd(new Date());
      header += `<div class="koopo-week-header__day ${isToday ? 'is-today' : ''}">${d.toLocaleDateString(undefined, { weekday:'long' })} ${d.getDate()}</div>`;
    });
    header += '</div>';
    header += '<div class="koopo-week-body"><div class="koopo-week-time">';
    for (let h = 0; h < 24; h++) {
      header += `<div class="koopo-week-time__slot">${formatHourLabel(h)}</div>`;
    }
    header += '</div><div class="koopo-week-days">';

    days.forEach(d => {
      const dayKey = toYmd(d);
      header += `<div class="koopo-week-day" data-day="${dayKey}"></div>`;
    });
    header += '</div></div></div>';
    $calendarBody.html(header);

    const hourHeight = 48;
    items.forEach(b => {
      const startDt = parseDateTime(b.start_datetime);
      const endDt = parseDateTime(b.end_datetime);
      if (!startDt || !endDt) return;
      const dayKey = toYmd(startDt);
      const $dayCol = $calendarBody.find(`.koopo-week-day[data-day="${dayKey}"]`);
      if (!$dayCol.length) return;
      const startMinutes = startDt.getHours() * 60 + startDt.getMinutes();
      const endMinutes = endDt.getHours() * 60 + endDt.getMinutes();
      const top = (startMinutes / 60) * hourHeight;
      const height = Math.max(24, ((endMinutes - startMinutes) / 60) * hourHeight);
      const color = b.service_color || '#2c7a3c';
      const bg = hexToRgba(color, 0.18);
      const avatar = '';
      const eventHtml = `
        <div class="koopo-cal-event" data-id="${b.id}" style="--event-color:${color};--event-bg:${bg};top:${top}px;height:${height}px;">
          <div><strong>${escapeHtml(b.service_title || 'Appointment')}</strong></div>
        </div>`;
      $dayCol.append(eventHtml);
    });
  }

  function renderWeekViewMobile(items, cursor){
    const start = weekStart(cursor);
    const days = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      days.push(d);
    }

    const todayKey = toYmd(new Date());
    const defaultDay = days.find(d => toYmd(d) === todayKey) || days[0];
    if (!calendarState.mobileDay) {
      calendarState.mobileDay = toYmd(defaultDay);
    }

    const selectedKey = calendarState.mobileDay;
    const selectedDate = days.find(d => toYmd(d) === selectedKey) || defaultDay;

    const monthLabel = selectedDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    let html = `<div class="koopo-week-mobile">
      <div class="koopo-week-mobile__top">
        <div class="koopo-week-mobile__month">${monthLabel}</div>
      </div>
      <div class="koopo-week-mobile__days">`;

    days.forEach(d => {
      const key = toYmd(d);
      const isActive = key === selectedKey;
      html += `
        <button type="button" class="koopo-week-mobile__day ${isActive ? 'is-active' : ''}" data-day="${key}">
          <span class="koopo-week-mobile__day-name">${d.toLocaleDateString(undefined, { weekday: 'narrow' })}</span>
          <span class="koopo-week-mobile__day-num">${String(d.getDate()).padStart(2,'0')}</span>
        </button>`;
    });

    html += '</div>';

    const dayItems = items
      .filter(b => String(b.start_datetime || '').slice(0,10) === selectedKey)
      .sort((a, b) => String(a.start_datetime).localeCompare(String(b.start_datetime)));

    if (!dayItems.length) {
      html += `<div class="koopo-week-mobile__empty">No appointments</div>`;
    } else {
      html += '<div class="koopo-week-mobile__list">';
      dayItems.forEach(b => {
        const startDt = parseDateTime(b.start_datetime);
        const endDt = parseDateTime(b.end_datetime);
        const time = formatTime(b.start_datetime);
        const duration = (startDt && endDt) ? (endDt.getTime() - startDt.getTime()) / 60000 : 0;
        const durationLabel = formatDurationLabel(duration);
        const color = b.service_color || '#2c7a3c';
        html += `
          <div class="koopo-week-mobile__row" data-id="${b.id}">
            <div class="koopo-week-mobile__time">${escapeHtml(time)}</div>
            <div class="koopo-week-mobile__card" style="--event-color:${color}">
              <div class="koopo-week-mobile__title">${escapeHtml(b.service_title || 'Appointment')}</div>
              <div class="koopo-week-mobile__duration">${escapeHtml(durationLabel)}</div>
            </div>
          </div>`;
      });
      html += '</div>';
    }

    html += '</div>';
    $calendarBody.html(html);
  }

  function renderDayView(items, cursor){
    const dayKey = toYmd(cursor);
    const todayKey = toYmd(new Date());
    const html = '<div class="koopo-week-view koopo-day-view">' +
      '<div class="koopo-week-header"><div class="koopo-week-header__time"></div>' +
      `<div class="koopo-week-header__day ${dayKey === todayKey ? 'is-today' : ''}">${cursor.toLocaleDateString(undefined, { weekday:'long', month:'short', day:'numeric' })}</div>` +
      '</div><div class="koopo-week-body"><div class="koopo-week-time">';
    let body = '';
    for (let h = 0; h < 24; h++) {
      const isCurrent = dayKey === todayKey && new Date().getHours() === h;
      body += `<div class="koopo-week-time__slot ${isCurrent ? 'is-current' : ''}">${formatHourLabel(h)}</div>`;
    }
    body += '</div><div class="koopo-week-days"><div class="koopo-week-day" data-day="' + dayKey + '"></div></div></div></div>';
    $calendarBody.html(html + body);

    const hourHeight = 48;
    items.forEach(b => {
      const startDt = parseDateTime(b.start_datetime);
      const endDt = parseDateTime(b.end_datetime);
      if (!startDt || !endDt) return;
      const startMinutes = startDt.getHours() * 60 + startDt.getMinutes();
      const endMinutes = endDt.getHours() * 60 + endDt.getMinutes();
      const top = (startMinutes / 60) * hourHeight;
      const height = Math.max(24, ((endMinutes - startMinutes) / 60) * hourHeight);
      const color = b.service_color || '#2c7a3c';
      const bg = hexToRgba(color, 0.18);
      const customer = b.customer_name || b.customer_email || 'Guest';
      const customerLabel = b.customer_is_guest ? `${customer} (Guest)` : customer;
      const avatar = '';
      const eventHtml = `
        <div class="koopo-cal-event" data-id="${b.id}" style="--event-color:${color};--event-bg:${bg};top:${top}px;height:${height}px;">
          <div><strong>${escapeHtml(b.service_title || 'Appointment')}</strong></div>
          <div class="koopo-cal-event__customer">${escapeHtml(customerLabel)}</div>
          <div class="koopo-cal-event__time">${escapeHtml(formatTime(b.start_datetime))}</div>
        </div>`;
      $calendarBody.find('.koopo-week-day').append(eventHtml);
    });
  }

  function renderAgendaView(items, start, end){
    const byDate = {};
    items.forEach(b => {
      const d = String(b.start_datetime || '').slice(0,10);
      if (!d) return;
      if (!byDate[d]) byDate[d] = [];
      byDate[d].push(b);
    });

    const dates = [];
    const cur = new Date(start);
    const endDate = new Date(end);
    while (cur <= endDate) {
      dates.push(toYmd(cur));
      cur.setDate(cur.getDate() + 1);
    }

    let html = '<div class="koopo-agenda">';
    dates.forEach(dateKey => {
      const dayItems = (byDate[dateKey] || []).slice().sort((a, b) => String(a.start_datetime).localeCompare(String(b.start_datetime)));
      const label = new Date(dateKey + 'T00:00:00').toLocaleDateString(undefined, { weekday:'long', month:'short', day:'numeric' });
      html += `<div class="koopo-agenda-day"><div class="koopo-agenda-day__title">${label}</div>`;
      if (!dayItems.length) {
        html += `<div class="koopo-agenda-empty">No appointments</div>`;
      } else {
        html += '<div class="koopo-agenda-list">';
        dayItems.forEach(b => {
          const time = formatTime(b.start_datetime);
          const customer = b.customer_name || b.customer_email || 'Guest';
          const customerLabel = b.customer_is_guest ? `${customer} (Guest)` : customer;
          const color = b.service_color || '#2c7a3c';
          html += `
            <div class="koopo-agenda-item" data-id="${b.id}" style="--event-color:${color}">
              <div class="koopo-agenda-item__time">${escapeHtml(time)}</div>
              <div class="koopo-agenda-item__title">${escapeHtml(b.service_title || 'Appointment')}</div>
              <div class="koopo-agenda-item__meta">${escapeHtml(customerLabel)}</div>
            </div>`;
        });
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    $calendarBody.html(html);
  }

  async function loadCalendar(){
    if (!$apptCalendar.length) return;
    if (!apptState.listingId) {
      $calendarBody.html('<div class="koopo-muted">Pick a listing to load appointments.</div>');
      return;
    }

    const view = calendarState.view;
    const cursor = calendarState.cursor;

    let rangeStart;
    let rangeEnd;
    if (view === 'month') {
      const start = monthStart(cursor);
      const end = new Date(start.getFullYear(), start.getMonth() + 1, 0);
      end.setHours(23,59,59,999);
      rangeStart = toYmdHms(start);
      rangeEnd = toYmdHms(end);
      $calendarTitle.text(cursor.toLocaleDateString(undefined, { month:'long', year:'numeric' }));
    } else if (view === 'week') {
      const start = weekStart(cursor);
      const end = new Date(start);
      end.setDate(start.getDate() + 6);
      end.setHours(23,59,59,999);
      rangeStart = toYmdHms(start);
      rangeEnd = toYmdHms(end);
      $calendarTitle.text(`${start.toLocaleDateString(undefined, { month:'short', day:'numeric' })} - ${end.toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' })}`);
    } else if (view === 'agenda') {
      const start = weekStart(cursor);
      const end = new Date(start);
      end.setDate(start.getDate() + 6);
      end.setHours(23,59,59,999);
      rangeStart = toYmdHms(start);
      rangeEnd = toYmdHms(end);
      $calendarTitle.text(`Agenda • ${start.toLocaleDateString(undefined, { month:'short', day:'numeric' })} - ${end.toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' })}`);
    } else {
      const start = new Date(cursor);
      start.setHours(0,0,0,0);
      const end = new Date(start);
      end.setHours(23,59,59,999);
      rangeStart = toYmdHms(start);
      rangeEnd = toYmdHms(end);
      $calendarTitle.text(cursor.toLocaleDateString(undefined, { weekday:'long', month:'long', day:'numeric', year:'numeric' }));
    }

    $calendarBody.html('<div class="koopo-loading-inline"><div class="koopo-spinner"></div><div>Loading calendar...</div></div>');
    try {
      const params = {
        listing_id: apptState.listingId,
        status: apptState.status || 'all',
        per_page: '500',
        range_start: rangeStart,
        range_end: rangeEnd,
      };
      const qs = new URLSearchParams(params);
      const data = await api(`/vendor/bookings?${qs.toString()}`, { method: 'GET' });
      const items = data.items || [];
      apptItemsMap = {};
      items.forEach(b => { apptItemsMap[b.id] = b; });
      calendarState.items = items;

      if (view === 'month') renderMonthView(items, cursor);
      if (view === 'week') renderWeekView(items, cursor);
      if (view === 'day') renderDayView(items, cursor);
      if (view === 'agenda') {
        const start = weekStart(cursor);
        const end = new Date(start);
        end.setDate(start.getDate() + 6);
        renderAgendaView(items, start, end);
      }
    } catch (e) {
      $calendarBody.html('<div class="koopo-muted">Failed to load calendar.</div>');
      console.error(e);
    }
  }

  async function loadAppointments(){
    if (!$apptTable.length) return;
    if (!apptState.listingId) {
      $apptTable.html('<div class="koopo-muted">Pick a listing to load appointments.</div>');
      $apptPager.html('');
      return;
    }
    $apptTable.html('<div class="koopo-loading-inline"><div class="koopo-spinner"></div><div>Loading appointments...</div></div>');
    try {
      const params = {
        listing_id: apptState.listingId,
        status: apptState.status || 'all',
        page: String(apptState.page),
        per_page: String(apptState.perPage),
      };

      if (apptState.search) params.search = apptState.search;
      if (apptState.month) params.month = apptState.month;
      if (apptState.year) params.year = apptState.year;

      const qs = new URLSearchParams(params);
      const data = await api(`/vendor/bookings?${qs.toString()}`, { method: 'GET' });
      const items = data.items || [];
      apptState.totalPages = (data.pagination && data.pagination.total_pages) ? Number(data.pagination.total_pages) : 1;
      apptItemsMap = {};
      items.forEach(b => { apptItemsMap[b.id] = b; });

      if (!items.length) {
        $apptTable.html('<div class="koopo-muted">No appointments found.</div>');
        renderPager();
        return;
      }

      const baseAdmin = (window.ajaxurl || '').replace('/admin-ajax.php','');
      const rows = items.map(b => {
        const isConflict = String(b.status || '').toLowerCase() === 'conflict';
        const rowClass = isConflict ? 'class="koopo-row--conflict"' : '';
        
        const orderLink = b.wc_order_id && baseAdmin
          ? `<a href="${escapeHtml(baseAdmin)}/post.php?post=${b.wc_order_id}&action=edit" target="_blank">#${b.wc_order_id}</a>`
          : (b.wc_order_id ? '#'+b.wc_order_id : '—');

        // Commit 22: Use formatted dates from API
        const dateTimeDisplay = b.start_datetime_formatted || escapeHtml(b.start_datetime || '');
        const endTimeDisplay = b.end_datetime_formatted || '';
        const durationDisplay = b.duration_formatted || '—';

        const bookedAtDisplay = b.created_at_formatted || escapeHtml(b.created_at || '—');

        const serviceColor = b.service_color ? escapeHtml(b.service_color) : '';
        const serviceDot = `<span class="koopo-service-color" style="background:${serviceColor || '#e5e5e5'}"></span>`;
        const serviceCell = `<span class="koopo-service-cell" style="--service-color:${serviceColor || '#e5e5e5'}">${serviceDot}${escapeHtml(b.service_title || '')}</span>`;
        const guestBadge = b.customer_is_guest ? '<span class="koopo-guest-badge">Guest</span>' : '';
        const customerName = b.customer_name || b.customer_email || '—';
        const customerHtml = buildAvatarHtml(b);
        const customerLabel = `${customerHtml}${b.customer_is_guest ? ' <span class="koopo-guest-badge">Guest</span>' : ''}${!b.customer_avatar ? ` ${escapeHtml(customerName)}` : ''}`;

        return `
          <tr ${rowClass} data-id="${b.id}" data-start="${escapeHtml(b.start_datetime)}" data-end="${escapeHtml(b.end_datetime)}">
            <td data-label="Booking">#${b.id}</td>
            <td data-label="Customer">${customerLabel}</td>
            <td data-label="Service">${serviceCell}</td>
            <td data-label="When">
              <strong>${dateTimeDisplay}</strong><br>
              <span style="opacity:0.7;font-size:12px;">to ${endTimeDisplay} (${durationDisplay})</span>
            </td>
            <td class="koopo-col-booked" data-label="Booked On" style="font-size:13px;opacity:0.8;">${bookedAtDisplay}</td>
            <td data-label="Status">${badgeForStatus(b.status)}</td>
            <td data-label="Total">${fmtMoney(b.price, b.currency)}</td>
            <td class="koopo-col-order" data-label="Order">${orderLink}</td>
            <td data-label="Actions">${actionsForBooking(b)}</td>
          </tr>
        `;
      }).join('');

      $apptTable.html(`
        <table class="koopo-table">
          <thead>
            <tr>
              <th>Booking</th>
              <th>Customer</th>
              <th>Service</th>
              <th>When</th>
              <th class="koopo-col-booked">Booked On</th>
              <th>Status</th>
              <th>Total</th>
              <th class="koopo-col-order">Order</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      `);

      renderPager();
    } catch (e) {
      $apptTable.html('<div class="koopo-muted">Failed to load appointments.</div>');
      $apptPager.html('');
      console.error(e);
    }
  }

  if ($apptPicker.length) {
    async function loadApptServices(listingId){
      if (!listingId) return;
      try {
        const services = await api(`/services/by-listing/${listingId}`, { method:'GET' });
        apptServices = (services || []).filter(s => !s.is_addon);
        apptAddons = (services || []).filter(s => s.is_addon);
        apptServiceMap = {};
        (services || []).forEach(s => { apptServiceMap[s.id] = s; });
        const $select = $('#koopo-appt-service');
        if ($select.length) {
          $select.html('<option value="">Select a service...</option>');
          apptServices.forEach(s => {
            $select.append(`<option value="${s.id}">${escapeHtml(s.title)}</option>`);
          });
        }
        renderAddonOptions();
      } catch (e) {
        console.error('Failed to load services', e);
      }
    }

    async function loadApptTimezone(listingId){
      if (!listingId) return;
      try {
        const settings = await api(`/appointments/settings/${listingId}`, { method:'GET' });
        apptTimezone = settings.timezone || '';
      } catch (e) {
        apptTimezone = '';
      }
    }

    loadVendorListings($apptPicker).catch(()=>{});
    $apptPicker.on('change', function(){
      apptState.listingId = $(this).val() || '';
      apptState.page = 1;
      loadAppointments();
      if (apptState.view === 'calendar') loadCalendar();
      loadApptServices(apptState.listingId);
      loadApptTimezone(apptState.listingId);
    });
    $apptStatus.on('change', function(){
      apptState.status = $(this).val() || 'all';
      apptState.page = 1;
      loadAppointments();
      if (apptState.view === 'calendar') loadCalendar();
    });

    // Search with debounce
    let searchTimeout;
    $apptSearch.on('input', function(){
      clearTimeout(searchTimeout);
      const val = $(this).val().trim();
      searchTimeout = setTimeout(function(){
        apptState.search = val;
        apptState.page = 1;
        loadAppointments();
      }, 500);
    });

    // Month filter
    $apptMonth.on('change', function(){
      apptState.month = $(this).val() || '';
      apptState.page = 1;
      loadAppointments();
    });

    // Year filter
    $apptYear.on('change', function(){
      apptState.year = $(this).val() || '';
      apptState.page = 1;
      loadAppointments();
    });

    $viewButtons.on('click', function(){
      const view = $(this).data('view');
      if (view) setAppointmentsView(view);
    });

    $calViewButtons.on('click', function(){
      const view = $(this).data('view');
      if (view) setCalendarView(view);
    });

    $calPrev.on('click', function(){
      const d = new Date(calendarState.cursor);
      if (calendarState.view === 'month') d.setMonth(d.getMonth() - 1);
      if (calendarState.view === 'week') d.setDate(d.getDate() - 7);
      if (calendarState.view === 'day') d.setDate(d.getDate() - 1);
      calendarState.cursor = d;
      loadCalendar();
    });

    $calNext.on('click', function(){
      const d = new Date(calendarState.cursor);
      if (calendarState.view === 'month') d.setMonth(d.getMonth() + 1);
      if (calendarState.view === 'week') d.setDate(d.getDate() + 7);
      if (calendarState.view === 'day') d.setDate(d.getDate() + 1);
      calendarState.cursor = d;
      loadCalendar();
    });

    $calToday.on('click', function(){
      calendarState.cursor = new Date();
      loadCalendar();
    });

    function setCreateModalLoading(isLoading, message){
      const $loading = $apptCreateModal.find('.koopo-modal__loading');
      if (message) $loading.find('.koopo-modal__loading-text').text(message);
      $loading.toggle(!!isLoading);
      $apptCreateModal.find('.koopo-modal__card :input, .koopo-modal__card button').prop('disabled', !!isLoading);
    }

    $apptCreate.on('click', async function(){
      if (!apptState.listingId) {
        alert('Please select a listing first.');
        return;
      }
      $apptCreateModal.show();
      setCreateModalLoading(true, 'Loading form...');
      selectedAddonIds = [];
      $('#koopo-appt-addon-selected').empty();
      await loadApptServices(apptState.listingId);
      await loadApptTimezone(apptState.listingId);
      setCreateModalLoading(false);
    });

    function closeModalOnOverlay($modal){
      $modal.on('click', function(e){
        if ($(e.target).is($modal)) {
          $modal.hide();
        }
      });
      $modal.on('click', '.koopo-modal__close', function(){
        $modal.hide();
      });
    }

    closeModalOnOverlay($apptCreateModal);
    closeModalOnOverlay($apptDetailsModal);

    $apptCreateModal.on('click', '#koopo-appt-create-cancel', function(){
      $apptCreateModal.hide();
    });

    $apptCreateModal.on('change', 'input[name="koopo-appt-customer-type"]', function(){
      const type = $(this).val();
      if (type === 'guest') {
        $('.koopo-appt-customer--user').hide();
        $('.koopo-appt-customer--guest').show();
      } else {
        $('.koopo-appt-customer--guest').hide();
        $('.koopo-appt-customer--user').show();
      }
    });

    function syncEndTime(){
      const serviceId = parseInt($('#koopo-appt-service').val(), 10) || 0;
      const startVal = $('#koopo-appt-start-time').val();
      if (!serviceId || !startVal) return;
      const svc = apptServiceMap[serviceId];
      if (!svc || !svc.duration_minutes) return;
      const [h, m] = startVal.split(':').map(Number);
      const startMinutes = (h * 60) + m;
      const endMinutes = startMinutes + Number(svc.duration_minutes);
      const endH = Math.floor(endMinutes / 60) % 24;
      const endM = endMinutes % 60;
      const endVal = `${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
      if (!$('#koopo-appt-end-time').val()) {
        $('#koopo-appt-end-time').val(endVal);
      }
      updateAppointmentTotal();
    }

    $('#koopo-appt-service').on('change', function(){
      syncEndTime();
      updateAppointmentTotal();
    });
    $('#koopo-appt-start-time').on('change', syncEndTime);

    function renderAddonOptions(){
      const $options = $('#koopo-appt-addon-options');
      const $selected = $('#koopo-appt-addon-selected');
      if (!$options.length || !$selected.length) return;
      $options.empty();
      selectedAddonIds = [];
      $selected.empty();
      if (!apptAddons.length) {
        $options.html('<div class="koopo-muted">No add-on services available.</div>');
        updateAppointmentTotal();
        return;
      }
      apptAddons.forEach(s => {
        const price = Number(s.price || 0).toFixed(2);
        $options.append(`
          <button type="button" class="koopo-appt-addon-btn" data-id="${s.id}">
            <span>${escapeHtml(s.title)}</span>
            <span><strong>${currencySymbol()}${price}</strong> <span class="koopo-appt-addon-plus">+</span></span>
          </button>
        `);
      });
      updateAppointmentTotal();
    }

    function currencySymbol(){
      return (window.KOOPO_APPT_VENDOR && KOOPO_APPT_VENDOR.currency_symbol) ? KOOPO_APPT_VENDOR.currency_symbol : '$';
    }

    function updateAppointmentTotal(){
      const serviceId = parseInt($('#koopo-appt-service').val(), 10) || 0;
      const base = serviceId && apptServiceMap[serviceId] ? Number(apptServiceMap[serviceId].price || 0) : 0;
      const addonsTotal = selectedAddonIds.reduce((sum, id) => {
        const svc = apptServiceMap[id];
        return sum + (svc ? Number(svc.price || 0) : 0);
      }, 0);
      const total = base + addonsTotal;
      $('#koopo-appt-total-amount').text(`${currencySymbol()}${total.toFixed(2)}`);
    }

    function renderSelectedAddons(){
      const $selected = $('#koopo-appt-addon-selected');
      $selected.empty();
      selectedAddonIds.forEach(id => {
        const svc = apptServiceMap[id];
        if (!svc) return;
        $selected.append(`
          <span class="koopo-appt-addon-chip" data-id="${id}">
            ${escapeHtml(svc.title)}
            <button type="button" aria-label="Remove">×</button>
          </span>
        `);
      });
      updateAppointmentTotal();
    }

    $(document).on('click', '.koopo-appt-addon-btn', function(){
      const id = parseInt($(this).data('id'), 10) || 0;
      if (!id || selectedAddonIds.includes(id)) return;
      selectedAddonIds.push(id);
      renderSelectedAddons();
    });

    $(document).on('click', '.koopo-appt-addon-chip button', function(){
      const id = parseInt($(this).closest('.koopo-appt-addon-chip').data('id'), 10) || 0;
      selectedAddonIds = selectedAddonIds.filter(x => x !== id);
      renderSelectedAddons();
    });

    $('#koopo-appt-create-save').on('click', async function(){
      const listingId = apptState.listingId;
      const serviceId = parseInt($('#koopo-appt-service').val(), 10) || 0;
      const date = $('#koopo-appt-date').val();
      const startTime = $('#koopo-appt-start-time').val();
      const endTime = $('#koopo-appt-end-time').val();
      const status = $('#koopo-appt-status').val() || 'confirmed';

      if (!listingId || !serviceId || !date || !startTime || !endTime) {
        alert('Please fill in service, date, start time, and end time.');
        return;
      }

      const startDateTime = `${date} ${startTime}:00`;
      const endDateTime = `${date} ${endTime}:00`;

      const type = $('input[name="koopo-appt-customer-type"]:checked').val();
      const payload = {
        listing_id: listingId,
        service_id: serviceId,
        start_datetime: startDateTime,
        end_datetime: endDateTime,
        timezone: apptTimezone,
        status: status,
        addon_ids: selectedAddonIds,
      };

      if (type === 'guest') {
        payload.customer_name = $('#koopo-appt-guest-name').val().trim();
        payload.customer_email = $('#koopo-appt-guest-email').val().trim();
        payload.customer_phone = $('#koopo-appt-guest-phone').val().trim();
      } else {
        const userId = parseInt($('#koopo-appt-user-id').val(), 10) || 0;
        const userEmail = $('#koopo-appt-user-email').val().trim();
        if (userId) payload.customer_id = userId;
        if (userEmail) payload.customer_email = userEmail;
      }

      const notes = $('#koopo-appt-notes').val().trim();
      if (notes) payload.customer_notes = notes;

      const $btn = $(this);
      $btn.prop('disabled', true).text('Creating...');
      setCreateModalLoading(true, 'Creating appointment...');
      try {
        await api('/vendor/bookings/create', { method:'POST', body: JSON.stringify(payload) });
        $apptCreateModal.hide();
        selectedAddonIds = [];
        $('#koopo-appt-addon-selected').empty();
        loadAppointments();
        if (apptState.view === 'calendar') loadCalendar();
        alert('Appointment created.');
      } catch (e) {
        alert(e.message || 'Failed to create appointment.');
      } finally {
        setCreateModalLoading(false);
        $btn.prop('disabled', false).text('Create Appointment');
      }
    });

    $apptTable.on('click', 'tbody tr', function(e){
      if ($(e.target).closest('button,a').length) return;
      const id = parseInt($(this).data('id'), 10) || 0;
      const booking = apptItemsMap[id];
      if (booking) openApptDetails(booking);
    });

    $calendarBody.on('click', '.koopo-cal-event', function(e){
      if ($(e.target).closest('a').length) return;
      const id = parseInt($(this).data('id'), 10) || 0;
      const booking = apptItemsMap[id] || calendarState.items.find(b => b.id === id);
      if (booking) openApptDetails(booking);
    });

    $calendarBody.on('click', '.koopo-agenda-item', function(){
      const id = parseInt($(this).data('id'), 10) || 0;
      const booking = apptItemsMap[id] || calendarState.items.find(b => b.id === id);
      if (booking) openApptDetails(booking);
    });

    $calendarBody.on('click', '.koopo-week-mobile__day', function(){
      const day = $(this).data('day');
      if (!day) return;
      calendarState.mobileDay = day;
      renderWeekView(calendarState.items, calendarState.cursor);
    });

    $calendarBody.on('click', '.koopo-week-mobile__row', function(){
      const id = parseInt($(this).data('id'), 10) || 0;
      const booking = apptItemsMap[id] || calendarState.items.find(b => b.id === id);
      if (booking) openApptDetails(booking);
    });

    $apptDetailsModal.on('click', '#koopo-appt-details-close', function(){
      $apptDetailsModal.hide();
    });

    function openApptDetails(b){
      $('#koopo-appt-details-customer').html(buildAvatarWithNameHtml(b) + (b.customer_is_guest ? ' <span class="koopo-guest-badge">Guest</span>' : ''));
      const metaParts = [];
      if (b.customer_email) metaParts.push(`<a class="koopo-link-pill" href="mailto:${escapeHtml(b.customer_email)}">Email</a>`);
      if (b.customer_phone) metaParts.push(`<a class="koopo-link-pill" href="tel:${escapeHtml(b.customer_phone)}">Call</a>`);
      if (b.customer_profile) metaParts.push(`<a class="koopo-link-pill" href="${escapeHtml(b.customer_profile)}" target="_blank" rel="noopener">Profile</a>`);
      $('#koopo-appt-details-meta').html(metaParts.join(' ') || '—');
      $('#koopo-appt-details-service').html(`<span class="koopo-service-cell" style="--service-color:${escapeHtml(b.service_color || '#e5e5e5')}"><span class="koopo-service-color" style="background:${escapeHtml(b.service_color || '#e5e5e5')}"></span>${escapeHtml(b.service_title || '')}</span>`);
      $('#koopo-appt-details-when').text(`${b.start_datetime_formatted || b.start_datetime} - ${b.end_datetime_formatted || ''}`);
      $('#koopo-appt-details-status').html(badgeForStatus(b.status));
      $('#koopo-appt-details-total').text(fmtMoney(b.price, b.currency));
      const adminBase = (window.KOOPO_APPT_VENDOR && KOOPO_APPT_VENDOR.admin_url)
        ? String(KOOPO_APPT_VENDOR.admin_url).replace(/\/$/, '')
        : (window.ajaxurl || '').replace('/admin-ajax.php','');
      const orderLink = b.wc_order_id && adminBase
        ? `<a href="${escapeHtml(adminBase)}/post.php?post=${b.wc_order_id}&action=edit" target="_blank">#${b.wc_order_id}</a>`
        : (b.wc_order_id ? '#'+b.wc_order_id : '—');
      $('#koopo-appt-details-order').html(orderLink);
      $apptDetailsActions.html(actionsForBooking(b));
      $apptDetailsModal.show();
    }

    // CSV Export
    $apptExport.on('click', async function(){
      if (!apptState.listingId) {
        alert('Please select a listing first.');
        return;
      }

      const $btn = $(this);
      $btn.prop('disabled', true).text('Exporting...');

      try {
        const params = {
          listing_id: apptState.listingId,
          status: apptState.status || 'all',
          export: 'csv',
        };

        if (apptState.search) params.search = apptState.search;
        if (apptState.month) params.month = apptState.month;
        if (apptState.year) params.year = apptState.year;

        const qs = new URLSearchParams(params);
        const url = `${KOOPO_APPT_VENDOR.rest}/vendor/bookings/export?${qs.toString()}`;

        // Create download link
        const a = document.createElement('a');
        a.href = url;
        a.download = `appointments-${apptState.listingId}-${Date.now()}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        alert('✓ CSV export started. Check your downloads.');
      } catch (e) {
        alert('Export failed: ' + (e.message || 'Unknown error'));
      } finally {
        $btn.prop('disabled', false).text('Export to CSV');
      }
    });

    function handleBasicAction($btn){
      const id = parseInt($btn.data('id'), 10) || 0;
      const action = String($btn.data('action')||'').toLowerCase();
      if (!id || !action) return;

      if (action === 'refund' || action === 'reschedule') {
        return;
      }

      let note = '';
      let start_datetime = '';
      let end_datetime = '';
      let timezone = '';

      if (action === 'cancel') {
        note = prompt('Optional: add a cancellation note for the customer/admin (leave blank for none).', '') || '';
        const ok = confirm('Cancel this booking? If it was already paid, you may need to issue a refund separately.');
        if (!ok) return;
      } else if(action === 'confirm') {
        const ok = confirm('Manually confirm this booking? This should only be used if payment was verified outside the system.');
        if (!ok) return;
      } else if (action === 'note') {
        note = prompt('Enter an internal note for this booking/order:', '') || '';
        if (!note.trim()) return;
      } else { return;}

      $btn.prop('disabled', true);

      (async () => {
        try {
          const payload = { action, note, start_datetime, end_datetime, timezone };
          await api(`/vendor/bookings/${id}/action`, {
            method: 'POST',
            body: JSON.stringify(payload)
          });

          await loadAppointments();
          if (apptState.view === 'calendar') loadCalendar();

          if (action === 'cancel') {
            alert('✓ Booking cancelled successfully.');
          } else if (action === 'confirm') {
            alert('✓ Booking confirmed.');
          } else if (action === 'note') {
            alert('✓ Note added to order.');
          }
        } catch (err) {
          const msg = err.message || 'Action failed';
          if (msg.includes('conflict')) {
            alert('⚠️ Cannot reschedule: the selected time conflicts with another booking.\n\nPlease choose a different time.');
          } else {
            alert('⚠️ Action failed: ' + msg);
          }
        } finally {
          $btn.prop('disabled', false);
        }
      })();
    }

    // Commit 19+22: Enhanced vendor actions with formatted dates
    $apptTable.on('click', '.koopo-appt-action', function(e){
      e.preventDefault();
      handleBasicAction($(this));
    });

    $apptDetailsActions.on('click', '.koopo-appt-action', function(e){
      e.preventDefault();
      handleBasicAction($(this));
    });

    $apptPager.on('click', 'button[data-page]', function(){
      const p = Number($(this).data('page'));
      if (!p) return;
      apptState.page = p;
      loadAppointments();
    });
  }

    async function showRefundModal(bookingId) {
    try {
      // Get refund info from API
      const info = await api(`/vendor/bookings/${bookingId}/refund-info`, { method: 'GET' });
      
      const policy = info.policy || {};
      const wc = info.woocommerce || {};
      const bookingPrice = info.booking_price || 0;

      // Build modal HTML
      let modalHtml = `
        <div class="koopo-modal koopo-refund-modal">
          <div class="koopo-modal__card">
            <button class="koopo-modal__close koopo-refund-modal__close" type="button">&times;</button>
            <h3>Process Refund</h3>
      `;

      // Show refund eligibility
      if (!policy.refundable) {
        modalHtml += `
          <div class="koopo-refund-warning">
            <strong>⚠️ Refund Not Available</strong>
            <p>${escapeHtml(policy.message || 'This booking cannot be refunded.')}</p>
          </div>
          <div class="koopo-modal__footer">
            <button type="button" class="koopo-btn koopo-refund-modal__close">Close</button>
          </div>
        `;
      } else {
        // Refundable - show policy and options
        modalHtml += `
          <div class="koopo-refund-summary">
            <div class="koopo-refund-row">
              <span>Booking Price:</span>
              <strong>$${bookingPrice.toFixed(2)}</strong>
            </div>
        `;

        if (policy.fee > 0) {
          modalHtml += `
            <div class="koopo-refund-row koopo-refund-row--fee">
              <span>Cancellation Fee:</span>
              <strong>-$${policy.fee.toFixed(2)}</strong>
            </div>
          `;
        }

        modalHtml += `
            <div class="koopo-refund-row koopo-refund-row--total">
              <span>Refund Amount:</span>
              <strong>$${policy.amount.toFixed(2)}</strong>
            </div>
          </div>

          <div class="koopo-refund-policy-note">
            <small>${escapeHtml(policy.message || '')}</small>
          </div>
        `;

        // Show WooCommerce refund method
        if (wc.can_refund) {
          modalHtml += `
            <div class="koopo-refund-gateway">
              <strong>Payment Method:</strong> ${escapeHtml(wc.gateway || 'Unknown')}
              <br>
              ${wc.automatic 
                ? '<span class="koopo-refund-auto">✓ Automatic refund via payment gateway</span>'
                : '<span class="koopo-refund-manual">⚠️ Manual refund required in payment gateway</span>'}
            </div>
          `;

          if (!wc.automatic && wc.instructions) {
            modalHtml += `
              <div class="koopo-refund-instructions">
                <small>${escapeHtml(wc.instructions)}</small>
              </div>
            `;
          }
        } else {
          modalHtml += `
            <div class="koopo-refund-warning">
              <strong>⚠️ Cannot Process Refund</strong>
              <p>${escapeHtml(wc.instructions || 'No order available for refund.')}</p>
            </div>
          `;
        }

        // Refund reason input
        modalHtml += `
          <div class="koopo-refund-form">
            <label class="koopo-label">
              Refund Reason (Optional)
              <textarea class="koopo-input koopo-refund-reason" rows="3" placeholder="Enter reason for refund (visible to customer)"></textarea>
            </label>

            <label class="koopo-label">
              <input type="checkbox" class="koopo-refund-custom-amount-toggle" />
              Use custom refund amount
            </label>

            <label class="koopo-label koopo-refund-custom-amount-field" style="display:none;">
              Custom Amount
              <input type="number" step="0.01" min="0.01" max="${wc.available_amount || bookingPrice}" 
                     class="koopo-input koopo-refund-custom-amount" 
                     value="${policy.amount.toFixed(2)}" />
              <small>Maximum: $${(wc.available_amount || bookingPrice).toFixed(2)}</small>
            </label>
          </div>

          <div class="koopo-modal__footer">
            <button type="button" class="koopo-btn koopo-refund-modal__close">Cancel</button>
            <button type="button" class="koopo-btn koopo-btn--danger koopo-refund-submit" 
                    data-booking-id="${bookingId}">
              Process Refund
            </button>
          </div>
        `;
      }

      modalHtml += `
          </div>
        </div>
      `;

      // Remove existing modal if present
      $('.koopo-refund-modal').remove();

      // Add to DOM
      $('body').append(modalHtml);

      // Show modal
      $('.koopo-refund-modal').fadeIn(200);

    } catch(e) {
      alert('Failed to load refund information: ' + (e.message || 'Unknown error'));
    }
  }

  /**
   * Commit 20: Process refund with enhanced API
   */
  async function processRefund(bookingId, reason, customAmount) {
    const $btn = $(`.koopo-refund-submit[data-booking-id="${bookingId}"]`);
    $btn.prop('disabled', true).text('Processing...');

    try {
      const payload = { action: 'refund', note: reason };
      
      if (customAmount !== null) {
        payload.amount = customAmount;
      }

      const result = await api(`/vendor/bookings/${bookingId}/action`, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      $('.koopo-refund-modal').fadeOut(200, function() {
        $(this).remove();
      });

      // Reload bookings table
      await loadAppointments();

      // Show success message
      let message = `✓ Refund processed: $${result.amount.toFixed(2)}\n\n`;
      
      if (result.automatic) {
        message += 'The refund was processed automatically via your payment gateway.';
      } else {
        message += 'The refund was created in WooCommerce. Please complete the refund manually in your payment gateway.';
      }

      alert(message);

    } catch(err) {
      alert('⚠️ Refund failed: ' + (err.message || 'Unknown error'));
      $btn.prop('disabled', false).text('Process Refund');
    }
  }

  // Event handlers for refund modal
  $(document).on('click', '.koopo-refund-modal__close', function() {
    $('.koopo-refund-modal').fadeOut(200, function() {
      $(this).remove();
    });
  });

  $(document).on('change', '.koopo-refund-custom-amount-toggle', function() {
    $('.koopo-refund-custom-amount-field').toggle($(this).is(':checked'));
  });

  $(document).on('click', '.koopo-refund-submit', async function(e) {
    e.preventDefault();
    
    const bookingId = parseInt($(this).data('booking-id'), 10);
    const reason = $('.koopo-refund-reason').val().trim();
    const useCustom = $('.koopo-refund-custom-amount-toggle').is(':checked');
    const customAmount = useCustom ? parseFloat($('.koopo-refund-custom-amount').val()) : null;

    if (!confirm('Process this refund? This action cannot be undone.')) {
      return;
    }

    await processRefund(bookingId, reason, customAmount);
  });

  // MODIFY EXISTING: Update refund button click handler in appointments table
  // Replace the existing refund button handler with this:
  $(document).on('click', '.koopo-appt-action[data-action="refund"]', async function(e) {
    e.preventDefault();
    const bookingId = parseInt($(this).data('id'), 10);
    if (!bookingId) return;

    await showRefundModal(bookingId);
  });
let rescheduleState = {
    bookingId: null,
    serviceId: null,
    duration: 0,
    currentMonth: new Date(),
    selectedDate: null,
    selectedSlot: null,
    currentStart: '',
    currentEnd: '',
    timezone: '',
  };

  /**
   * Show reschedule modal with calendar UI
   */
  async function showRescheduleModal(bookingId) {
    try {
      // Get booking details
      const bookings = await api(`/vendor/bookings?page=1&per_page=200`, { method: 'GET' });
      const booking = bookings.items.find(b => b.id === bookingId);
      
      if (!booking) {
        alert('Booking not found');
        return;
      }

      // Initialize state
      rescheduleState.bookingId = bookingId;
      rescheduleState.serviceId = booking.service_id;
      rescheduleState.currentStart = booking.start_datetime;
      rescheduleState.currentEnd = booking.end_datetime;
      rescheduleState.timezone = booking.timezone || '';
      
      // Calculate duration
      const startTs = new Date(booking.start_datetime).getTime();
      const endTs = new Date(booking.end_datetime).getTime();
      rescheduleState.duration = Math.round((endTs - startTs) / 60000); // minutes

      // Build modal HTML
      const modalHtml = `
        <div class="koopo-reschedule-modal">
          <div class="koopo-modal__card">
            <button class="koopo-modal__close koopo-reschedule-modal__close" type="button">&times;</button>
            <h3>Reschedule Appointment</h3>
            <div class="koopo-reschedule-message" style="display:none;"></div>
            
            <div class="koopo-reschedule-current">
              <strong>Current Time</strong>
              <div>${booking.start_datetime_formatted || booking.start_datetime} (${rescheduleState.duration} min)</div>
            </div>
            
            <div class="koopo-reschedule-step" data-step="1">
              <h4>Step 1: Select New Date</h4>
              <div id="reschedule-calendar"></div>
            </div>
            
            <div class="koopo-reschedule-step" data-step="2" style="display:none;">
              <h4>Step 2: Select New Time</h4>
              <div class="koopo-reschedule-slots"></div>
            </div>
            
            <div class="koopo-reschedule-summary" style="display:none;">
              <strong>New Time</strong>
              <div class="koopo-reschedule-summary__text"></div>
            </div>
            
            <div class="koopo-modal__footer">
              <button type="button" class="koopo-btn koopo-reschedule-back" style="display:none;">‹ Back</button>
              <button type="button" class="koopo-btn koopo-reschedule-cancel">Cancel</button>
              <button type="button" class="koopo-btn koopo-btn--gold koopo-reschedule-submit" disabled>Reschedule</button>
            </div>
          </div>
        </div>
      `;

      // Remove existing modal
      $('.koopo-reschedule-modal').remove();

      // Add to DOM
      $('body').append(modalHtml);

      // Initialize calendar
      renderCalendar();

      // Show modal
      $('.koopo-reschedule-modal').fadeIn(200);

    } catch (e) {
      alert('Failed to load reschedule UI: ' + (e.message || 'Unknown error'));
    }
  }

  /**
   * Render calendar for current month
   */
  function renderCalendar() {
    const $calendar = $('#reschedule-calendar');
    const month = rescheduleState.currentMonth;
    const year = month.getFullYear();
    const monthIndex = month.getMonth();
    
    // Month names
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
    
    // Build calendar HTML
    let html = `
      <div class="koopo-calendar">
        <div class="koopo-calendar__header">
          <div class="koopo-calendar__month">${monthNames[monthIndex]} ${year}</div>
          <div class="koopo-calendar__nav">
            <button type="button" class="koopo-calendar__prev">‹</button>
            <button type="button" class="koopo-calendar__next">›</button>
          </div>
        </div>
        <div class="koopo-calendar__grid">
          <div class="koopo-calendar__day-name">Sun</div>
          <div class="koopo-calendar__day-name">Mon</div>
          <div class="koopo-calendar__day-name">Tue</div>
          <div class="koopo-calendar__day-name">Wed</div>
          <div class="koopo-calendar__day-name">Thu</div>
          <div class="koopo-calendar__day-name">Fri</div>
          <div class="koopo-calendar__day-name">Sat</div>
    `;

    // Get first day of month and number of days
    const firstDay = new Date(year, monthIndex, 1).getDay();
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const today = new Date();
    const todayStr = formatDate(today);

    // Previous month days (filler)
    const prevMonthDays = new Date(year, monthIndex, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
      const day = prevMonthDays - i;
      html += `<div class="koopo-calendar__date is-other-month">${day}</div>`;
    }

    // Current month days
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, monthIndex, day);
      const dateStr = formatDate(date);
      const isPast = date < today && dateStr !== todayStr;
      const isToday = dateStr === todayStr;
      const isSelected = dateStr === rescheduleState.selectedDate;
      
      let classes = 'koopo-calendar__date';
      if (isPast) classes += ' is-disabled';
      if (isToday) classes += ' is-today';
      if (isSelected) classes += ' is-selected';
      
      html += `<div class="${classes}" data-date="${dateStr}">${day}</div>`;
    }

    // Next month days (filler)
    const totalCells = firstDay + daysInMonth;
    const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let day = 1; day <= remainingCells; day++) {
      html += `<div class="koopo-calendar__date is-other-month">${day}</div>`;
    }

    html += `</div></div>`;
    
    $calendar.html(html);
  }

  /**
   * Load available slots for selected date
   */
  async function loadAvailableSlots(date) {
    const $slotsContainer = $('.koopo-reschedule-slots');
    $slotsContainer.html('<div class="koopo-reschedule-loading">Loading available times...</div>');

    try {
      // Fetch availability from the standard availability endpoint
      // We'll exclude the current booking from conflicts by using the service's availability
      const data = await api(`/availability/by-service/${rescheduleState.serviceId}?date=${encodeURIComponent(date)}`, {
        method: 'GET'
      });

      const slots = data.slots || [];

      if (slots.length === 0) {
        $slotsContainer.html(`
          <div class="koopo-reschedule-empty">
            <strong>No Times Available</strong>
            <p>This date is fully booked or outside business hours. Please select another date.</p>
          </div>
        `);
        return;
      }

      // Normalize + de-dupe + sort slots (API may return duplicates or unsorted results)
      const toDateSafe = (s) => {
        if (!s) return null;
        // Support "YYYY-MM-DD HH:MM:SS" and ISO strings
        const isoish = (typeof s === 'string' && s.includes(' ') && !s.includes('T')) ? s.replace(' ', 'T') : s;
        const d = new Date(isoish);
        return isNaN(d.getTime()) ? null : d;
      };

      const uniqMap = new Map();
      slots.forEach(slot => {
        const key = `${slot.start || ''}|${slot.end || ''}`;
        if (!uniqMap.has(key)) uniqMap.set(key, slot);
      });

      const uniqueSlots = Array.from(uniqMap.values()).sort((a, b) => {
        const da = toDateSafe(a.start);
        const db = toDateSafe(b.start);
        return (da ? da.getTime() : 0) - (db ? db.getTime() : 0);
      });

      // Group slots by time of day based on local hour
      const groups = { Morning: [], Afternoon: [], Evening: [] };
      uniqueSlots.forEach(slot => {
        const d = toDateSafe(slot.start);
        const hour = d ? d.getHours() : 0; // 0-23
        const period = hour < 12 ? 'Morning' : hour < 17 ? 'Afternoon' : 'Evening';
        groups[period].push(slot);
      });

      // Render slots
      let html = '';
      ['Morning', 'Afternoon', 'Evening'].forEach(period => {
        if (groups[period].length === 0) return;
        
        html += `
          <div class="koopo-slot-group">
            <h5>${period}</h5>
            <div class="koopo-slot-grid">
        `;
        
        groups[period].forEach(slot => {
          const isSelected = rescheduleState.selectedSlot && 
                            rescheduleState.selectedSlot.start === slot.start;
          html += `
            <button type="button" 
                    class="koopo-slot ${isSelected ? 'is-selected' : ''}" 
                    data-start="${slot.start}" 
                    data-end="${slot.end}">
              ${slot.label}
            </button>
          `;
        });
        
        html += `</div></div>`;
      });

      $slotsContainer.html(html);

    } catch (e) {
      $slotsContainer.html(`
        <div class="koopo-reschedule-empty">
          <strong>Error Loading Times</strong>
          <p>${e.message || 'Failed to load available times. Please try again.'}</p>
        </div>
      `);
    }
  }

  /**
   * Process reschedule submission
   */
  async function submitReschedule() {
    if (!rescheduleState.selectedSlot) {
      showRescheduleMessage('error', 'Please select a new date and time.');
      return;
    }

    const $btn = $('.koopo-reschedule-submit');
    $btn.prop('disabled', true).text('Processing...');

    try {
      const result = await api(`/vendor/bookings/${rescheduleState.bookingId}/action`, {
        method: 'POST',
        body: JSON.stringify({
          action: 'reschedule',
          start_datetime: rescheduleState.selectedSlot.start,
          end_datetime: rescheduleState.selectedSlot.end,
          timezone: rescheduleState.timezone || '',
        })
      });

      showRescheduleMessage('success', 'Appointment rescheduled successfully. The customer will be notified.');
      setTimeout(() => {
        $('.koopo-reschedule-modal').fadeOut(200, function() {
          $(this).remove();
        });
      }, 1200);

      // Reload bookings table
      if (typeof loadAppointments === 'function') {
        await loadAppointments();
      }

    } catch (err) {
      const msg = err.message || 'Unknown error';
      if (msg.includes('conflict')) {
        showRescheduleMessage('error', 'Cannot reschedule: the selected time conflicts with another booking.');
      } else {
        showRescheduleMessage('error', 'Reschedule failed. ' + msg);
      }
      $btn.prop('disabled', false).text('Reschedule');
    }
  }

  function showRescheduleMessage(type, text) {
    const $msg = $('.koopo-reschedule-message');
    if (!$msg.length) return;
    $msg.removeClass('is-error is-success');
    if (type === 'error') $msg.addClass('is-error');
    if (type === 'success') $msg.addClass('is-success');
    $msg.text(text).show();
  }

  /**
   * Format date as YYYY-MM-DD
   */
  function formatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  /**
   * Format datetime for display
   */
  function formatDateTime(datetime) {
    const date = new Date(datetime);
    const options = { 
      weekday: 'long', 
      year: 'numeric', 
      month: 'long', 
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    };
    return date.toLocaleDateString('en-US', options);
  }

  // ===== Event Handlers =====

  // Close modal
  $(document).on('click', '.koopo-reschedule-modal__close, .koopo-reschedule-cancel', function() {
    $('.koopo-reschedule-modal').fadeOut(200, function() {
      $(this).remove();
    });
  });

  // Calendar navigation
  $(document).on('click', '.koopo-calendar__prev', function() {
    rescheduleState.currentMonth = new Date(
      rescheduleState.currentMonth.getFullYear(),
      rescheduleState.currentMonth.getMonth() - 1,
      1
    );
    renderCalendar();
  });

  $(document).on('click', '.koopo-calendar__next', function() {
    rescheduleState.currentMonth = new Date(
      rescheduleState.currentMonth.getFullYear(),
      rescheduleState.currentMonth.getMonth() + 1,
      1
    );
    renderCalendar();
  });

  // Date selection
  $(document).on('click', '.koopo-calendar__date:not(.is-disabled):not(.is-other-month)', async function() {
    const date = $(this).data('date');
    rescheduleState.selectedDate = date;
    rescheduleState.selectedSlot = null;
    
    // Update UI
    $('.koopo-calendar__date').removeClass('is-selected');
    $(this).addClass('is-selected');
    
    // Show step 2
    $('[data-step="1"]').hide();
    $('[data-step="2"]').show();
    $('.koopo-reschedule-back').show();
    $('.koopo-reschedule-submit').prop('disabled', true);
    $('.koopo-reschedule-summary').hide();
    
    // Load slots
    await loadAvailableSlots(date);
  });

  // Time slot selection
  $(document).on('click', '.koopo-slot:not(:disabled)', function() {
    const start = $(this).data('start');
    const end = $(this).data('end');
    
    rescheduleState.selectedSlot = { start, end };
    
    // Update UI
    $('.koopo-slot').removeClass('is-selected');
    $(this).addClass('is-selected');
    
    // Show summary
    const formatted = formatDateTime(start);
    $('.koopo-reschedule-summary__text').text(`${formatted} (${rescheduleState.duration} min)`);
    $('.koopo-reschedule-summary').show();
    
    // Enable submit
    $('.koopo-reschedule-submit').prop('disabled', false);
  });

  // Back button
  $(document).on('click', '.koopo-reschedule-back', function() {
    $('[data-step="2"]').hide();
    $('[data-step="1"]').show();
    $(this).hide();
    $('.koopo-reschedule-summary').hide();
    $('.koopo-reschedule-submit').prop('disabled', true);
  });

  // Submit reschedule
  $(document).on('click', '.koopo-reschedule-submit', async function(e) {
    e.preventDefault();
    
    if (!confirm('Reschedule this appointment to the selected time?')) {
      return;
    }
    
    await submitReschedule();
  });

  // REPLACE existing reschedule button handler
  $(document).on('click', '.koopo-appt-action[data-action="reschedule"]', async function(e) {
    e.preventDefault();
    const bookingId = parseInt($(this).data('id'), 10);
    if (!bookingId) return;

    await showRescheduleModal(bookingId);
  });

})(jQuery);
