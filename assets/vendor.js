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
      const price = (s.price_label && s.price_label.trim()) ? escapeHtml(s.price_label) : `$${Number(s.price||0).toFixed(2)}`;
      const colorDot = s.color ? `<span class="koopo-dot" style="background:${escapeHtml(s.color)}"></span>` : '';
      return `
        <div class="koopo-card koopo-card--click" data-service-id="${s.id}">
          <div class="koopo-card__top">
            <div class="koopo-card__title">${colorDot}${escapeHtml(s.title)}</div>
            ${badge}
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

  function openModal(service){
    state.editingServiceId = service ? service.id : null;
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
    $('#koopo-service-delete').toggle(!!service);
    $modal.show();
  }
  function closeModal(){ $modal.hide(); }

  async function saveService(){
    if (!state.listingId) throw new Error('Select a listing first.');
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
      listing_id: state.listingId,
    };

    if (!payload.title || !payload.duration_minutes) throw new Error('Service name and duration are required.');

    if (state.editingServiceId) {
      await api(`/services/${state.editingServiceId}`, { method:'POST', body: JSON.stringify(payload) });
    } else {
      await api('/services', { method:'POST', body: JSON.stringify(payload) });
    }
    await refreshServices();
    closeModal();
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
    $('#koopo-add-service').on('click', function(){
      if (!state.listingId) { alert('Select a listing first.'); return; }
      openModal(null);
    });
    $servicesGrid.on('click', '[data-service-id]', function(){
      const id = parseInt($(this).data('service-id'),10);
      const svc = state.services.find(s => s.id === id);
      if (svc) openModal(svc);
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
  const $apptTable  = $('#koopo-appointments-table');
  const $apptPager  = $('#koopo-appointments-pagination');

  const apptState = { listingId: null, status: 'all', page: 1, perPage: 20, totalPages: 1 };

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

  async function loadAppointments(){
    if (!$apptTable.length) return;
    if (!apptState.listingId) {
      $apptTable.html('<div class="koopo-muted">Pick a listing to load appointments.</div>');
      $apptPager.html('');
      return;
    }
    $apptTable.html('<div class="koopo-muted">Loading…</div>');
    try {
      const qs = new URLSearchParams({
        listing_id: apptState.listingId,
        status: apptState.status || 'all',
        page: String(apptState.page),
        per_page: String(apptState.perPage),
      });
      const data = await api(`/vendor/bookings?${qs.toString()}`, { method: 'GET' });
      const items = data.items || [];
      apptState.totalPages = (data.pagination && data.pagination.total_pages) ? Number(data.pagination.total_pages) : 1;

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

        return `
          <tr ${rowClass} data-start="${escapeHtml(b.start_datetime)}" data-end="${escapeHtml(b.end_datetime)}">
            <td>#${b.id}</td>
            <td>${escapeHtml(b.customer_name || '')}</td>
            <td>${escapeHtml(b.service_title || '')}</td>
            <td>
              <strong>${dateTimeDisplay}</strong><br>
              <span style="opacity:0.7;font-size:12px;">to ${endTimeDisplay} (${durationDisplay})</span>
            </td>
            <td>${badgeForStatus(b.status)}</td>
            <td>${fmtMoney(b.price, b.currency)}</td>
            <td>${orderLink}</td>
            <td>${actionsForBooking(b)}</td>
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
              <th>Status</th>
              <th>Total</th>
              <th>Order</th>
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
    loadVendorListings($apptPicker).catch(()=>{});
    $apptPicker.on('change', function(){
      apptState.listingId = $(this).val() || '';
      apptState.page = 1;
      loadAppointments();
    });
    $apptStatus.on('change', function(){
      apptState.status = $(this).val() || 'all';
      apptState.page = 1;
      loadAppointments();
    });
    
    // Commit 19+22: Enhanced vendor actions with formatted dates
    $apptTable.on('click', '.koopo-appt-action', async function(e){
      e.preventDefault();
      const $btn = $(this);
      const id = parseInt($btn.data('id'), 10) || 0;
      const action = String($btn.data('action')||'').toLowerCase();
      if (!id || !action) return;

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
      
      try {
        const payload = { action, note, start_datetime, end_datetime, timezone };
        await api(`/vendor/bookings/${id}/action`, {
          method: 'POST',
          body: JSON.stringify(payload)
        });
        
        await loadAppointments();
        
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

      // Group slots by time of day
      const groups = { Morning: [], Afternoon: [], Evening: [] };
      
      slots.forEach(slot => {
        const hour = parseInt(slot.start.split(' ')[1].split(':')[0]);
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
      alert('Please select a new date and time');
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

      $('.koopo-reschedule-modal').fadeOut(200, function() {
        $(this).remove();
      });

      // Reload bookings table
      if (typeof loadAppointments === 'function') {
        await loadAppointments();
      }

      alert('✓ Appointment rescheduled successfully!\n\nThe customer will receive a notification about the new time.');

    } catch (err) {
      const msg = err.message || 'Unknown error';
      if (msg.includes('conflict')) {
        alert('⚠️ Cannot reschedule: The selected time conflicts with another booking.\n\nPlease choose a different time.');
      } else {
        alert('⚠️ Reschedule failed: ' + msg);
      }
      $btn.prop('disabled', false).text('Reschedule');
    }
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
