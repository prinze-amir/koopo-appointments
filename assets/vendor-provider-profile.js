(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const api = (window.KOOPO_VENDOR_UTILS || {}).api;
  if (!api) return;
  const $picker = $('#koopo-provider-picker');
  let providers = [];

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
    const html = gallery.map((image,index)=>`<figure data-gallery-id="${Number(image.id)}"><img src="${$('<div>').text(image.url||'').html()}" alt="" /><figcaption><button type="button" data-gallery-move="up" ${index===0?'disabled':''} aria-label="Move photo earlier">↑</button><button type="button" data-gallery-move="down" ${index===gallery.length-1?'disabled':''} aria-label="Move photo later">↓</button><button type="button" data-gallery-remove aria-label="Remove photo">Remove</button></figcaption></figure>`).join('');
    $('[data-koopo-provider-gallery]').html(html || '<p class="koopo-provider-gallery-empty">No gallery photos yet.</p>');
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
    if (providers.length) $picker.prop('selectedIndex', 1).trigger('change');
  }

  $picker.on('change', function(){ render(providers.find(item => item.id === parseInt($(this).val(), 10)) || null); });
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
      render(provider); $status.text('Profile image updated.'); this.value = '';
    } catch(error) { $status.text(error.message || 'Image upload failed.'); }
  });
  $('#koopo-provider-gallery-files').on('change', async function(){
    const id=parseInt($picker.val(),10)||0; const files=[...(this.files||[])]; if(!id||!files.length)return;
    const current=providers.find(item=>item.id===id); const available=Math.max(0,12-((current&&current.gallery||[]).length));
    const $status=$('.koopo-provider-gallery-status'); if(!available){$status.text('This gallery already has 12 photos.');this.value='';return;}
    try {
      const selected=files.slice(0,available);
      for(let index=0;index<selected.length;index++) await window.KOOPO_VENDOR_UTILS.uploadServiceProfileGalleryImage(id,selected[index],percent=>$status.text(`Uploading photo ${index+1} of ${selected.length} — ${percent}%…`));
      const provider=await api(`/providers/${id}`,{method:'GET'}); providers=providers.map(item=>item.id===id?provider:item); render(provider);
      $status.text(selected.length<files.length?`Added ${selected.length} photos. The gallery limit is 12.`:`Added ${selected.length} ${selected.length===1?'photo':'photos'}.`); this.value='';
    } catch(error){$status.text(error.message||'Gallery upload failed.');}
  });
  $(document).on('click','[data-gallery-remove]',async function(){
    const id=parseInt($picker.val(),10)||0; const attachmentId=parseInt($(this).closest('[data-gallery-id]').data('gallery-id'),10)||0; if(!id||!attachmentId)return;
    const $status=$('.koopo-provider-gallery-status').text('Removing photo…');
    try{const result=await api(`/providers/${id}/gallery/${attachmentId}`,{method:'DELETE'}); const provider=providers.find(item=>item.id===id); provider.gallery=result.gallery||[];renderGallery(provider);$status.text('Photo removed.');}catch(error){$status.text(error.message||'Unable to remove photo.');}
  });
  $(document).on('click','[data-gallery-move]',async function(){
    const id=parseInt($picker.val(),10)||0; const provider=providers.find(item=>item.id===id); if(!id||!provider)return;
    const attachmentId=parseInt($(this).closest('[data-gallery-id]').data('gallery-id'),10)||0; const from=provider.gallery.findIndex(image=>Number(image.id)===attachmentId); const to=$(this).data('gallery-move')==='up'?from-1:from+1; if(from<0||to<0||to>=provider.gallery.length)return;
    const next=provider.gallery.slice(); [next[from],next[to]]=[next[to],next[from]]; provider.gallery=next;renderGallery(provider);
    const $status=$('.koopo-provider-gallery-status').text('Saving gallery order…');
    try{const result=await api(`/providers/${id}/gallery`,{method:'POST',body:JSON.stringify({attachment_ids:next.map(image=>image.id)})});provider.gallery=result.gallery||next;renderGallery(provider);$status.text('Gallery order saved.');}catch(error){$status.text(error.message||'Unable to save gallery order.');}
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
      render(refreshed);
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
