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

  // ---------- Shared: vendor listings picker ----------
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
      $servicesGrid.html('<div class="koopo-card koopo-muted">No services yet. Click “Add Service”.</div>');
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

  // Wire services page if present
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

  // ---------- Booking settings page (minimal: enable + timezone) ----------
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
    return '<span class="koopo-badge koopo-badge--gray">'+escapeHtml(status)+'</span>';
  }

  
  function actionsForBooking(b){
    const id = Number(b && b.id ? b.id : 0);
    if (!id) return '—';
    const st = String(b.status||'').toLowerCase();
    let html = '';
    // Allow cancel while pending or confirmed
    if (st === 'pending_payment' || st === 'confirmed') {
      html += `<button class="koopo-btn koopo-btn--sm koopo-btn--danger koopo-appt-action" data-action="cancel" data-id="${id}">Cancel</button> `;
    }
    // Allow manual confirm for pending_payment only (edge cases)
    if (st === 'pending_payment') {
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="confirm" data-id="${id}">Confirm</button> `;
    }
    // Always allow adding a note if order exists
    if (b.wc_order_id) {
      html += `<button class="koopo-btn koopo-btn--sm koopo-appt-action" data-action="note" data-id="${id}">Add note</button>`;
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
        const orderLink = b.wc_order_id && baseAdmin
          ? `<a href="${escapeHtml(baseAdmin)}/post.php?post=${b.wc_order_id}&action=edit" target="_blank">#${b.wc_order_id}</a>`
          : (b.wc_order_id ? '#'+b.wc_order_id : '—');

        return `
          <tr>
            <td>#${b.id}</td>
            <td>${escapeHtml(b.customer_name || '')}</td>
            <td>${escapeHtml(b.service_title || '')}</td>
            <td>${escapeHtml(b.start_datetime || '')}</td>
            <td>${escapeHtml(b.end_datetime || '')}</td>
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
              <th>Start</th>
              <th>End</th>
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
    
    // Vendor booking actions (cancel / confirm / note)
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
        const ok = confirm('Cancel this booking? If it was already paid, you may need to refund the customer.');
        if (!ok) return;
      }
      if (action === 'note') {
        note = prompt('Enter an internal note for this booking/order:', '') || '';
        if (!note.trim()) return;
      }

      if (action === 'reschedule') {
        start_datetime = prompt('New start (YYYY-MM-DD HH:MM:SS):', '') || '';
        end_datetime = prompt('New end (YYYY-MM-DD HH:MM:SS):', '') || '';
        timezone = prompt('Timezone (optional, e.g. America/Detroit):', '') || '';
        if (!start_datetime.trim() || !end_datetime.trim()) return;
      }

      $btn.prop('disabled', true);
      try {
        await api(`/vendor/bookings/${id}/action`, {
          method: 'POST',
          body: JSON.stringify({ action, note, start_datetime, end_datetime, timezone })
        });
        await loadAppointments();
      } catch (err) {
        alert('Action failed. Please try again.');
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

})(jQuery);
