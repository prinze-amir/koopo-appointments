(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const utils = window.KOOPO_VENDOR_UTILS || {};
  const api = utils.api;
  const loadBookingContexts = utils.loadBookingContexts;
  const updateListingLink = utils.updateListingLink;
  if (!api || !loadBookingContexts) return;

// ---------- Booking settings page ----------
  const $settingsPicker = $('.koopo-appt-settings__listing');
  const $viewListing = $('#koopo-view-listing-settings');
  if ($settingsPicker.length) {
    $settingsPicker.on('change', async function(){
      const resourceId = parseInt($(this).find('option:selected').data('resource-id'),10) || 0;
      if (updateListingLink) updateListingLink($settingsPicker, $viewListing);
      if (!resourceId) return;
      const data = await api(`/resources/${resourceId}/settings`, { method:'GET' });
      $('#koopo-setting-enabled').prop('checked', !!data.enabled);
    });
    loadBookingContexts($settingsPicker).then(contexts => {
      if (Array.isArray(contexts) && contexts.length) {
        $settingsPicker.prop('selectedIndex', 1).trigger('change');
      }
      if (updateListingLink) updateListingLink($settingsPicker, $viewListing);
    }).catch(()=>{});
    $('#koopo-settings-save').on('click', async function(){
      const resourceId = parseInt($settingsPicker.find('option:selected').data('resource-id'),10) || 0;
      if (!resourceId) { alert('Select a booking profile first.'); return; }
      await api(`/resources/${resourceId}/settings`, {
        method:'POST',
        body: JSON.stringify({ enabled: $('#koopo-setting-enabled').is(':checked') })
      });
      alert('Saved');
    });
  }
})(jQuery);

  
