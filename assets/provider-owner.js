(() => {
  'use strict';
  const config = window.KOOPO_PROVIDER_OWNER;
  const utils = window.KOOPO_VENDOR_UTILS;
  if (!config || !utils || !config.providerId) return;

  const providerId = Number(config.providerId);
  let profile = config.profile || {};
  let services = [];
  let lastTrigger = null;
  let pendingPortfolio = [];
  let draggedPortfolioId = 0;
  let portfolioBusy = false;
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
    const saved = gallery.map((image, index) => `<figure draggable="true" tabindex="0" data-image-id="${Number(image.id)}" aria-label="Portfolio photo ${index + 1} of ${gallery.length}. Drag to reorder."><img src="${escape(image.url)}" alt=""><figcaption><span aria-hidden="true">⠿</span><button type="button" data-koopo-gallery-move="up" ${index === 0 ? 'disabled' : ''} aria-label="Move photo earlier">←</button><button type="button" data-koopo-gallery-move="down" ${index === gallery.length - 1 ? 'disabled' : ''} aria-label="Move photo later">→</button><button type="button" data-koopo-gallery-remove aria-label="Remove photo">×</button></figcaption></figure>`).join('');
    const pending = pendingPortfolio.map((item) => `<figure class="is-uploading" data-pending-key="${escape(item.key)}"><img src="${escape(item.url)}" alt=""><span class="koopo-owner-gallery__uploading">Uploading</span></figure>`).join('');
    node.innerHTML = saved + pending || '<p>Drop photos above to start your portfolio.</p>';
  }
  renderGallery();

  async function persistPortfolioOrder(next) {
    if (portfolioBusy) return;
    const previous = (profile.gallery || []).slice();
    portfolioBusy = true;
    profile.gallery = next;
    renderGallery();
    status('[data-koopo-owner-gallery-status]', 'Saving portfolio order…');
    try {
      const result = await utils.api(`/providers/${providerId}/gallery`, {method: 'POST', body: JSON.stringify({attachment_ids: next.map((image) => image.id)})});
      profile.gallery = result.gallery || next;
      renderGallery();
      status('[data-koopo-owner-gallery-status]', 'Portfolio order saved.');
    } catch (error) {
      profile.gallery = previous;
      renderGallery();
      status('[data-koopo-owner-gallery-status]', error.message || 'Unable to save portfolio order.', true);
    } finally { portfolioBusy = false; }
  }

  async function uploadPortfolio(files) {
    const available = Math.max(0, 12 - ((profile.gallery || []).length + pendingPortfolio.length));
    if (!files.length || portfolioBusy) return;
    if (!available) return status('[data-koopo-owner-gallery-status]', 'This portfolio already has 12 photos.', true);
    const selected = files.slice(0, available);
    const batch = selected.map((file, index) => ({file, key: `${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`, url: URL.createObjectURL(file)}));
    pendingPortfolio = pendingPortfolio.concat(batch);
    renderGallery();
    portfolioBusy = true;
    try {
      for (let index = 0; index < batch.length; index += 1) {
        const item = batch[index];
        const media = await utils.uploadServiceProfileGalleryImage(providerId, item.file, (percent) => status('[data-koopo-owner-gallery-status]', `Uploading photo ${index + 1} of ${batch.length} — ${percent}%…`));
        profile.gallery = Array.isArray(media.gallery) ? media.gallery : profile.gallery;
        pendingPortfolio = pendingPortfolio.filter((pending) => pending.key !== item.key);
        URL.revokeObjectURL(item.url);
        renderGallery();
      }
      status('[data-koopo-owner-gallery-status]', selected.length < files.length ? `Added ${selected.length} photos. The portfolio limit is 12.` : `Added ${selected.length} ${selected.length === 1 ? 'photo' : 'photos'} to your portfolio.`);
    } catch (error) {
      status('[data-koopo-owner-gallery-status]', error.message || 'Portfolio upload failed.', true);
    } finally {
      batch.forEach((item) => URL.revokeObjectURL(item.url));
      pendingPortfolio = pendingPortfolio.filter((item) => !batch.some((batchItem) => batchItem.key === item.key));
      portfolioBusy = false;
      renderGallery();
      const field = document.querySelector('[data-koopo-owner-gallery-files]');
      if (field) field.value = '';
    }
  }

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

  document.querySelector('[data-koopo-owner-gallery-files]')?.addEventListener('change', (event) => uploadPortfolio(Array.from(event.target.files || [])));
  const portfolioDrop = document.querySelector('[data-koopo-owner-gallery-drop]');
  portfolioDrop?.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); document.querySelector('[data-koopo-owner-gallery-files]')?.click(); } });
  ['dragenter', 'dragover'].forEach((name) => portfolioDrop?.addEventListener(name, (event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; portfolioDrop.classList.add('is-drag-over'); }));
  portfolioDrop?.addEventListener('dragleave', (event) => { if (!event.relatedTarget || !portfolioDrop.contains(event.relatedTarget)) portfolioDrop.classList.remove('is-drag-over'); });
  portfolioDrop?.addEventListener('drop', (event) => { event.preventDefault(); portfolioDrop.classList.remove('is-drag-over'); uploadPortfolio(Array.from(event.dataTransfer.files || [])); });

  document.querySelector('[data-koopo-owner-gallery]')?.addEventListener('click', async (event) => {
    const move = event.target.closest('[data-koopo-gallery-move]');
    if (move) {
      const imageId = Number(move.closest('[data-image-id]')?.dataset.imageId || 0);
      const from = (profile.gallery || []).findIndex((image) => Number(image.id) === imageId);
      const to = move.dataset.koopoGalleryMove === 'up' ? from - 1 : from + 1;
      if (from >= 0 && to >= 0 && to < profile.gallery.length) { const next = profile.gallery.slice(); [next[from], next[to]] = [next[to], next[from]]; await persistPortfolioOrder(next); }
      return;
    }
    const button = event.target.closest('[data-koopo-gallery-remove]');
    if (!button) return;
    if (portfolioBusy) return;
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
  const ownerGallery = document.querySelector('[data-koopo-owner-gallery]');
  ownerGallery?.addEventListener('dragstart', (event) => { const figure = event.target.closest('[data-image-id]'); if (!figure || portfolioBusy) { event.preventDefault(); return; } draggedPortfolioId = Number(figure.dataset.imageId) || 0; figure.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(draggedPortfolioId)); });
  ownerGallery?.addEventListener('dragend', (event) => { event.target.closest('[data-image-id]')?.classList.remove('is-dragging'); ownerGallery.querySelectorAll('.is-drag-target').forEach((item) => item.classList.remove('is-drag-target')); draggedPortfolioId = 0; });
  ownerGallery?.addEventListener('dragover', (event) => { const figure = event.target.closest('[data-image-id]'); if (!figure || !draggedPortfolioId) return; event.preventDefault(); ownerGallery.querySelectorAll('.is-drag-target').forEach((item) => item.classList.remove('is-drag-target')); figure.classList.add('is-drag-target'); });
  ownerGallery?.addEventListener('drop', async (event) => { const target = event.target.closest('[data-image-id]'); if (!target || !draggedPortfolioId) return; event.preventDefault(); const targetId = Number(target.dataset.imageId) || 0; ownerGallery.querySelectorAll('.is-drag-target').forEach((item) => item.classList.remove('is-drag-target')); if (targetId === draggedPortfolioId) return; const from = profile.gallery.findIndex((image) => Number(image.id) === draggedPortfolioId); const to = profile.gallery.findIndex((image) => Number(image.id) === targetId); if (from < 0 || to < 0) return; const next = profile.gallery.slice(); const [moved] = next.splice(from, 1); next.splice(to, 0, moved); await persistPortfolioOrder(next); });

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
