(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const api = (window.KOOPO_VENDOR_UTILS || {}).api;
  if (!api) return;
  const $picker = $('#koopo-provider-picker');
  const escapeHtml = (window.KOOPO_VENDOR_UTILS || {}).escapeHtml || (value => String(value || ''));
  const $modal = $('[data-koopo-provider-modal]');
  const $createPanel = $('#koopo-provider-create-panel');
  const entitlement = Object.assign({used:0,limit:1,limit_label:'1',can_create:true}, KOOPO_APPT_VENDOR.profileEntitlement || {});
  let providers = [];
  let modalReturnFocus = null;
  let pendingGallery = [];
  let draggedGalleryId = 0;
  let galleryBusy = false;

  function updateEntitlement(){
    entitlement.used = providers.length;
    entitlement.can_create = entitlement.limit == null || providers.length < Number(entitlement.limit);
    const label = entitlement.limit == null ? 'Unlimited' : String(entitlement.limit);
    $('[data-koopo-profile-usage]').text(`${providers.length} of ${label}`);
    $('[data-koopo-profile-entitlement]').toggleClass('is-at-limit', !entitlement.can_create);
    $('[data-koopo-create-toggle]').prop('disabled', !entitlement.can_create);
    if (!entitlement.can_create && !$('[data-koopo-profile-entitlement] a').length) {
      $('[data-koopo-profile-entitlement]').append(`<a href="${escapeHtml(KOOPO_APPT_VENDOR.upgradeUrl || '#')}">Upgrade plan →</a>`);
    }
  }

  function renderCards(){
    const $grid = $('[data-koopo-provider-grid]');
    $('[data-koopo-provider-count]').text(providers.length === 1 ? '1 profile' : `${providers.length} profiles`);
    if (!providers.length) {
      $grid.html('<div class="koopo-provider-empty"><strong>No service profiles yet</strong><p>Create a profile to publish services, availability, and booking details.</p></div>');
      updateEntitlement();
      return;
    }
    $grid.html(providers.map(provider => {
      const modes = (provider.service_modes || []).map(mode => mode === 'at_location' ? 'In person' : (mode === 'mobile' ? 'Mobile' : 'Virtual')).join(' · ');
      const category = provider.category && provider.category.name ? provider.category.name : 'Uncategorized';
      const image = provider.image_url ? `<img src="${escapeHtml(provider.image_url)}" alt="" />` : `<span>${escapeHtml((provider.name || '?').slice(0,1))}</span>`;
      return `<button type="button" class="koopo-provider-profile-card" data-provider-open="${Number(provider.id)}" aria-label="Edit ${escapeHtml(provider.name)}">
        <span class="koopo-provider-profile-card__image">${image}</span>
        <span class="koopo-provider-profile-card__content"><strong>${escapeHtml(provider.name)}</strong><small>${escapeHtml(provider.headline || category)}</small><span>${escapeHtml(category)}${modes ? ` · ${escapeHtml(modes)}` : ''}</span></span>
        <span class="koopo-provider-profile-card__action">Edit <b aria-hidden="true">→</b></span>
      </button>`;
    }).join(''));
    updateEntitlement();
  }

  function openEditor(provider, trigger){
    if (!provider) return;
    modalReturnFocus = trigger || document.activeElement;
    $picker.val(String(provider.id));
    render(provider);
    $('#koopo-provider-editor-title').text(`Edit ${provider.name}`);
    $modal.prop('hidden', false).attr('aria-hidden', 'false');
    $('body').addClass('koopo-provider-modal-open');
    window.requestAnimationFrame(() => $modal.find('.koopo-provider-modal__dialog').trigger('focus'));
  }

  function closeEditor(){
    $modal.prop('hidden', true).attr('aria-hidden', 'true');
    $('body').removeClass('koopo-provider-modal-open');
    if (modalReturnFocus && typeof modalReturnFocus.focus === 'function') modalReturnFocus.focus();
  }

  function serviceAreaPayload(){
    return {
      enabled: $('#koopo-provider-area-enabled').is(':checked'),
      address_1: $('#koopo-provider-area-address').val(),
      city: $('#koopo-provider-area-city').val(),
      region: $('#koopo-provider-area-region').val(),
      postal_code: $('#koopo-provider-area-postal').val(),
      country: $('#koopo-provider-area-country').val(),
      radius: Number($('#koopo-provider-area-radius').val() || 0),
      radius_unit: 'miles',
      travel_buffer_minutes: Number($('#koopo-provider-area-buffer').val() || 0),
      public_label: $('#koopo-provider-area-label').val()
    };
  }

  function updateDeliveryVisibility(){
    const mobile = $('.koopo-provider-edit-mode[value="mobile"]').is(':checked');
    const virtual = $('.koopo-provider-edit-mode[value="virtual"]').is(':checked');
    $('[data-koopo-service-area]').toggleClass('is-disabled', !mobile);
    $('[data-koopo-service-area] input, [data-koopo-service-area] select, [data-koopo-service-area] button').prop('disabled', !mobile);
    $('[data-koopo-virtual]').toggleClass('is-disabled', !virtual);
    $('[data-koopo-virtual] input, [data-koopo-virtual] select, [data-koopo-virtual] textarea').prop('disabled', !virtual);
  }

  function renderGallery(provider){
    const gallery = provider && Array.isArray(provider.gallery) ? provider.gallery : [];
    const saved = gallery.map((image,index)=>`<figure draggable="true" tabindex="0" data-gallery-id="${Number(image.id)}" aria-label="Portfolio photo ${index+1} of ${gallery.length}. Drag to reorder."><img src="${escapeHtml(image.url||'')}" alt="" /><figcaption><span class="koopo-gallery-drag-handle" aria-hidden="true">⠿</span><button type="button" data-gallery-move="up" ${index===0?'disabled':''} aria-label="Move photo earlier">←</button><button type="button" data-gallery-move="down" ${index===gallery.length-1?'disabled':''} aria-label="Move photo later">→</button><button type="button" data-gallery-remove aria-label="Remove photo">Remove</button></figcaption></figure>`).join('');
    const pending = pendingGallery.map(item=>`<figure class="is-uploading" data-gallery-pending="${escapeHtml(item.key)}"><img src="${escapeHtml(item.url)}" alt="" /><span class="koopo-gallery-uploading">Uploading</span></figure>`).join('');
    $('[data-koopo-provider-gallery]').html(saved + pending || '<p class="koopo-provider-gallery-empty">Drop photos above to start your portfolio.</p>');
  }

  async function persistGalleryOrder(provider, next){
    if (!provider || galleryBusy) return;
    const previous = provider.gallery.slice();
    galleryBusy = true;
    provider.gallery = next;
    renderGallery(provider);
    const $status = $('.koopo-provider-gallery-status').text('Saving portfolio order…');
    try {
      const result = await api(`/providers/${provider.id}/gallery`, {method:'POST', body:JSON.stringify({attachment_ids:next.map(image=>image.id)})});
      provider.gallery = result.gallery || next;
      renderGallery(provider);
      $status.text('Portfolio order saved.');
    } catch(error) {
      provider.gallery = previous;
      renderGallery(provider);
      $status.text(error.message || 'Unable to save portfolio order.');
    } finally { galleryBusy = false; }
  }

  async function uploadPortfolioFiles(files){
    const id = parseInt($picker.val(),10) || 0;
    const current = providers.find(item=>item.id===id);
    if (!id || !current || !files.length || galleryBusy) return;
    const available = Math.max(0, 12 - ((current.gallery||[]).length + pendingGallery.length));
    const $status = $('.koopo-provider-gallery-status');
    if (!available) { $status.text('This portfolio already has 12 photos.'); return; }
    const selected = files.slice(0, available);
    const batch = selected.map((file,index)=>({file,key:`${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`,url:URL.createObjectURL(file)}));
    pendingGallery = pendingGallery.concat(batch);
    renderGallery(current);
    galleryBusy = true;
    try {
      for (let index=0; index<batch.length; index++) {
        const item = batch[index];
        const media = await window.KOOPO_VENDOR_UTILS.uploadServiceProfileGalleryImage(id,item.file,percent=>$status.text(`Uploading photo ${index+1} of ${batch.length} — ${percent}%…`));
        current.gallery = Array.isArray(media.gallery) ? media.gallery : current.gallery;
        pendingGallery = pendingGallery.filter(pending=>pending.key!==item.key);
        URL.revokeObjectURL(item.url);
        renderGallery(current);
      }
      $status.text(selected.length<files.length?`Added ${selected.length} photos. The portfolio limit is 12.`:`Added ${selected.length} ${selected.length===1?'photo':'photos'} to your portfolio.`);
    } catch(error) {
      $status.text(error.message || 'Portfolio upload failed.');
    } finally {
      batch.forEach(item=>URL.revokeObjectURL(item.url));
      pendingGallery = pendingGallery.filter(item=>!batch.some(batchItem=>batchItem.key===item.key));
      galleryBusy = false;
      renderGallery(current);
      $('#koopo-provider-gallery-files').val('');
    }
  }

  function render(provider){
    $('#koopo-provider-edit-name').val(provider ? provider.name : '');
    $('#koopo-provider-edit-category').val(provider && provider.category ? provider.category.id : '');
    $('#koopo-provider-edit-headline').val(provider ? provider.headline : '');
    $('#koopo-provider-edit-bio').val(provider ? provider.bio : '');
    const location = provider && provider.location || {};
    $('#koopo-provider-edit-location-name').val(location.name || '');
    $('#koopo-provider-edit-address').val(provider && provider.location_fields ? provider.location_fields.address : '');
    $('#koopo-provider-edit-city').val(provider && provider.location_fields ? provider.location_fields.city : '');
    $('#koopo-provider-edit-region').val(provider && provider.location_fields ? provider.location_fields.region : '');
    $('#koopo-provider-edit-postal').val(provider && provider.location_fields ? provider.location_fields.postal_code : '');
    $('#koopo-provider-edit-country').val(provider && provider.location_fields ? provider.location_fields.country : '');
    $('#koopo-provider-edit-latitude').val(location.latitude || '');
    $('#koopo-provider-edit-longitude').val(location.longitude || '');
    $('#koopo-provider-edit-location-public').prop('checked', !!(provider && provider.location_public));
    $('.koopo-provider-edit-mode').prop('checked', false);
    (provider && provider.service_modes || []).forEach(mode => $(`.koopo-provider-edit-mode[value="${mode}"]`).prop('checked', true));
    const area = provider && provider.service_area || {};
    const origin = area.origin || {};
    $('#koopo-provider-area-enabled').prop('checked', !!area.configured);
    $('#koopo-provider-area-address').val(origin.address_1 || '');
    $('#koopo-provider-area-city').val(origin.city || '');
    $('#koopo-provider-area-region').val(origin.region || '');
    $('#koopo-provider-area-postal').val(origin.postal_code || '');
    $('#koopo-provider-area-country').val(origin.country || 'United States');
    $('#koopo-provider-area-radius').val(area.radius_miles || 20);
    $('#koopo-provider-area-buffer').val(String(area.travel_buffer_minutes == null ? 30 : area.travel_buffer_minutes));
    $('#koopo-provider-area-label').val(area.public_label || '');
    const virtual = provider && provider.virtual_delivery || {};
    $('#koopo-provider-virtual-method').val(virtual.method || 'provider_sends');
    $('#koopo-provider-virtual-url').val(virtual.join_url || '');
    $('#koopo-provider-virtual-instructions').val(virtual.instructions || '');
    updateDeliveryVisibility();
    $('#koopo-provider-view').attr('href', provider ? provider.permalink : '#').toggleClass('is-disabled', !provider);
    const $preview = $('[data-koopo-provider-edit-preview]');
    $preview.html(provider && provider.image_url ? `<img src="${$('<div>').text(provider.image_url).html()}" alt="" />` : '<span aria-hidden="true">+</span>');
    renderGallery(provider);
  }

  async function load(){
    const contexts = await api('/vendor/booking-contexts', { method:'GET' });
    const ids = contexts.filter(item => item.subject_type === 'provider').map(item => item.provider_id);
    providers = await Promise.all(ids.map(id => api(`/providers/${id}`, { method:'GET' })));
    $picker.html('<option value="">Select service profile…</option>');
    providers.forEach(item => $picker.append(`<option value="${item.id}">${$('<div>').text(item.name).html()}</option>`));
    renderCards();
  }

  $picker.on('change', function(){ render(providers.find(item => item.id === parseInt($(this).val(), 10)) || null); });
  $(document).on('click', '[data-provider-open]', function(){ openEditor(providers.find(item => item.id === Number($(this).data('provider-open'))), this); });
  $(document).on('click', '[data-koopo-modal-close]', closeEditor);
  $(document).on('keydown', function(event){
    if ($modal.prop('hidden')) return;
    if (event.key === 'Escape') { event.preventDefault(); closeEditor(); return; }
    if (event.key !== 'Tab') return;
    const focusable = $modal.find('a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])').filter(':visible').get();
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  $('[data-koopo-create-toggle]').on('click', function(){
    if (!entitlement.can_create) return;
    const opening = $createPanel.prop('hidden');
    $createPanel.prop('hidden', !opening);
    $(this).attr('aria-expanded', opening ? 'true' : 'false');
    if (opening) window.requestAnimationFrame(() => $('#koopo-provider-name').trigger('focus'));
  });
  $('[data-koopo-create-close]').on('click', function(){ $createPanel.prop('hidden', true); $('[data-koopo-create-toggle]').attr('aria-expanded', 'false').trigger('focus'); });
  $(document).on('koopo:provider-created', function(event, provider){
    if (!provider || !provider.id) return;
    providers.push(provider);
    $picker.append(`<option value="${provider.id}">${escapeHtml(provider.name)}</option>`);
    renderCards();
    $createPanel.prop('hidden', true);
    $('[data-koopo-create-toggle]').attr('aria-expanded', 'false');
    $('.koopo-provider-create-status').text('Profile created.');
  });
  $('#koopo-provider-edit-image').on('change', async function(){
    const id = parseInt($picker.val(), 10) || 0;
    const file = this.files && this.files[0];
    if (!id || !file) return;
    const $status = $('.koopo-provider-image-status').text('Preparing image…');
    window.KOOPO_VENDOR_UTILS.previewSelectedImage(this, '[data-koopo-provider-edit-preview]');
    try {
      const media = await window.KOOPO_VENDOR_UTILS.uploadServiceProfileImage(id, file, percent => $status.text(`Uploading directly ${percent}%…`));
      const provider = await api(`/providers/${id}`, {method:'GET'});
      providers = providers.map(item => item.id === id ? provider : item);
      render(provider); renderCards(); $status.text('Profile image updated.'); this.value = '';
    } catch(error) { $status.text(error.message || 'Image upload failed.'); }
  });
  $('#koopo-provider-gallery-files').on('change', function(){ uploadPortfolioFiles([...(this.files||[])]); });
  $('[data-koopo-provider-gallery-drop]').on('keydown', function(event){ if(event.key==='Enter'||event.key===' '){event.preventDefault();$('#koopo-provider-gallery-files').trigger('click');} });
  $('[data-koopo-provider-gallery-drop]').on('dragenter dragover', function(event){event.preventDefault();event.originalEvent.dataTransfer.dropEffect='copy';$(this).addClass('is-drag-over');});
  $('[data-koopo-provider-gallery-drop]').on('dragleave', function(event){if(!event.relatedTarget||!this.contains(event.relatedTarget))$(this).removeClass('is-drag-over');});
  $('[data-koopo-provider-gallery-drop]').on('drop', function(event){event.preventDefault();$(this).removeClass('is-drag-over');uploadPortfolioFiles([...(event.originalEvent.dataTransfer.files||[])]);});
  $(document).on('click','[data-gallery-remove]',async function(){
    if(galleryBusy)return;
    const id=parseInt($picker.val(),10)||0; const attachmentId=parseInt($(this).closest('[data-gallery-id]').data('gallery-id'),10)||0; if(!id||!attachmentId)return;
    const $status=$('.koopo-provider-gallery-status').text('Removing photo…');
    try{const result=await api(`/providers/${id}/gallery/${attachmentId}`,{method:'DELETE'}); const provider=providers.find(item=>item.id===id); provider.gallery=result.gallery||[];renderGallery(provider);$status.text('Photo removed.');}catch(error){$status.text(error.message||'Unable to remove photo.');}
  });
  $(document).on('click','[data-gallery-move]',async function(){
    const id=parseInt($picker.val(),10)||0; const provider=providers.find(item=>item.id===id); if(!id||!provider)return;
    const attachmentId=parseInt($(this).closest('[data-gallery-id]').data('gallery-id'),10)||0; const from=provider.gallery.findIndex(image=>Number(image.id)===attachmentId); const to=$(this).data('gallery-move')==='up'?from-1:from+1; if(from<0||to<0||to>=provider.gallery.length)return;
    const next=provider.gallery.slice(); [next[from],next[to]]=[next[to],next[from]]; await persistGalleryOrder(provider,next);
  });
  $(document).on('dragstart','[data-koopo-provider-gallery] [data-gallery-id]',function(event){
    if(galleryBusy){event.preventDefault();return;} draggedGalleryId=Number($(this).data('gallery-id'))||0; $(this).addClass('is-dragging'); event.originalEvent.dataTransfer.effectAllowed='move'; event.originalEvent.dataTransfer.setData('text/plain',String(draggedGalleryId));
  });
  $(document).on('dragend','[data-koopo-provider-gallery] [data-gallery-id]',function(){$(this).removeClass('is-dragging');$('[data-gallery-id]').removeClass('is-drag-target');draggedGalleryId=0;});
  $(document).on('dragover','[data-koopo-provider-gallery] [data-gallery-id]',function(event){if(!draggedGalleryId)return;event.preventDefault();$(this).addClass('is-drag-target').siblings().removeClass('is-drag-target');});
  $(document).on('drop','[data-koopo-provider-gallery] [data-gallery-id]',async function(event){
    event.preventDefault(); const id=parseInt($picker.val(),10)||0; const provider=providers.find(item=>item.id===id); const targetId=Number($(this).data('gallery-id'))||0; $('[data-gallery-id]').removeClass('is-drag-target'); if(!provider||!draggedGalleryId||targetId===draggedGalleryId)return;
    const from=provider.gallery.findIndex(image=>Number(image.id)===draggedGalleryId); const to=provider.gallery.findIndex(image=>Number(image.id)===targetId); if(from<0||to<0)return; const next=provider.gallery.slice(); const [moved]=next.splice(from,1); next.splice(to,0,moved); await persistGalleryOrder(provider,next);
  });
  $('#koopo-provider-edit-save').on('click', async function(){
    const id = parseInt($picker.val(), 10) || 0;
    if (!id) return;
    const $status = $('.koopo-provider-edit-status').text('Saving…');
    const virtualMethod = $('#koopo-provider-virtual-method').val();
    if ($('.koopo-provider-edit-mode[value="virtual"]').is(':checked') && virtualMethod !== 'provider_sends' && !$('#koopo-provider-virtual-url').val().trim()) {
      $status.text('Add the private meeting link customers should receive after confirmation.');
      return;
    }
    const categoryId = parseInt($('#koopo-provider-edit-category').val(), 10) || 0;
    if (!categoryId) { $status.text('Choose a primary service category.'); return; }
    try {
      const updated = await api(`/providers/${id}`, { method:'POST', body: JSON.stringify({
        name: $('#koopo-provider-edit-name').val(),
        category_id: categoryId,
        headline: $('#koopo-provider-edit-headline').val(),
        bio: $('#koopo-provider-edit-bio').val(),
        location_name: $('#koopo-provider-edit-location-name').val(),
        address: $('#koopo-provider-edit-address').val(),
        city: $('#koopo-provider-edit-city').val(),
        region: $('#koopo-provider-edit-region').val(),
        postal_code: $('#koopo-provider-edit-postal').val(),
        country: $('#koopo-provider-edit-country').val(),
        latitude: $('#koopo-provider-edit-latitude').val(),
        longitude: $('#koopo-provider-edit-longitude').val(),
        location_public: $('#koopo-provider-edit-location-public').is(':checked'),
        service_modes: $('.koopo-provider-edit-mode:checked').map(function(){ return $(this).val(); }).get(),
        virtual_delivery: {
          method: $('#koopo-provider-virtual-method').val(),
          join_url: $('#koopo-provider-virtual-url').val(),
          instructions: $('#koopo-provider-virtual-instructions').val()
        }
      }) });
      if ($('.koopo-provider-edit-mode[value="mobile"]').is(':checked')) {
        await api(`/providers/${id}/service-area`, {method:'PUT', body:JSON.stringify(serviceAreaPayload())});
      } else {
        await api(`/providers/${id}/service-area`, {method:'PUT', body:JSON.stringify({enabled:false})});
      }
      const refreshed = await api(`/providers/${id}`, {method:'GET'});
      providers = providers.map(item => item.id === id ? refreshed : item);
      render(refreshed); renderCards();
      $('#koopo-provider-editor-title').text(`Edit ${refreshed.name}`);
      $status.text('Profile saved.');
    } catch (error) { $status.text(error.message || 'Save failed.'); }
  });
  $(document).on('change', '.koopo-provider-edit-mode', updateDeliveryVisibility);
  $('#koopo-provider-area-test').on('click', async function(){
    const id=parseInt($picker.val(),10)||0; if(!id)return;
    const $status=$('.koopo-provider-area-status').text('Validating address and coverage…');
    try{
      const area=await api(`/providers/${id}/service-area`,{method:'PUT',body:JSON.stringify(serviceAreaPayload())});
      $status.text(`Coverage validated: ${area.public_label} · ${area.radius_miles} miles.`);
    }catch(error){$status.text(error.message||'Unable to validate this service area.');}
  });
  $('[data-koopo-use-location]').on('click', function(){
    const mode = $(this).data('koopo-use-location'); const $status = $(`[data-koopo-location-status="${mode}"]`).text('Locating…');
    if (!navigator.geolocation) { $status.text('Location is unavailable in this browser.'); return; }
    navigator.geolocation.getCurrentPosition(position => {
      const prefix = mode === 'edit' ? '#koopo-provider-edit-' : '#koopo-provider-';
      $(`${prefix}latitude`).val(position.coords.latitude.toFixed(7)); $(`${prefix}longitude`).val(position.coords.longitude.toFixed(7)); $status.text('Map position added.');
    }, () => $status.text('We could not access your location. You can enter coordinates manually.'), {enableHighAccuracy:true,timeout:10000});
  });
  load().catch(error => $('.koopo-provider-edit-status').text(error.message || 'Unable to load profiles.'));
})(jQuery);
