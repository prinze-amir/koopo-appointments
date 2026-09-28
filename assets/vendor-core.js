(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;

  const utils = window.KOOPO_VENDOR_UTILS || {};

  async function apiResponse(path, opts = {}) {
    const base = String(KOOPO_APPT_VENDOR.rest || '').replace(/\/$/, '');
    const url = `${base}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_APPT_VENDOR.nonce
    }, opts.headers || {});
    const res = await fetch(url, Object.assign({}, opts, { headers, credentials: 'same-origin' }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || data.error || 'Request failed');
    return { data, response: res };
  }

  async function api(path, opts = {}) {
    return (await apiResponse(path, opts)).data;
  }

  async function apiWithMeta(path, opts = {}) {
    const result = await apiResponse(path, opts);
    const data = result.data;
    return {
      data,
      total: Number(result.response.headers.get('X-WP-Total') || (Array.isArray(data) ? data.length : 0)),
      totalPages: Number(result.response.headers.get('X-WP-TotalPages') || 1)
    };
  }

  function cropServiceProfileImage(file) {
    const maxBytes = Number(KOOPO_APPT_VENDOR.serviceProfileImageMaxBytes || 6291456);
    if (!file || !/^image\/(jpeg|png|webp|avif)$/i.test(file.type || '')) return Promise.reject(new Error('Choose a JPEG, PNG, WebP, or AVIF image.'));
    if (file.size > maxBytes) return Promise.reject(new Error('That image is larger than 6 MB.'));
    return new Promise((resolve, reject) => {
      const image = new Image();
      const url = URL.createObjectURL(file);
      image.onload = () => {
        URL.revokeObjectURL(url);
        const edge = Math.min(image.naturalWidth, image.naturalHeight);
        const output = Math.min(1400, edge);
        const canvas = document.createElement('canvas'); canvas.width = output; canvas.height = output;
        const context = canvas.getContext('2d');
        context.drawImage(image, (image.naturalWidth-edge)/2, (image.naturalHeight-edge)/2, edge, edge, 0, 0, output, output);
        canvas.toBlob(blob => blob ? resolve(new File([blob], String(file.name || 'profile').replace(/\.[^.]+$/, '') + '.jpg', {type:'image/jpeg'})) : reject(new Error('The image could not be prepared.')), 'image/jpeg', .9);
      };
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('The image could not be opened.')); };
      image.src = url;
    });
  }

  function prepareServiceProfileGalleryImage(file) {
    const maxBytes = Number(KOOPO_APPT_VENDOR.serviceProfileImageMaxBytes || 6291456);
    if (!file || !/^image\/(jpeg|png|webp|avif)$/i.test(file.type || '')) return Promise.reject(new Error('Choose a JPEG, PNG, WebP, or AVIF image.'));
    if (file.size > maxBytes) return Promise.reject(new Error('That image is larger than 6 MB.'));
    return new Promise((resolve, reject) => {
      const image = new Image(); const url = URL.createObjectURL(file);
      image.onload = () => {
        URL.revokeObjectURL(url);
        const scale = Math.min(1, 2000 / Math.max(image.naturalWidth, image.naturalHeight));
        const width = Math.max(1, Math.round(image.naturalWidth * scale)); const height = Math.max(1, Math.round(image.naturalHeight * scale));
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        canvas.getContext('2d').drawImage(image, 0, 0, width, height);
        canvas.toBlob(blob => blob ? resolve(new File([blob], String(file.name || 'gallery').replace(/\.[^.]+$/, '') + '.jpg', {type:'image/jpeg'})) : reject(new Error('The gallery image could not be prepared.')), 'image/jpeg', .9);
      };
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('The gallery image could not be opened.')); };
      image.src = url;
    });
  }

  async function uploadServiceProfileMedia(providerId, file, mediaRole, progress) {
    const isGallery = mediaRole === 'gallery';
    const prepared = isGallery ? await prepareServiceProfileGalleryImage(file) : await cropServiceProfileImage(file);
    const segment = isGallery ? 'gallery' : 'image';
    const base = String(KOOPO_APPT_VENDOR.mediaGatewayRest || '').replace(/\/$/, '') + `/appointments/service-profiles/${Number(providerId)}/${segment}/upload-sessions`;
    const key = `appointment-profile-${segment}-${providerId}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,9)}`;
    const created = await fetch(base, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':KOOPO_APPT_VENDOR.nonce,'Idempotency-Key':key}, body:JSON.stringify({filename:prepared.name,mimeType:prepared.type,sizeBytes:prepared.size,kind:'image',clientRequestId:key})}).then(async response => { const body=await response.json().catch(()=>({})); if(!response.ok) throw new Error(body.message || body.error || 'Unable to start the direct upload.'); return body; });
    if (!created.session || !created.session.id || !created.upload) throw new Error('No direct upload destination was returned.');
    try {
      await new Promise((resolve,reject)=>{ const xhr=new XMLHttpRequest(); xhr.open(created.upload.method||'PUT',created.upload.url,true); Object.keys(created.upload.headers||{}).forEach(name=>xhr.setRequestHeader(name,created.upload.headers[name])); xhr.upload.onprogress=event=>{if(progress&&event.lengthComputable)progress(Math.round(event.loaded*100/event.total));}; xhr.onload=()=>xhr.status>=200&&xhr.status<300?resolve():reject(new Error(`Direct upload failed (${xhr.status}).`)); xhr.onerror=()=>reject(new Error('Direct upload connection failed.')); xhr.send(prepared); });
      const completed = await fetch(`${base}/${encodeURIComponent(created.session.id)}/complete`, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':KOOPO_APPT_VENDOR.nonce},body:'{}'}).then(async response=>{const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.message||body.error||'Unable to finish the image upload.');return body;});
      if(!completed.serviceProfileMedia)throw new Error('The service profile image was not finalized.');
      return completed.serviceProfileMedia;
    } catch(error) {
      fetch(`${base}/${encodeURIComponent(created.session.id)}`,{method:'DELETE',credentials:'same-origin',headers:{'X-WP-Nonce':KOOPO_APPT_VENDOR.nonce}}).catch(()=>{});
      throw error;
    }
  }

  async function uploadServiceProfileImage(providerId, file, progress) {
    return uploadServiceProfileMedia(providerId, file, 'image', progress);
  }

  async function uploadClientFile(clientId, file, progress) {
    if (!file || !/^(image\/(jpeg|png|webp)|application\/pdf)$/i.test(file.type || '') || file.size <= 0 || file.size > 10 * 1024 * 1024) throw new Error('Choose a JPEG, PNG, WebP, or PDF up to 10 MB.');
    const kind = file.type === 'application/pdf' ? 'document' : 'image';
    const base = String(KOOPO_APPT_VENDOR.mediaGatewayRest || '').replace(/\/$/, '') + `/appointments/clients/${Number(clientId)}/files/upload-sessions`;
    const key = `appointment-client-${clientId}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,9)}`;
    const created = await fetch(base,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':KOOPO_APPT_VENDOR.nonce,'Idempotency-Key':key},body:JSON.stringify({filename:file.name,mimeType:file.type,sizeBytes:file.size,kind,clientRequestId:key})}).then(async response=>{const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.message||body.error||'Unable to start the private upload.');return body;});
    if(!created.session||!created.session.id||!created.upload)throw new Error('No direct upload destination was returned.');
    try{
      await new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();xhr.open(created.upload.method||'PUT',created.upload.url,true);Object.keys(created.upload.headers||{}).forEach(name=>xhr.setRequestHeader(name,created.upload.headers[name]));xhr.upload.onprogress=event=>{if(progress&&event.lengthComputable)progress(Math.round(event.loaded*100/event.total));};xhr.onload=()=>xhr.status>=200&&xhr.status<300?resolve():reject(new Error(`Direct upload failed (${xhr.status}).`));xhr.onerror=()=>reject(new Error('Direct upload connection failed.'));xhr.send(file);});
      const completed=await fetch(`${base}/${encodeURIComponent(created.session.id)}/complete`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':KOOPO_APPT_VENDOR.nonce},body:'{}'}).then(async response=>{const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.message||body.error||'Unable to finish the private upload.');return body;});
      if(!completed.clientFile)throw new Error('The private file was not finalized.');return completed.clientFile;
    }catch(error){fetch(`${base}/${encodeURIComponent(created.session.id)}`,{method:'DELETE',credentials:'same-origin',headers:{'X-WP-Nonce':KOOPO_APPT_VENDOR.nonce}}).catch(()=>{});throw error;}
  }

  function previewSelectedImage(input, target) {
    const file=input&&input.files&&input.files[0]; const node=target&&document.querySelector(target); if(!file||!node)return;
    const url=URL.createObjectURL(file); node.innerHTML=`<img src="${url}" alt="" />`; const image=node.querySelector('img'); if(image)image.onload=()=>URL.revokeObjectURL(url);
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
      const url = l.permalink ? String(l.permalink) : '';
      $select.append(`<option value="${l.id}" data-url="${escapeHtml(url)}">${escapeHtml(l.title)}</option>`);
    });
    return listings;
  }

  async function loadBookingContexts($select){
    $select.empty().append('<option value="">Select booking profile…</option>');
    let contexts = Array.isArray(KOOPO_APPT_VENDOR.contexts) && KOOPO_APPT_VENDOR.contexts.length
      ? KOOPO_APPT_VENDOR.contexts
      : await api('/vendor/booking-contexts', { method:'GET' });
    if (!Array.isArray(contexts)) contexts = [];
    contexts.forEach(context => {
      const label = `${context.title} — ${context.subject_type === 'provider' ? 'Professional' : 'Business'}`;
      $select.append(`<option value="${context.subject_id}" data-resource-id="${context.resource_id}" data-subject-type="${escapeHtml(context.subject_type)}" data-provider-id="${context.provider_id || 0}" data-listing-id="${context.listing_id || 0}" data-url="${escapeHtml(context.permalink || '')}">${escapeHtml(label)}</option>`);
    });
    if (!contexts.length) $select.append('<option value="">No booking profiles found</option>');
    return contexts;
  }

  function updateListingLink($select, $link) {
    if (!$select || !$select.length || !$link || !$link.length) return;
    const url = $select.find('option:selected').data('url');
    if (url) {
      $link.attr('href', url).removeClass('is-disabled').show();
    } else {
      $link.attr('href', '#').addClass('is-disabled');
    }
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
    const rawName = (booking && (booking.customer_name || booking.customer_email)) || 'Guest';
    // Customer names can come from legacy/API data with HTML entities already
    // encoded. Decode once before escaping for the HTML sink so "&amp;" does
    // not leak into the seller dashboard as visible text.
    const decoder = document.createElement('textarea');
    decoder.innerHTML = String(rawName);
    const name = decoder.value;
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
  utils.apiWithMeta = apiWithMeta;
  utils.loadVendorListings = loadVendorListings;
  utils.loadBookingContexts = loadBookingContexts;
  utils.updateListingLink = updateListingLink;
  utils.escapeHtml = escapeHtml;
  utils.formatCurrency = formatCurrency;
  utils.formatMoney = formatMoney;
  utils.parseDateTime = parseDateTime;
  utils.toYmd = toYmd;
  utils.toYmdHms = toYmdHms;
  utils.formatTime = formatTime;
  utils.renderAvatar = renderAvatar;
  utils.uploadServiceProfileImage = uploadServiceProfileImage;
  utils.uploadServiceProfileGalleryImage = (providerId, file, progress) => uploadServiceProfileMedia(providerId, file, 'gallery', progress);
  utils.uploadClientFile = uploadClientFile;
  utils.previewSelectedImage = previewSelectedImage;

  window.KOOPO_VENDOR_UTILS = utils;

  $(document).on('change','#koopo-provider-image',function(){ previewSelectedImage(this,'[data-koopo-provider-create-preview]'); });

  $(document).on('click', '#koopo-create-provider', async function(){
    const $button = $(this);
    const $status = $('.koopo-provider-create-status');
    const name = String($('#koopo-provider-name').val() || '').trim();
    const headline = String($('#koopo-provider-headline').val() || '').trim();
    const categoryId = parseInt($('#koopo-provider-category').val(), 10) || 0;
    const serviceModes = $('.koopo-provider-mode:checked').map(function(){ return $(this).val(); }).get();
    if (!name) { $status.text('Enter your service profile name.'); return; }
    if (!categoryId) { $status.text('Choose a primary service category.'); return; }
    if (!serviceModes.length) { $status.text('Choose at least one service delivery option.'); return; }
    $button.prop('disabled', true);
    $status.text('Creating profile…');
    try {
      let provider = await api('/providers', { method:'POST', body: JSON.stringify({ name, headline, category_id: categoryId, service_modes: serviceModes,
        location_name: $('#koopo-provider-location-name').val(), address: $('#koopo-provider-address').val(), city: $('#koopo-provider-city').val(), region: $('#koopo-provider-region').val(), postal_code: $('#koopo-provider-postal').val(), country: $('#koopo-provider-country').val(), latitude: $('#koopo-provider-latitude').val(), longitude: $('#koopo-provider-longitude').val(), location_public: $('#koopo-provider-location-public').is(':checked')
      }) });
      const imageInput = document.getElementById('koopo-provider-image');
      if (imageInput && imageInput.files && imageInput.files[0]) {
        $status.text('Uploading profile image directly…');
        try { await uploadServiceProfileImage(provider.id, imageInput.files[0], percent => $status.text(`Uploading profile image ${percent}%…`)); provider = await api(`/providers/${provider.id}`, {method:'GET'}); }
        catch (imageError) { $status.text(`Profile created. Image upload needs another try: ${imageError.message}`); window.setTimeout(() => window.location.reload(), 1800); return; }
      }
      if (document.querySelector('[data-koopo-provider-library]')) {
        $('#koopo-provider-name,#koopo-provider-headline,#koopo-provider-location-name,#koopo-provider-address,#koopo-provider-city,#koopo-provider-region,#koopo-provider-postal,#koopo-provider-latitude,#koopo-provider-longitude').val('');
        $('#koopo-provider-category').val(''); $('.koopo-provider-mode').prop('checked', false).filter('[value="at_location"]').prop('checked', true);
        if (imageInput) imageInput.value = '';
        $('[data-koopo-provider-create-preview]').html('<span aria-hidden="true">+</span>');
        $(document).trigger('koopo:provider-created', [provider]);
        $button.prop('disabled', false);
      } else {
        window.location.reload();
      }
    } catch (error) {
      $status.text(error.message || 'Unable to create service profile.');
      $button.prop('disabled', false);
    }
  });
})(jQuery);
