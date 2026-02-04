(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const utils = window.KOOPO_VENDOR_UTILS || {};
  const api = utils.api;
  const loadVendorListings = utils.loadVendorListings;
  const updateListingLink = utils.updateListingLink;
  if (!api || !loadVendorListings) return;

// ---------- Booking settings page ----------
  const $settingsPicker = $('.koopo-appt-settings__listing');
  const $viewListing = $('#koopo-view-listing-settings');
  if ($settingsPicker.length) {
    $settingsPicker.on('change', async function(){
      const listingId = parseInt($(this).val(),10) || 0;
      if (updateListingLink) updateListingLink($settingsPicker, $viewListing);
      if (!listingId) return;
      const data = await api(`/appointments/settings/${listingId}`, { method:'GET' });
      $('#koopo-setting-enabled').prop('checked', !!data.enabled);
    });
    loadVendorListings($settingsPicker).then(listings => {
      if (Array.isArray(listings) && listings.length) {
        $settingsPicker.prop('selectedIndex', 1).trigger('change');
      }
      if (updateListingLink) updateListingLink($settingsPicker, $viewListing);
    }).catch(()=>{});
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
})(jQuery);

  
