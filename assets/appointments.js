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
        userInfo: null
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
    if (step === 2) {
      $root.find('.koopo-appt__step[data-step="1"]').addClass('koopo-appt__step--completed');
    }

    // Update panels
    $root.find('.koopo-appt__panel').removeClass('koopo-appt__panel--active');
    $root.find(`.koopo-appt__panel[data-panel="${step}"]`).addClass('koopo-appt__panel--active');

    // Clear notices when changing steps
    clearNotice($root);

    // If going to step 2, pre-fill user info
    if (step === 2) {
      prefillUserInfo($root);
      updateSummary($root);
    }
  }

  // Pre-fill user information
  async function prefillUserInfo($root){
    const state = getState($root);

    // Only pre-fill if not "booking for someone else"
    if ($root.find('.koopo-appt__booking-for-other').is(':checked')) return;

    // If we already have user info cached, use it
    if (state.userInfo) {
      $root.find('.koopo-appt__customer-name').val(state.userInfo.name || '');
      $root.find('.koopo-appt__customer-email').val(state.userInfo.email || '');
      $root.find('.koopo-appt__customer-phone').val(state.userInfo.phone || '');
      return;
    }

    // Try to get current user info from WordPress
    if (KOOPO_APPT && parseInt(KOOPO_APPT.userId, 10) > 0) {
      try {
        const userInfo = await api('/customer/profile', { method: 'GET' });
        state.userInfo = userInfo;
        $root.find('.koopo-appt__customer-name').val(userInfo.name || '');
        $root.find('.koopo-appt__customer-email').val(userInfo.email || '');
        $root.find('.koopo-appt__customer-phone').val(userInfo.phone || '');
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

  // Load listing settings
  async function loadListingSettings($root){
    const listingId = $root.data('listing-id') || KOOPO_APPT.listingId;
    if ($root.data('listingSettingsLoaded')) return;

    try {
      const s = await api(`/appointments/settings/${listingId}`, { method: 'GET' });
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
    if ((settings.days_off || []).includes(isoDate)) return true;

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
      const data = await api(`/availability/by-service/${serviceId}?date=${encodeURIComponent(date)}`, { method:'GET' });
      const slots = (data && data.slots) ? data.slots : [];

      if (!slots.length) {
        $slots.html('<div class="koopo-appt__slots-empty">No times available (fully booked).</div>');
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
      $sel.append(`<option value="${s.id}" data-price="${s.price}" data-duration="${s.duration_minutes}" data-name="${s.title}">${s.title}</option>`);
    });
    return services;
  }

  // Update summary display
  function updateSummary($root){
    const state = getState($root);
    const $opt = $root.find('.koopo-appt__service option:selected');
    const price = $opt.data('price');
    const duration = $opt.data('duration');
    const serviceName = $opt.data('name') || $opt.text();

    // Update service name
    $root.find('.koopo-appt__summary-service').text(
      serviceName && serviceName !== 'Select a service' ? serviceName : '—'
    );

    // Update date & time
    const dateTime = formatDateTime(state.selectedDate, state.selectedTimeLabel);
    $root.find('.koopo-appt__summary-datetime').text(dateTime);

    // Update price and duration
    $root.find('.koopo-appt__price').text(
      (price !== undefined) ? `${KOOPO_APPT.currency}${Number(price).toFixed(2)}` : '—'
    );
    $root.find('.koopo-appt__duration').text(
      (duration !== undefined) ? `${duration} min` : '—'
    );

    // Enable/disable next button for step 1
    const hasService = !!$root.find('.koopo-appt__service').val();
    const hasDate = !!state.selectedDate;
    const hasSlot = !!$root.find('.koopo-appt__slot-start').val();
    $root.find('.koopo-appt__next-step').prop('disabled', !(hasService && hasDate && hasSlot));

    // Enable/disable submit button for step 2
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

      const listingId = $root.data('listing-id') || KOOPO_APPT.listingId;
      const serviceId = parseInt($root.find('.koopo-appt__service').val(), 10);
      const state = getState($root);
      const date = state.selectedDate;

      if (!serviceId || !date) {
        throw new Error('Please select a service and date.');
      }

      const price = parseFloat($root.find('.koopo-appt__service option:selected').data('price')) || 0;
      const start = $root.find('.koopo-appt__slot-start').val();
      const end = $root.find('.koopo-appt__slot-end').val();

      if (!start || !end) throw new Error('Please select an available time.');

      // Collect customer information
      const customerName = $root.find('.koopo-appt__customer-name').val().trim();
      const customerEmail = $root.find('.koopo-appt__customer-email').val().trim();
      const customerPhone = $root.find('.koopo-appt__customer-phone').val().trim();
      const customerNotes = $root.find('.koopo-appt__customer-notes').val().trim();
      const bookingForOther = $root.find('.koopo-appt__booking-for-other').is(':checked');

      if (!customerName || !customerEmail || !customerPhone) {
        throw new Error('Please fill in all required fields (Name, Email, Phone).');
      }

      // Create booking
      const booking = await api('/bookings', {
        method: 'POST',
        body: JSON.stringify({
          listing_id: listingId,
          service_id: serviceId,
          start_datetime: start,
          end_datetime: end,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
          price: price,
          customer_name: customerName,
          customer_email: customerEmail,
          customer_phone: customerPhone,
          customer_notes: customerNotes,
          booking_for_other: bookingForOther
        })
      });

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
      const services = await loadServices($root);

      // Remove spinner and show form content
      $panel1.find('.koopo-appt__spinner').remove();
      $panel1.children().show();

      // Auto-select first service if available
      if (services && services.length > 0) {
        const $serviceSelect = $root.find('.koopo-appt__service');
        $serviceSelect.val(services[0].id);
        // Manually trigger the change to load calendar
        $serviceSelect.trigger('change');
      }

      renderCalendar($root);
      updateSummary($root);
    } catch (e) {
      // Remove spinner and show form content on error
      $panel1.find('.koopo-appt__spinner').remove();
      $panel1.children().show();
      showNotice($root, e.message || 'Failed to load booking UI.', 'error');
    }
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

  // Service selection
  $(document).on('change', '.koopo-appt__service', function(){
    const $root = $(this).closest('.koopo-appt');
    const state = getState($root);
    state.selectedService = $(this).val();
    state.selectedServiceName = $(this).find('option:selected').data('name');

    updateSummary($root);
    if (state.selectedDate) {
      loadSlots($root).catch(()=>{});
    }
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
    goToStep($root, 2);
  });

  $(document).on('click', '.koopo-appt__prev-step', function(){
    const $root = $(this).closest('.koopo-appt');
    goToStep($root, 1);
  });

  // Booking for someone else toggle
  $(document).on('change', '.koopo-appt__booking-for-other', function(){
    const $root = $(this).closest('.koopo-appt');
    if ($(this).is(':checked')) {
      // Clear fields when booking for someone else
      $root.find('.koopo-appt__customer-name').val('');
      $root.find('.koopo-appt__customer-email').val('');
      $root.find('.koopo-appt__customer-phone').val('');
    } else {
      // Re-populate with user info
      prefillUserInfo($root);
    }
    updateSummary($root);
  });

  // Form field changes
  $(document).on('input', '.koopo-appt__customer-name, .koopo-appt__customer-email, .koopo-appt__customer-phone', function(){
    updateSummary($(this).closest('.koopo-appt'));
  });

  // Submit button
  $(document).on('click', '.koopo-appt__submit', function(){
    const $root = $(this).closest('.koopo-appt');
    createBookingAndCheckout($root);
  });

})(jQuery);
