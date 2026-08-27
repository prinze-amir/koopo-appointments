(() => {
  'use strict';
  const config = window.KOOPO_PROVIDER_OWNER;
  const utils = window.KOOPO_VENDOR_UTILS;
  if (!config || !utils || !config.providerId) return;

  const providerId = Number(config.providerId);
  let profile = config.profile || {};
  let services = [];
  let lastTrigger = null;
  const escape = (value) => String(value == null ? '' : value).replace(/[&<>"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]));
  const status = (selector, message, error = false) => {
    const node = document.querySelector(selector);
    if (!node) return;
    node.textContent = message;
    node.classList.toggle('is-error', error);
  };

  function openDialog(name, trigger) {
    const dialog = document.querySelector(`[data-koopo-owner-dialog="${name}"]`);
    if (!dialog) return;
    lastTrigger = trigger || document.activeElement;
    document.documentElement.classList.add('koopo-owner-modal-open');
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    dialog.querySelector('input:not([type="hidden"]), button, select, textarea')?.focus({preventScroll: true});
  }
  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    document.documentElement.classList.remove('koopo-owner-modal-open');
    lastTrigger?.focus?.({preventScroll: true});
  }
  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-koopo-owner-open]');
    if (opener) openDialog(opener.dataset.koopoOwnerOpen, opener);
    const closer = event.target.closest('[data-koopo-owner-close]');
    if (closer) closeDialog(closer.closest('dialog'));
  });
  document.querySelectorAll('.koopo-owner-dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => document.documentElement.classList.remove('koopo-owner-modal-open'));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) closeDialog(dialog); });
  });

  function renderGallery() {
    const node = document.querySelector('[data-koopo-owner-gallery]');
    if (!node) return;
    const gallery = Array.isArray(profile.gallery) ? profile.gallery : [];
    node.innerHTML = gallery.length ? gallery.map((image) => `<figure data-image-id="${Number(image.id)}"><img src="${escape(image.url)}" alt=""><button type="button" data-koopo-gallery-remove aria-label="Remove photo">×</button></figure>`).join('') : '<p>No gallery photos yet.</p>';
  }
  renderGallery();

  document.querySelector('[data-koopo-owner-image]')?.addEventListener('change', async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    status('[data-koopo-owner-image-status]', 'Preparing image…');
    try {
      const media = await utils.uploadServiceProfileImage(providerId, file, (percent) => status('[data-koopo-owner-image-status]', `Uploading directly ${percent}%…`));
      const avatar = document.querySelector('[data-koopo-owner-avatar]');
      if (avatar && media.url) avatar.innerHTML = `<img src="${escape(media.url)}" alt="">`;
      status('[data-koopo-owner-image-status]', 'Profile image updated. Refresh the page to see it in the public header.');
      event.target.value = '';
    } catch (error) { status('[data-koopo-owner-image-status]', error.message || 'Image upload failed.', true); }
  });

  document.querySelector('[data-koopo-owner-gallery-files]')?.addEventListener('change', async (event) => {
    const files = Array.from(event.target.files || []);
    const available = Math.max(0, 12 - (profile.gallery || []).length);
    if (!available) return status('[data-koopo-owner-gallery-status]', 'This gallery already has 12 photos.', true);
    try {
      const selected = files.slice(0, available);
      for (let index = 0; index < selected.length; index += 1) {
        await utils.uploadServiceProfileGalleryImage(providerId, selected[index], (percent) => status('[data-koopo-owner-gallery-status]', `Uploading photo ${index + 1} of ${selected.length} — ${percent}%…`));
      }
      profile = await utils.api(`/providers/${providerId}`, {method: 'GET'});
      renderGallery();
      status('[data-koopo-owner-gallery-status]', `Added ${selected.length} ${selected.length === 1 ? 'photo' : 'photos'}.`);
      event.target.value = '';
    } catch (error) { status('[data-koopo-owner-gallery-status]', error.message || 'Gallery upload failed.', true); }
  });

  document.querySelector('[data-koopo-owner-gallery]')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-koopo-gallery-remove]');
    if (!button) return;
    const imageId = Number(button.closest('[data-image-id]')?.dataset.imageId || 0);
    if (!imageId) return;
    status('[data-koopo-owner-gallery-status]', 'Removing photo…');
    try {
      const result = await utils.api(`/providers/${providerId}/gallery/${imageId}`, {method: 'DELETE'});
      profile.gallery = result.gallery || [];
      renderGallery();
      status('[data-koopo-owner-gallery-status]', 'Photo removed.');
    } catch (error) { status('[data-koopo-owner-gallery-status]', error.message || 'Unable to remove photo.', true); }
  });

  const settingsForm = document.querySelector('[data-koopo-owner-settings]');
  if (settingsForm) {
    settingsForm.elements.name.value = profile.name || '';
    settingsForm.elements.headline.value = profile.headline || '';
    settingsForm.elements.bio.value = profile.bio || '';
    settingsForm.elements.category_id.innerHTML = '<option value="">Choose a category</option>' + (config.categories || []).map((category) => `<option value="${Number(category.id)}">${escape(`${category.glyph ? `${category.glyph} ` : ''}${category.name}`)}</option>`).join('');
    settingsForm.elements.category_id.value = profile.category?.id || '';
    settingsForm.querySelectorAll('[name="service_modes[]"]').forEach((field) => { field.checked = (profile.service_modes || []).includes(field.value); });
    settingsForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const modes = Array.from(settingsForm.querySelectorAll('[name="service_modes[]"]:checked')).map((field) => field.value);
      if (!modes.length) return status('[data-koopo-owner-settings-status]', 'Choose at least one service delivery option.', true);
      status('[data-koopo-owner-settings-status]', 'Saving…');
      try {
        profile = await utils.api(`/providers/${providerId}`, {method: 'POST', body: JSON.stringify({name: settingsForm.elements.name.value, headline: settingsForm.elements.headline.value, bio: settingsForm.elements.bio.value, category_id: Number(settingsForm.elements.category_id.value), service_modes: modes})});
        document.querySelector('.koopo-pro-hero h1').textContent = profile.name;
        const headline = document.querySelector('.koopo-pro-hero__headline');
        if (headline) headline.textContent = profile.headline || '';
        status('[data-koopo-owner-settings-status]', 'Profile saved.');
      } catch (error) { status('[data-koopo-owner-settings-status]', error.message || 'Save failed.', true); }
    });
  }

  const serviceForm = document.querySelector('[data-koopo-owner-service-form]');
  const serviceList = document.querySelector('[data-koopo-owner-services]');
  const locked = document.querySelector('[data-koopo-owner-service-locked]');
  function resetServiceForm() { serviceForm?.reset(); if (serviceForm) serviceForm.elements.id.value = ''; }
  function renderServices() {
    if (!serviceList) return;
    serviceList.innerHTML = services.length ? services.map((service) => `<article data-service-id="${Number(service.id)}"><div><strong>${escape(service.title)}</strong><small>${Number(service.duration_minutes)} min · ${escape(service.price_label || `${window.KOOPO_APPT_VENDOR.currency_symbol || '$'}${Number(service.price).toFixed(2)}`)}${service.status === 'inactive' ? ' · Inactive' : ''}</small></div><button type="button" data-service-edit>Edit</button><button type="button" data-service-delete>Delete</button></article>`).join('') : '<p>No services yet. Add your first bookable service below.</p>';
  }
  async function loadServices() {
    services = await utils.api(`/services/by-provider/${providerId}?include_inactive=1`, {method: 'GET'});
    renderServices();
  }
  if (!config.canManageServices) {
    if (serviceForm) serviceForm.hidden = true;
    if (locked) locked.hidden = false;
  } else {
    loadServices().catch((error) => status('[data-koopo-owner-service-status]', error.message || 'Unable to load services.', true));
  }
  serviceList?.addEventListener('click', async (event) => {
    const row = event.target.closest('[data-service-id]');
    const service = services.find((item) => Number(item.id) === Number(row?.dataset.serviceId));
    if (!service) return;
    if (event.target.closest('[data-service-edit]')) {
      ['id', 'title', 'price', 'duration_minutes', 'status', 'description'].forEach((key) => { serviceForm.elements[key].value = service[key] == null ? '' : service[key]; });
      serviceForm.elements.title.focus();
    }
    if (event.target.closest('[data-service-delete]') && window.confirm(`Remove “${service.title}”?`)) {
      try { await utils.api(`/services/${service.id}`, {method: 'DELETE'}); await loadServices(); status('[data-koopo-owner-service-status]', 'Service removed.'); }
      catch (error) { status('[data-koopo-owner-service-status]', error.message || 'Unable to remove service.', true); }
    }
  });
  document.querySelector('[data-koopo-service-reset]')?.addEventListener('click', resetServiceForm);
  serviceForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(serviceForm).entries());
    const id = Number(data.id || 0);
    const payload = {title: data.title, price: Number(data.price), duration_minutes: Number(data.duration_minutes), status: data.status, description: data.description, provider_id: providerId};
    status('[data-koopo-owner-service-status]', 'Saving…');
    try {
      await utils.api(id ? `/services/${id}` : '/services', {method: 'POST', body: JSON.stringify(payload)});
      resetServiceForm(); await loadServices(); status('[data-koopo-owner-service-status]', 'Service saved.');
    } catch (error) { status('[data-koopo-owner-service-status]', error.message || 'Unable to save service.', true); }
  });
})();
