(function($){
  'use strict';

  const config = window.KOOPO_APPT_SETTINGS || {};

  async function api(path, options = {}) {
    const response = await fetch(`${config.restUrl}${path}`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
        ...(options.headers || {})
      }
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || data.error || 'Calendar request failed.');
    return data;
  }

  function calendarContextFor($mount) {
    const selected = parseInt($('.koopo-appt-settings__listing').val(), 10);
    if ($mount.data('mode') === 'dokan' && Number.isFinite(selected) && selected > 0) return { resourceId: selected, listingId: 0 };
    const inline = parseInt($mount.closest('.koopo-appt-settings-inline').data('listing-id'), 10);
    if (Number.isFinite(inline) && inline > 0) return { resourceId: 0, listingId: inline };
    const localized = parseInt(config.listingId, 10);
    return { resourceId: 0, listingId: Number.isFinite(localized) ? localized : 0 };
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[char]);
  }

  function providerLabel(provider) {
    return provider === 'google' ? 'Google Calendar' : 'Microsoft Outlook';
  }

  function panelMarkup() {
    return `
      <section class="kcal" aria-labelledby="kcal-title">
        <div class="kcal__heading">
          <div>
            <h4 id="kcal-title">Calendar Sync</h4>
            <p>Mirror Koopo appointments out and import privacy-safe busy time back in.</p>
          </div>
          <button type="button" class="kcal__sync-all">Sync now</button>
        </div>
        <div class="kcal__authority">
          <strong>Koopo stays in control.</strong>
          External events are read-only. Selected calendars can block booking time, but Koopo never imports event titles or lets an external calendar change an appointment.
        </div>
        <div class="kcal__notice" role="status" aria-live="polite"></div>
        <div class="kcal__providers"><span class="kcal__loading">Loading calendar connections…</span></div>
      </section>`;
  }

  function showNotice($panel, message, error = false) {
    $panel.find('.kcal__notice')
      .toggleClass('is-error', error)
      .text(message || '');
  }

  function connectionFor(state, provider) {
    return (state.connections || []).find(item => item.provider === provider) || null;
  }

  function bindingFor(state, provider, context) {
    return (state.bindings || []).find(item => item.provider === provider && (
      context.resourceId ? item.resource_id === context.resourceId : item.listing_id === context.listingId
    )) || null;
  }

  function render($panel, state, context) {
    if (!context.resourceId && !context.listingId) {
      $panel.find('.kcal__providers').html('<p>Select a booking profile before configuring calendar sync.</p>');
      return;
    }
    const cards = ['google', 'microsoft'].map(provider => {
      const connection = connectionFor(state, provider);
      const binding = bindingFor(state, provider, context);
      const configured = !!(state.providers && state.providers[provider] && state.providers[provider].configured);
      if (!configured) {
        return `<article class="kcal__card">
          <div><h5>${providerLabel(provider)}</h5><p>Waiting for the site administrator to configure OAuth credentials.</p></div>
          <span class="kcal__status">Not configured</span>
        </article>`;
      }
      if (!connection) {
        return `<article class="kcal__card">
          <div><h5>${providerLabel(provider)}</h5><p>Automatically add, update, and remove Koopo appointments.</p></div>
          <button type="button" class="kcal__connect" data-provider="${provider}">Connect</button>
        </article>`;
      }
      return `<article class="kcal__card" data-provider-card="${provider}" data-connection-id="${connection.id}">
        <div class="kcal__card-main">
          <h5>${providerLabel(provider)}</h5>
          <p>${escapeHtml(connection.email || connection.label)} · <span class="kcal__status kcal__status--${escapeHtml(connection.status)}">${escapeHtml(connection.status)}</span></p>
          ${connection.last_error ? `<p class="kcal__error">${escapeHtml(connection.last_error)}</p>` : ''}
          <label>Destination calendar
            <select class="kcal__calendar-select" data-provider="${provider}">
              <option value="">Loading calendars…</option>
            </select>
          </label>
          <label>Event details
            <select class="kcal__privacy" data-provider="${provider}">
              <option value="minimal" ${!binding || binding.privacy_mode === 'minimal' ? 'selected' : ''}>Minimal — service and business only</option>
              <option value="standard" ${binding && binding.privacy_mode === 'standard' ? 'selected' : ''}>Standard — include customer name</option>
            </select>
          </label>
          <details class="kcal__availability" data-provider="${provider}">
            <summary>Availability blocking</summary>
            <p>Select the calendars that should make matching Koopo times unavailable.</p>
            <div class="kcal__availability-calendars"><span class="kcal__loading">Loading availability calendars…</span></div>
            <button type="button" class="kcal__save-availability" data-provider="${provider}">Save availability calendars</button>
          </details>
        </div>
        <div class="kcal__actions">
          <button type="button" class="kcal__save-binding" data-provider="${provider}">Save</button>
          <button type="button" class="kcal__disconnect" data-connection-id="${connection.id}">Disconnect</button>
        </div>
      </article>`;
    });

    const ics = bindingFor(state, 'ics', context);
    cards.push(`<article class="kcal__card">
      <div class="kcal__card-main">
        <h5>Apple Calendar / iCalendar</h5>
        <p>Subscribe with Apple Calendar or another calendar app. Calendar refresh timing is controlled by that app.</p>
        ${ics && ics.subscription_url ? `<input class="kcal__subscription-url" type="text" readonly value="${escapeHtml(ics.subscription_url)}" aria-label="Calendar subscription URL" />` : ''}
      </div>
      <div class="kcal__actions">
        ${ics && ics.subscription_url ? '<button type="button" class="kcal__copy-subscription">Copy link</button>' : ''}
        <button type="button" class="kcal__create-subscription">${ics ? 'Regenerate link' : 'Create subscription link'}</button>
      </div>
    </article>`);
    $panel.find('.kcal__providers').html(cards.join(''));

    ['google', 'microsoft'].forEach(provider => {
      const connection = connectionFor(state, provider);
      if (!connection) return;
      api(`/appointments/calendar/connections/${connection.id}/calendars`)
        .then(data => {
          const binding = bindingFor(state, provider, context);
          const options = (data.items || []).map(calendar => `<option value="${escapeHtml(calendar.id)}" ${binding && binding.calendar_id === calendar.id ? 'selected' : ''}>${escapeHtml(calendar.name)}${calendar.primary ? ' (Primary)' : ''}</option>`);
          $panel.find(`.kcal__calendar-select[data-provider="${provider}"]`).html(options.join('') || '<option value="">No writable calendars found</option>');
        })
        .catch(error => showNotice($panel, error.message, true));
      const resourceId = context.resourceId || (binding && binding.resource_id) || 0;
      if (resourceId) loadAvailabilityCalendars($panel, provider, connection.id, resourceId);
    });
  }

  async function loadAvailabilityCalendars($panel, provider, connectionId, resourceId) {
    const $target = $panel.find(`.kcal__availability[data-provider="${provider}"] .kcal__availability-calendars`);
    try {
      const data = await api(`/appointments/calendar/connections/${connectionId}/availability-calendars?resource_id=${resourceId}`);
      const rows = (data.items || []).map(calendar => `
        <div class="kcal__availability-row" data-calendar-id="${escapeHtml(calendar.id)}" data-calendar-name="${escapeHtml(calendar.name)}" data-calendar-timezone="${escapeHtml(calendar.timezone || 'UTC')}">
          <label class="kcal__availability-toggle"><input type="checkbox" class="kcal__availability-enabled" ${calendar.enabled ? 'checked' : ''}> <span>${escapeHtml(calendar.name)}${calendar.primary ? ' (Primary)' : ''}</span></label>
          <select class="kcal__busy-mode" aria-label="How this calendar affects availability">
            <option value="respect_provider" ${calendar.busy_mode === 'respect_provider' ? 'selected' : ''}>Block events marked busy</option>
            <option value="all_events" ${calendar.busy_mode === 'all_events' ? 'selected' : ''}>Block every event</option>
            <option value="informational" ${calendar.busy_mode === 'informational' ? 'selected' : ''}>Informational only</option>
          </select>
          <select class="kcal__refresh-minutes" aria-label="Availability refresh interval">
            ${[5,15,30,60].map(minutes => `<option value="${minutes}" ${Number(calendar.refresh_minutes) === minutes ? 'selected' : ''}>Every ${minutes} minutes</option>`).join('')}
          </select>
          <small>${calendar.last_synced_at ? `Last refreshed ${escapeHtml(calendar.last_synced_at)} UTC` : 'Not refreshed yet'}${calendar.last_error ? ` · ${escapeHtml(calendar.last_error)}` : ''}</small>
        </div>`);
      $target.html(rows.join('') || '<p>No readable calendars were found.</p>');
      $target.closest('.kcal__availability').data('connection-id', connectionId).data('resource-id', resourceId);
    } catch (error) {
      $target.html(`<p class="kcal__error">${escapeHtml(error.message)}</p>`);
    }
  }

  async function load($panel) {
    const context = calendarContextFor($panel.closest('.koopo-appt-settings-mount'));
    $panel.data('listing-id', context.listingId);
    $panel.data('resource-id', context.resourceId);
    try {
      const state = await api('/appointments/calendar/connections');
      $panel.data('state', state);
      render($panel, state, context);
    } catch (error) {
      showNotice($panel, error.message, true);
      $panel.find('.kcal__providers').empty();
    }
  }

  function mountOne($mount) {
    let $panel = $mount.find('.kcal');
    if (!$panel.length) {
      $mount.append(panelMarkup());
      $panel = $mount.find('.kcal');
    }
    load($panel);
  }

  function mountAll() {
    $('.koopo-appt-settings-mount').each(function(){
      mountOne($(this));
    });
  }

  $(document).on('click', '.kcal__connect', async function(){
    const $panel = $(this).closest('.kcal');
    try {
      const data = await api(`/appointments/calendar/connections/${$(this).data('provider')}/authorize`, {
        method: 'POST', body: JSON.stringify({ listing_id: $panel.data('listing-id'), resource_id: $panel.data('resource-id') })
      });
      window.location.assign(data.authorize_url);
    } catch (error) { showNotice($panel, error.message, true); }
  });

  $(document).on('click', '.kcal__save-binding', async function(){
    const $panel = $(this).closest('.kcal');
    const provider = $(this).data('provider');
    const state = $panel.data('state') || {};
    const connection = connectionFor(state, provider);
    const $select = $panel.find(`.kcal__calendar-select[data-provider="${provider}"]`);
    if (!connection || !$select.val()) return showNotice($panel, 'Select a writable calendar.', true);
    try {
      const bindingPath = $panel.data('resource-id')
        ? `/appointments/calendar/resource-bindings/${$panel.data('resource-id')}`
        : `/appointments/calendar/bindings/${$panel.data('listing-id')}`;
      await api(bindingPath, {
        method: 'PUT',
        body: JSON.stringify({
          provider,
          connection_id: connection.id,
          calendar_id: $select.val(),
          calendar_name: $select.find('option:selected').text().replace(' (Primary)', ''),
          privacy_mode: $panel.find(`.kcal__privacy[data-provider="${provider}"]`).val(),
          enabled: true
        })
      });
      showNotice($panel, 'Calendar settings saved. Existing appointments are queued for synchronization.');
      load($panel);
    } catch (error) { showNotice($panel, error.message, true); }
  });

  $(document).on('click', '.kcal__save-availability', async function(){
    const $panel = $(this).closest('.kcal');
    const $section = $(this).closest('.kcal__availability');
    const sources = $section.find('.kcal__availability-row').map(function(){
      const $row = $(this);
      if (!$row.find('.kcal__availability-enabled').is(':checked')) return null;
      return {
        calendar_id: String($row.data('calendar-id') || ''),
        enabled: true,
        busy_mode: $row.find('.kcal__busy-mode').val(),
        refresh_minutes: Number($row.find('.kcal__refresh-minutes').val() || 15)
      };
    }).get();
    $(this).prop('disabled', true);
    showNotice($panel, 'Refreshing external availability…');
    try {
      const result = await api(`/appointments/calendar/resource-availability-sources/${$section.data('resource-id')}`, {
        method: 'PUT',
        body: JSON.stringify({ connection_id: $section.data('connection-id'), sources })
      });
      showNotice($panel, `${result.saved || 0} availability calendars saved · ${(result.sync && result.sync.blocks) || 0} busy periods refreshed.`);
      load($panel);
    } catch (error) { showNotice($panel, error.message, true); }
    finally { $(this).prop('disabled', false); }
  });

  $(document).on('click', '.kcal__create-subscription', async function(){
    const $panel = $(this).closest('.kcal');
    if ($(this).text().includes('Regenerate') && !window.confirm('Regenerate the link? The previous calendar subscription will stop updating.')) return;
    try {
      const subscriptionPath = $panel.data('resource-id')
        ? `/appointments/calendar/resource-subscriptions/${$panel.data('resource-id')}`
        : `/appointments/calendar/subscriptions/${$panel.data('listing-id')}`;
      await api(subscriptionPath, {
        method: 'POST', body: JSON.stringify({ privacy_mode: 'minimal' })
      });
      showNotice($panel, 'Private calendar subscription link created.');
      load($panel);
    } catch (error) { showNotice($panel, error.message, true); }
  });

  $(document).on('click', '.kcal__copy-subscription', async function(){
    const $panel = $(this).closest('.kcal');
    const value = $panel.find('.kcal__subscription-url').val();
    try {
      await navigator.clipboard.writeText(value);
      showNotice($panel, 'Subscription link copied.');
    } catch (error) {
      $panel.find('.kcal__subscription-url').trigger('select');
      showNotice($panel, 'Select and copy the subscription link.');
    }
  });

  $(document).on('click', '.kcal__sync-all', async function(){
    const $panel = $(this).closest('.kcal');
    try {
      const syncPath = $panel.data('resource-id')
        ? `/appointments/calendar/resource-sync/${$panel.data('resource-id')}`
        : `/appointments/calendar/sync/${$panel.data('listing-id')}`;
      const data = await api(syncPath, { method: 'POST', body: '{}' });
      showNotice($panel, `${data.queued || 0} appointment updates queued · ${(data.availability && data.availability.blocks) || 0} busy periods refreshed.`);
    } catch (error) { showNotice($panel, error.message, true); }
  });

  $(document).on('click', '.kcal__disconnect', async function(){
    if (!window.confirm('Disconnect this calendar? Its imported busy periods will be removed; Koopo appointments will not change.')) return;
    const $panel = $(this).closest('.kcal');
    try {
      await api(`/appointments/calendar/connections/${$(this).data('connection-id')}`, { method: 'DELETE' });
      showNotice($panel, 'Calendar disconnected.');
      load($panel);
    } catch (error) { showNotice($panel, error.message, true); }
  });

  $(document).on('koopo:appointments-settings-mounted', '.koopo-appt-settings-mount', function(){
    mountOne($(this));
  });

  $(mountAll);
})(jQuery);
