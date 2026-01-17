(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const utils = window.KOOPO_VENDOR_UTILS || {};
  const api = utils.api;
  const loadVendorListings = utils.loadVendorListings;
  const escapeHtml = utils.escapeHtml;
  if (!api || !loadVendorListings || !escapeHtml) return;

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
})(jQuery);
