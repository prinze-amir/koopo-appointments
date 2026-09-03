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

  // Date utility functions
  function fmtDate(d){
    const pad = (n)=>String(n).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  }

  function addDays(d, n){
    const x = new Date(d.getTime());
    x.setDate(x.getDate() + n);
    return x;
  }

  function addMonths(d, n){
    const x = new Date(d.getTime());
    x.setMonth(x.getMonth() + n);
    return x;
  }

  function startOfMonth(d){
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }

  function endOfMonth(d){
    return new Date(d.getFullYear(), d.getMonth() + 1, 0);
  }

  function getMonthName(d){
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }

  function getDayOfWeek(d){
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return days[d.getDay()];
  }

  function timeBucket(label){
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

  function formatDateTime(dateStr, timeStr){
    if (!dateStr || !timeStr) return '—';
    const d = new Date(dateStr);
    const dayName = d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    return `${dayName} at ${timeStr}`;
  }

  function formatPhoneInput(value){
    const digits = String(value || '').replace(/\D/g, '');
    if (!digits) return '';
    let local = digits;
    let prefix = '';
    if (digits.length > 10 && digits[0] === '1') {
      prefix = '+1 ';
      local = digits.slice(1, 11);
    } else {
      local = digits.slice(0, 10);
    }
    const parts = [];
    if (local.length <= 3) {
      parts.push(local);
    } else if (local.length <= 6) {
      parts.push(`(${local.slice(0,3)})`, local.slice(3));
    } else {
      parts.push(`(${local.slice(0,3)})`, local.slice(3,6), '-' + local.slice(6));
    }
    return prefix + parts.join(' ');
  }

  function waitlistPrompt($root, date){
    if (!(window.KOOPO_APPT && KOOPO_APPT.features && KOOPO_APPT.features.waitlist)) return '';
    const start = date || fmtDate(new Date());
    const end = fmtDate(addDays(new Date(`${start}T12:00:00`), 30));
    const day = ['sun','mon','tue','wed','thu','fri','sat'][new Date(`${start}T12:00:00`).getDay()];
    return `
      <section class="koopo-appt__waitlist" aria-label="Waitlist">
        <span class="koopo-appt__waitlist-kicker">Opening alerts</span>
        <h4>Want this service on another opening?</h4>
        <p>Join the waitlist and we’ll send a private, expiring offer when a matching time opens.</p>
        <div class="koopo-appt__waitlist-grid">
          <label>From<input type="date" class="koopo-appt__wait-from" value="${start}"></label>
          <label>Through<input type="date" class="koopo-appt__wait-to" value="${end}"></label>
          <label>After<input type="time" class="koopo-appt__wait-earliest" value="08:00"></label>
          <label>Before<input type="time" class="koopo-appt__wait-latest" value="18:00"></label>
        </div>
        <div class="koopo-appt__waitlist-days"><span>Preferred days</span>${['sun','mon','tue','wed','thu','fri','sat'].map(value=>`<label><input type="checkbox" value="${value}" ${value===day?'checked':''}> ${value.charAt(0).toUpperCase()+value.slice(1)}</label>`).join('')}</div>
        <div class="koopo-appt__waitlist-channels">
          <span>Notify me by</span>
          <label><input type="checkbox" value="email" checked> Email</label>
          <label><input type="checkbox" value="push" checked> Push</label>
        </div>
        <button type="button" class="koopo-appt__waitlist-join">Join waitlist</button>
        <span class="koopo-appt__waitlist-status" role="status"></span>
      </section>`;
  }

  // State management
  function getState($root){
    if (!$root.data('koopoState')) {
      $root.data('koopoState', {
        currentMonth: new Date(),
        selectedDate: null,
        selectedService: null,
        selectedServiceName: null,
        selectedTimeLabel: null,
        listingSettings: null,
        currentStep: 1,
        userInfo: null,
        servicesMap: {},
        addonIds: [],
        fulfillmentMode: '',
        coverageVerified: false
      });
    }
    return $root.data('koopoState');
  }

  // Step navigation
  function goToStep($root, step){
    const state = getState($root);
    state.currentStep = step;

    // Update step indicators
    $root.find('.koopo-appt__step').removeClass('koopo-appt__step--active koopo-appt__step--completed');
    $root.find(`.koopo-appt__step[data-step="${step}"]`).addClass('koopo-appt__step--active');

    // Mark completed steps
    for (let i = 1; i < step; i++) {
      $root.find(`.koopo-appt__step[data-step="${i}"]`).addClass('koopo-appt__step--completed');
    }

    // Update panels
    $root.find('.koopo-appt__panel').removeClass('koopo-appt__panel--active');
    $root.find(`.koopo-appt__panel[data-panel="${step}"]`).addClass('koopo-appt__panel--active');

    // Clear notices when changing steps
    clearNotice($root);

    // If going to step 2, render calendar
    if (step === 2) {
      renderCalendar($root);
      updateSummary($root);
    }

    // If going to step 3, pre-fill user info
    if (step === 3) {
      prefillUserInfo($root);
      updateSummary($root);
    }
  }

  // Pre-fill user information
  async function prefillUserInfo($root){
    const state = getState($root);

    // If we already have user info cached, use it
    if (state.userInfo) {
      $root.find('.koopo-appt__customer-name').val(state.userInfo.name || '');
      $root.find('.koopo-appt__customer-email').val(state.userInfo.email || '');
      $root.find('.koopo-appt__customer-phone').val(formatPhoneInput(state.userInfo.phone || ''));
      updateSummary($root);
      return;
    }

    // Try to get current user info from WordPress
    if (KOOPO_APPT && parseInt(KOOPO_APPT.userId, 10) > 0) {
      try {
        const userInfo = await api('/customer/profile', { method: 'GET' });
        state.userInfo = userInfo;
        $root.find('.koopo-appt__customer-name').val(userInfo.name || '');
        $root.find('.koopo-appt__customer-email').val(userInfo.email || '');
        $root.find('.koopo-appt__customer-phone').val(formatPhoneInput(userInfo.phone || ''));
        updateSummary($root);
      } catch(e) {
        // Silently fail - user can fill in manually
      }
    }
  }

  // Render full month calendar
  function renderCalendar($root){
    const state = getState($root);
    const month = state.currentMonth;
    const $calendar = $root.find('.koopo-appt__calendar');
    const $title = $root.find('.koopo-appt__month-title');

    $title.text(getMonthName(month));

    const firstDay = startOfMonth(month);
    const lastDay = endOfMonth(month);
    const startDayOfWeek = firstDay.getDay(); // 0 = Sunday
    const daysInMonth = lastDay.getDate();

    let html = '<div class="koopo-appt__calendar-grid">';

    // Header row (day names)
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    html += '<div class="koopo-appt__calendar-row koopo-appt__calendar-header-row">';
    dayNames.forEach(name => {
      html += `<div class="koopo-appt__calendar-dayname">${name}</div>`;
    });
    html += '</div>';

    // Calendar days
    let dayCount = 1;
    const totalCells = Math.ceil((startDayOfWeek + daysInMonth) / 7) * 7;

    for (let i = 0; i < totalCells; i++) {
      if (i % 7 === 0) html += '<div class="koopo-appt__calendar-row">';

      if (i < startDayOfWeek || dayCount > daysInMonth) {
        html += '<div class="koopo-appt__calendar-day koopo-appt__calendar-day--empty"></div>';
      } else {
        const date = new Date(month.getFullYear(), month.getMonth(), dayCount);
        const iso = fmtDate(date);
        const isToday = iso === fmtDate(new Date());
        const isPast = date < new Date(new Date().setHours(0,0,0,0));
        const isSelected = iso === state.selectedDate;

        let classes = 'koopo-appt__calendar-day';
        if (isToday) classes += ' koopo-appt__calendar-day--today';
        if (isPast) classes += ' koopo-appt__calendar-day--past';
        if (isSelected) classes += ' koopo-appt__calendar-day--selected';

        html += `<button type="button" class="${classes}" data-date="${iso}" ${isPast ? 'disabled' : ''}>
          ${dayCount}
        </button>`;
        dayCount++;
      }

      if (i % 7 === 6) html += '</div>';
    }

    html += '</div>';
    $calendar.html(html);
  }

  function bookingContext($root){
    return {
      listingId: parseInt($root.data('listing-id') || KOOPO_APPT.listingId || 0, 10) || 0,
      providerId: parseInt($root.data('provider-id') || KOOPO_APPT.providerId || 0, 10) || 0,
      resourceId: parseInt($root.data('resource-id') || KOOPO_APPT.resourceId || 0, 10) || 0
    };
  }

  function serviceModes($root){
    let modes=$root.attr('data-service-modes')||'[]';
    try{modes=JSON.parse(modes);}catch(error){modes=[];}
    return Array.isArray(modes)&&modes.length?modes:['at_location'];
  }

  function deliveryLabel(mode){
    return mode==='mobile'?'Provider comes to me':(mode==='virtual'?'Online meeting':'At a location');
  }

  function renderDeliveryOptions($root){
    const state=getState($root); const modes=serviceModes($root); const areaLabel=String($root.attr('data-service-area-label')||'');
    const options=modes.map(mode=>{
      const unavailable=mode==='mobile'&&!areaLabel;
      const detail=mode==='mobile'?(areaLabel||'Service area setup is incomplete'):mode==='virtual'?'Meeting access is shared privately after confirmation':'Visit the provider or selected business';
      return `<label class="koopo-appt__delivery-option ${unavailable?'is-disabled':''}"><input type="radio" name="koopo-delivery-${$root.data('resource-id')}" value="${mode}" ${unavailable?'disabled':''}><span><strong>${deliveryLabel(mode)}</strong><small>${escapeHtml(detail)}</small></span></label>`;
    }).join('');
    $root.find('.koopo-appt__delivery-options').html(options);
    let selected=state.fulfillmentMode;
    if(!modes.includes(selected)||(selected==='mobile'&&!areaLabel)) selected=modes.find(mode=>mode!=='mobile'||!!areaLabel)||'';
    state.fulfillmentMode=selected;
    $root.find(`.koopo-appt__delivery-option input[value="${selected}"]`).prop('checked',true);
    updateDeliveryFields($root);
  }

  function updateDeliveryFields($root){
    const state=getState($root); const mobile=state.fulfillmentMode==='mobile'; const virtual=state.fulfillmentMode==='virtual';
    $root.find('.koopo-appt__mobile-address').prop('hidden',!mobile);
    $root.find('.koopo-appt__virtual-note').prop('hidden',!virtual);
    $root.find('.koopo-appt__delivery-note').text(String($root.attr('data-service-area-label')||''));
    $root.find('.koopo-appt__summary-delivery').text(deliveryLabel(state.fulfillmentMode));
    updateSummary($root);
  }

  function serviceAddress($root){
    return {address_1:$root.find('.koopo-appt__service-address-1').val(),address_2:$root.find('.koopo-appt__service-address-2').val(),city:$root.find('.koopo-appt__service-city').val(),region:$root.find('.koopo-appt__service-region').val(),postal_code:$root.find('.koopo-appt__service-postal').val(),country:$root.find('.koopo-appt__service-country').val()};
  }

  // Load booking-calendar settings.
  async function loadListingSettings($root){
    const context = bookingContext($root);
    if ($root.data('listingSettingsLoaded')) return;

    try {
      const path = context.resourceId ? `/resources/${context.resourceId}/settings` : `/appointments/settings/${context.listingId}`;
      const s = await api(path, { method: 'GET' });
      $root.data('listingSettings', s);
      $root.data('listingSettingsLoaded', true);
      const state = getState($root);
      state.listingSettings = s;

      if (s && s.enabled === false) {
        $root.find('.koopo-appt__slots').html('<div class="koopo-appt__slots-empty">Booking is currently disabled for this listing.</div>');
      }
    } catch(e) {
      // Non-fatal
    }
  }

  // Check if day is closed
  function isClosedDay(settings, isoDate){
    if (!settings) return false;
    if (!settings.enabled) return true;
    const daysOff = Array.isArray(settings.days_off) ? settings.days_off : [];
    const hasAllDayClosure = daysOff.some(d => {
      if (typeof d === 'string') return d === isoDate;
      if (!d || typeof d !== 'object') return false;
      return d.date === isoDate && !d.start && !d.end;
    });
    if (hasAllDayClosure) return true;

    const dt = new Date(isoDate + 'T00:00:00');
    const map = ['sun','mon','tue','wed','thu','fri','sat'];
    const dayKey = map[dt.getDay()];
    const hours = (settings.hours && settings.hours[dayKey]) ? settings.hours[dayKey] : [];
    return !hours.length;
  }

  // Load available time slots
  async function loadSlots($root){
    const state = getState($root);
    const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
    const date = state.selectedDate;

    const $slots = $root.find('.koopo-appt__slots');
    $root.find('.koopo-appt__slot-start').val('');
    $root.find('.koopo-appt__slot-end').val('');
    state.selectedTimeLabel = null;

    if (!serviceId) {
      $slots.html('<div class="koopo-appt__slots-empty">Select a service to view availability.</div>');
      return;
    }
    if (!date) {
      $slots.html('<div class="koopo-appt__slots-empty">Select a day to view times.</div>');
      return;
    }

    const settings = state.listingSettings;
    if (isClosedDay(settings, date)) {
      $slots.html('<div class="koopo-appt__slots-empty">Closed on this day.</div>');
      return;
    }

    $slots.html(`
      <div class="koopo-appt__spinner" style="padding: 30px 20px; min-height: 120px;">
        <div class="koopo-appt__spinner-circle"></div>
        <div class="koopo-appt__spinner-text">Loading times...</div>
      </div>
    `);

    try {
      const duration = getTotalDuration($root);
      const qs = new URLSearchParams({ date: date, duration_minutes: String(duration || 0), fulfillment_mode: state.fulfillmentMode || 'at_location' });
      const data = await api(`/availability/by-service/${serviceId}?${qs.toString()}`, { method:'GET' });
      const slots = (data && data.slots) ? data.slots : [];

      if (!slots.length) {
        $slots.html('<div class="koopo-appt__slots-empty">No times available for this day.</div>' + waitlistPrompt($root, date));
        return;
      }

      // Group slots by time of day
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
                <button type="button" class="koopo-appt__slot" data-start="${s.start}" data-end="${s.end}" data-label="${s.label}">
                  ${s.label}
                </button>
              `).join('')}
            </div>
          </div>
        `).join('');

      $slots.html(groupHtml);
    } catch(e) {
      $slots.html('<div class="koopo-appt__slots-empty">Failed to load availability.</div>');
    }
  }

  // Load services
  async function loadServices($root){
    const context = bookingContext($root);
    const path = context.providerId ? `/services/by-provider/${context.providerId}` : `/services/by-listing/${context.listingId}`;
    const services = await api(path, { method: 'GET' });
    const state = getState($root);
    state.servicesMap = {};
    (services || []).forEach(s => { state.servicesMap[s.id] = s; });
    const bookableServices = (services || []).filter(s => !s.is_addon && (s.status || 'active') !== 'inactive');
    const addons = (services || []).filter(s => s.is_addon && (s.status || 'active') !== 'inactive');

    const $grid = $root.find('.koopo-appt__services-grid');
    $grid.empty();

    if (!bookableServices.length) {
      $grid.html('<p style="text-align: center; padding: 40px 20px; color: #666;">No services available at this time.</p>');
      return [];
    }

    // Render service cards
    bookableServices.forEach(s => {
      const price = s.price !== undefined ? `${KOOPO_APPT.currency}${Number(s.price).toFixed(2)}` : 'N/A';
      const duration = s.duration_minutes ? `${s.duration_minutes} min` : 'N/A';
      const description = s.description || '';

      const card = $(`
        <div class="koopo-appt__service-card" data-service-id="${s.id}" data-price="${s.price}" data-duration="${s.duration_minutes}" data-name="${s.title}">
          <div class="koopo-appt__service-card-header">
            <h4 class="koopo-appt__service-card-title">${escapeHtml(s.title)}</h4>
            <span class="koopo-appt__service-card-price">${price}</span>
          </div>
          ${description ? `<p class="koopo-appt__service-card-desc">${escapeHtml(description)}</p>` : ''}
          <div class="koopo-appt__service-card-footer">
            <span class="koopo-appt__service-card-duration">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 14.5C11.5899 14.5 14.5 11.5899 14.5 8C14.5 4.41015 11.5899 1.5 8 1.5C4.41015 1.5 1.5 4.41015 1.5 8C1.5 11.5899 4.41015 14.5 8 14.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8 4V8L10.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              ${duration}
            </span>
            <button type="button" class="koopo-appt__service-card-btn" aria-pressed="false">Select</button>
          </div>
        </div>
      `);

      $grid.append(card);
    });

    state.addonsAvailable = addons.length > 0;
    renderAddons($root, addons);
    renderSelectedAddons($root);
    updateAddonsVisibility($root);
    return bookableServices;
  }

  function updateAddonsVisibility($root) {
    const state = getState($root);
    const hasService = !!$root.find('.koopo-appt__service').val();
    const show = !!state.addonsAvailable && hasService;
    $root.find('.koopo-appt__addons').toggleClass('koopo-appt__addons--hidden', !show);
  }

  function renderAddons($root, addons){
    const $options = $root.find('.koopo-appt__addons-options');
    const $selected = $root.find('.koopo-appt__addons-selected');
    $options.empty();
    $selected.empty();

    if (!addons.length) {
      return;
    }

    addons.forEach(s => {
      const price = s.price !== undefined ? `${KOOPO_APPT.currency}${Number(s.price).toFixed(2)}` : 'N/A';
      $options.append(`
        <button type="button" class="koopo-appt__addon-btn" data-id="${s.id}">
          <span>${escapeHtml(s.title)}</span>
          <span>${price} <span class="koopo-appt__addon-plus">+</span></span>
        </button>
      `);
    });
  }

  function renderSelectedAddons($root){
    const state = getState($root);
    const $selected = $root.find('.koopo-appt__addons-selected');
    $selected.empty();
    (state.addonIds || []).forEach(id => {
      const svc = state.servicesMap[id];
      if (!svc) return;
      $selected.append(`
        <span class="koopo-appt__addon-chip" data-id="${id}">
          ${escapeHtml(svc.title)}
          <button type="button" aria-label="Remove">×</button>
        </span>
      `);
    });
  }

  function getSelectedAddons($root){
    const state = getState($root);
    return state.addonIds || [];
  }

  function getTotalDuration($root){
    const state = getState($root);
    const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
    const base = serviceId && state.servicesMap[serviceId] ? Number(state.servicesMap[serviceId].duration_minutes || 0) : 0;
    const addons = getSelectedAddons($root).reduce((sum, id) => {
      const svc = state.servicesMap[id];
      return sum + (svc ? Number(svc.duration_minutes || 0) : 0);
    }, 0);
    return base + addons;
  }

  function getTotalPrice($root){
    const state = getState($root);
    const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
    const base = serviceId && state.servicesMap[serviceId] ? Number(state.servicesMap[serviceId].price || 0) : 0;
    const addons = getSelectedAddons($root).reduce((sum, id) => {
      const svc = state.servicesMap[id];
      return sum + (svc ? Number(svc.price || 0) : 0);
    }, 0);
    return base + addons;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Update summary display
  function updateSummary($root){
    const state = getState($root);
    const serviceId = $root.find('.koopo-appt__service').val();

    let price, duration, serviceName;

    if (serviceId) {
      // Get service data from selected card or hidden input data
      const $card = $root.find(`.koopo-appt__service-card--selected`);
      if ($card.length) {
        price = $card.data('price');
        duration = $card.data('duration');
        serviceName = $card.data('name');
      }
    }

    // Update service name
    $root.find('.koopo-appt__summary-service').text(serviceName || '—');
    const addons = getSelectedAddons($root).map(id => (state.servicesMap[id] ? state.servicesMap[id].title : '')).filter(Boolean);
    $root.find('.koopo-appt__summary-addons').text(addons.length ? addons.join(', ') : '—');

    // Update date & time
    const dateTime = formatDateTime(state.selectedDate, state.selectedTimeLabel);
    $root.find('.koopo-appt__summary-datetime').text(dateTime);

    const totalPrice = getTotalPrice($root);
    const totalDuration = getTotalDuration($root);
    $root.find('.koopo-appt__price').text(
      (totalPrice !== undefined) ? `${KOOPO_APPT.currency}${Number(totalPrice).toFixed(2)}` : '—'
    );
    $root.find('.koopo-appt__duration').text(
      (totalDuration !== undefined) ? `${totalDuration} min` : '—'
    );

    // Enable/disable next button for step 2
    const hasService = !!serviceId;
    const deliveryReady = !!state.fulfillmentMode && (state.fulfillmentMode !== 'mobile' || state.coverageVerified);
    const hasDate = !!state.selectedDate;
    const hasSlot = !!$root.find('.koopo-appt__slot-start').val();
    $root.find('.koopo-appt__next-step--service').prop('disabled', !(hasService && deliveryReady));
    $root.find('.koopo-appt__next-step--schedule').prop('disabled', !(hasService && hasDate && hasSlot));

    // Enable/disable submit button for step 3
    const hasName = !!$root.find('.koopo-appt__customer-name').val().trim();
    const hasEmail = !!$root.find('.koopo-appt__customer-email').val().trim();
    const hasPhone = !!$root.find('.koopo-appt__customer-phone').val().trim();
    $root.find('.koopo-appt__submit').prop('disabled', !(hasService && hasDate && hasSlot && hasName && hasEmail && hasPhone));
  }

  // API helper
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

  // Modal functions
  function openModal($root){
    const $overlay = $root.find('.koopo-appt__overlay');
    $overlay.attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('koopo-appt--modal-open');
  }

  function closeModal($root){
    const $overlay = $root.find('.koopo-appt__overlay');
    $overlay.attr('aria-hidden', 'true').removeClass('is-open');
    $('body').removeClass('koopo-appt--modal-open');

    // Reset to step 1 when closing
    const state = getState($root);
    state.currentStep = 1;
    $root.find('.koopo-appt__panel').removeClass('koopo-appt__panel--active');
    $root.find('.koopo-appt__panel[data-panel="1"]').addClass('koopo-appt__panel--active');
    $root.find('.koopo-appt__step').removeClass('koopo-appt__step--active koopo-appt__step--completed');
    $root.find('.koopo-appt__step[data-step="1"]').addClass('koopo-appt__step--active');
  }

  // Create booking and checkout
  async function createBookingAndCheckout($root){
    clearNotice($root);
    setLoading($root, true);

    try {
      if (KOOPO_APPT && parseInt(KOOPO_APPT.userId, 10) === 0 && KOOPO_APPT.loginUrl) {
        window.location.href = KOOPO_APPT.loginUrl;
        return;
      }

      const context = bookingContext($root);
      const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
      const state = getState($root);
      const date = state.selectedDate;

      if (!serviceId || !date) {
        throw new Error('Please select a service and date.');
      }

      const price = (state.servicesMap[serviceId] && state.servicesMap[serviceId].price !== undefined)
        ? parseFloat(state.servicesMap[serviceId].price) || 0
        : 0;
      const start = $root.find('.koopo-appt__slot-start').val();
      const end = $root.find('.koopo-appt__slot-end').val();
      const addonIds = getSelectedAddons($root);

      if (!start || !end) throw new Error('Please select an available time.');

      // Collect customer information
      const customerName = $root.find('.koopo-appt__customer-name').val().trim();
      const customerEmail = $root.find('.koopo-appt__customer-email').val().trim();
      const customerPhone = $root.find('.koopo-appt__customer-phone').val().trim();
      const customerNotes = $root.find('.koopo-appt__customer-notes').val().trim();

      if (!customerName || !customerEmail || !customerPhone) {
        throw new Error('Please fill in all required fields (Name, Email, Phone).');
      }

      // Create booking
      const listingSettings = state.listingSettings || $root.data('listingSettings') || {};
      const booking = await api('/bookings', {
        method: 'POST',
        body: JSON.stringify({
          listing_id: context.listingId || 0,
          provider_id: context.providerId || 0,
          resource_id: context.resourceId || 0,
          service_id: serviceId,
          start_datetime: start,
          end_datetime: end,
          timezone: listingSettings.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || '',
          price: price,
          addon_ids: addonIds,
          customer_phone: customerPhone,
          customer_notes: customerNotes,
          fulfillment_mode: state.fulfillmentMode || 'at_location',
          service_address: state.fulfillmentMode === 'mobile' ? serviceAddress($root) : {}
        })
      });

      if (booking.order_received_url) {
        window.location.href = booking.order_received_url;
        return;
      }

      // Add to cart and checkout
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

  // Event handlers
  $(document).on('click', '.koopo-appt__open', async function(){
    const $root = $(this).closest('.koopo-appt');
    openModal($root);
    clearNotice($root);
    goToStep($root, 1);
    const state = getState($root);
    state.addonIds = [];

    // Show spinner while loading
    const $panel1 = $root.find('.koopo-appt__panel[data-panel="1"]');

    // Hide the form content and show spinner
    $panel1.children().hide();
    $panel1.append(`
      <div class="koopo-appt__spinner">
        <div class="koopo-appt__spinner-circle"></div>
        <div class="koopo-appt__spinner-text">Loading services...</div>
      </div>
    `);

    try {
      await loadListingSettings($root);
      await loadServices($root);
      renderDeliveryOptions($root);
      renderSelectedAddons($root);

      // Remove spinner and show form content
      $panel1.find('.koopo-appt__spinner').remove();
      $panel1.children().show();
    } catch (e) {
      // Remove spinner and show form content on error
      $panel1.find('.koopo-appt__spinner').remove();
      $panel1.children().show();
      showNotice($root, e.message || 'Failed to load booking UI.', 'error');
    }
  });

  $(document).on('change','.koopo-appt__delivery-option input',function(){
    const $root=$(this).closest('.koopo-appt'); const state=getState($root); state.fulfillmentMode=String($(this).val()||''); state.coverageVerified=state.fulfillmentMode!=='mobile'; updateDeliveryFields($root); loadSlots($root).catch(()=>{});
  });
  $(document).on('input','.koopo-appt__mobile-address input',function(){const $root=$(this).closest('.koopo-appt');getState($root).coverageVerified=false;$root.find('.koopo-appt__coverage-status').text('');updateSummary($root);});
  $(document).on('click','.koopo-appt__coverage-check',async function(){
    const $root=$(this).closest('.koopo-appt'); const state=getState($root); const context=bookingContext($root); const $status=$root.find('.koopo-appt__coverage-status').text('Checking coverage…'); $(this).prop('disabled',true);
    try{const result=await api(`/providers/${context.providerId}/service-area/check`,{method:'POST',body:JSON.stringify(serviceAddress($root))});state.coverageVerified=!!result.eligible;$status.text(result.eligible?'This address is in the service area.':'This address is outside the provider’s service area.');updateSummary($root);}catch(error){state.coverageVerified=false;$status.text(error.message||'Unable to check this address.');updateSummary($root);}finally{$(this).prop('disabled',false);}
  });

  // Service card selection
  $(document).on('click', '.koopo-appt__service-card', function(e){
    e.preventDefault();
    const $card = $(this);
    const $root = $card.closest('.koopo-appt');

    // Deselect all cards
    $root.find('.koopo-appt__service-card')
      .removeClass('koopo-appt__service-card--selected')
      .find('.koopo-appt__service-card-btn')
      .attr('aria-pressed', 'false');

    // Select this card
    $card.addClass('koopo-appt__service-card--selected');
    $card.find('.koopo-appt__service-card-btn').attr('aria-pressed', 'true');

    // Set the service ID in hidden input
    const serviceId = $card.data('service-id');
    $root.find('.koopo-appt__service').val(serviceId);

    // Update summary
    updateSummary($root);
    updateAddonsVisibility($root);
  });

  // Add-on selection
  $(document).on('click', '.koopo-appt__addon-btn', function(e){
    e.preventDefault();
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    const id = parseInt($(this).data('id'), 10);
    if (!id) return;
    if (!state.addonIds.includes(id)) {
      state.addonIds.push(id);
      renderSelectedAddons($root);
      updateSummary($root);
      loadSlots($root);
    }
  });

  $(document).on('click', '.koopo-appt__addon-chip button', function(e){
    e.preventDefault();
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    const id = parseInt($(this).closest('.koopo-appt__addon-chip').data('id'), 10);
    state.addonIds = state.addonIds.filter(x => x !== id);
    renderSelectedAddons($root);
    updateSummary($root);
    loadSlots($root);
  });

  $(document).on('click', '.koopo-appt__close', function(){
    closeModal($(this).closest('.koopo-appt'));
  });

  $(document).on('click', '.koopo-appt__overlay', function(e){
    if ($(e.target).hasClass('koopo-appt__overlay')) {
      closeModal($(this).closest('.koopo-appt'));
    }
  });

  // Calendar navigation
  $(document).on('click', '.koopo-appt__month-prev', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    state.currentMonth = addMonths(state.currentMonth, -1);
    renderCalendar($root);
  });

  $(document).on('click', '.koopo-appt__month-next', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    state.currentMonth = addMonths(state.currentMonth, 1);
    renderCalendar($root);
  });

  // Date selection
  $(document).on('click', '.koopo-appt__calendar-day:not(.koopo-appt__calendar-day--past)', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    const iso = $(this).data('date');

    state.selectedDate = iso;
    $root.find('.koopo-appt__date').val(iso);

    // Update calendar selection
    $root.find('.koopo-appt__calendar-day').removeClass('koopo-appt__calendar-day--selected');
    $(this).addClass('koopo-appt__calendar-day--selected');

    loadSlots($root).catch(()=>{});
    updateSummary($root);
  });

  // Slot selection
  $(document).on('click', '.koopo-appt__slot', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);

    $root.find('.koopo-appt__slot').removeClass('is-selected');
    $(this).addClass('is-selected');

    $root.find('.koopo-appt__slot-start').val($(this).data('start'));
    $root.find('.koopo-appt__slot-end').val($(this).data('end'));
    state.selectedTimeLabel = $(this).data('label');

    updateSummary($root);
  });

  // Step navigation
  $(document).on('click', '.koopo-appt__next-step', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    // From step 2 to step 3
    goToStep($root, state.currentStep + 1);
  });

  $(document).on('click', '.koopo-appt__prev-step', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    // Go back one step
    goToStep($root, state.currentStep - 1);
  });

  // Form field changes
  $(document).on('input', '.koopo-appt__customer-name, .koopo-appt__customer-email, .koopo-appt__customer-phone', function(){
    if ($(this).hasClass('koopo-appt__customer-phone')) {
      $(this).val(formatPhoneInput($(this).val()));
    }
    updateSummary($(this).closest('.koopo-appt'));
  });

  // Submit button
  $(document).on('click', '.koopo-appt__submit', function(){
    const $root = $(this).closest('.koopo-appt');
    createBookingAndCheckout($root);
  });

  $(document).on('click', '.koopo-appt__waitlist-join', async function(){
    const $button = $(this);
    const $root = $button.closest('.koopo-appt');
    const $box = $button.closest('.koopo-appt__waitlist');
    const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10) || 0;
    const channels = $box.find('.koopo-appt__waitlist-channels input:checked').map(function(){ return this.value; }).get();
    const preferredDays = $box.find('.koopo-appt__waitlist-days input:checked').map(function(){ return this.value; }).get();
    $button.prop('disabled', true);
    $box.find('.koopo-appt__waitlist-status').text('Saving…');
    try {
      await api('/waitlist', {
        method: 'POST',
        body: JSON.stringify({
          service_id: serviceId,
          date_from: $box.find('.koopo-appt__wait-from').val(),
          date_to: $box.find('.koopo-appt__wait-to').val(),
          preferred_days: preferredDays,
          earliest_time: $box.find('.koopo-appt__wait-earliest').val(),
          latest_time: $box.find('.koopo-appt__wait-latest').val(),
          channels: channels,
          fulfillment_mode: getState($root).fulfillmentMode || 'at_location',
          service_address: getState($root).fulfillmentMode === 'mobile' ? serviceAddress($root) : {}
        })
      });
      $box.addClass('is-complete').html('<strong>You’re on the waitlist.</strong><p>We’ll contact you only when a matching opening becomes available.</p>');
    } catch (error) {
      $button.prop('disabled', false);
      $box.find('.koopo-appt__waitlist-status').text(error.message || 'Could not join the waitlist.');
    }
  });

  async function acceptWaitlistOffer($banner, token){
    const $button = $banner.find('button');
    $button.prop('disabled', true).text('Confirming…');
    try {
      const booking = await api(`/waitlist/offers/${encodeURIComponent(token)}/accept`, { method: 'POST' });
      if (booking.order_received_url) {
        window.location.href = booking.order_received_url;
        return;
      }
      const checkout = await api(`/bookings/${booking.booking_id}/checkout-cart`, { method: 'POST' });
      window.location.href = checkout.checkout_url;
    } catch (error) {
      $button.prop('disabled', false).text('Confirm opening');
      $banner.find('[role="status"]').text(error.message || 'This opening is no longer available.');
    }
  }

  $(function(){
    if (!(window.KOOPO_APPT && KOOPO_APPT.features && KOOPO_APPT.features.waitlist)) return;
    const token = new URLSearchParams(window.location.search).get('koopo_waitlist_offer');
    if (!token) return;
    $('.koopo-appt').each(function(){
      const $root = $(this);
      if ($root.find('.koopo-appt__offer-banner').length) return;
      const $banner = $('<aside class="koopo-appt__offer-banner"><div><span>Waitlist opening</span><strong>An appointment time is being held briefly for you.</strong><small role="status">Confirm now to reserve it. If payment is required, you’ll continue to checkout.</small></div><button type="button">Confirm opening</button></aside>');
      $banner.on('click', 'button', function(){ acceptWaitlistOffer($banner, token); });
      $root.prepend($banner);
    });
  });

})(jQuery);
