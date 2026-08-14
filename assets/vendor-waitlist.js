(function($){
  const u = window.KOOPO_VENDOR_UTILS;
  if (!u) return;
  const $select = $('[data-koopo-waitlist-context]');
  const $settings = $('[data-koopo-waitlist-settings]');
  const $board = $('[data-koopo-waitlist-board]');
  const esc = u.escapeHtml;

  function resourceId(){ return Number($select.find(':selected').data('resource-id') || 0); }
  function render(rows){
    if (!rows.length) { $board.html('<div class="koopo-card koopo-empty-state"><span class="koopo-eyebrow">Demand is clear</span><h3>No one is waiting right now.</h3><p>Waitlist requests will appear here in priority order.</p></div>'); return; }
    $board.html(rows.map(row => `<article class="koopo-card koopo-waitlist-row">
      <div><span class="koopo-status koopo-status--${esc(row.status)}">${esc(row.status)}</span><h3>${esc(row.customer_name || 'Customer')}</h3><p>${esc(row.service_title)} · ${esc((row.preferred_days || []).join(', ') || 'Any day')} · ${esc(row.earliest_time || 'Any time')}–${esc(row.latest_time || 'Any time')}</p><small>${esc((row.channels || []).join(' + '))}</small></div>
      <div class="koopo-waitlist-row__actions"><label>Priority <input type="number" min="1" max="1000" value="${row.priority}" data-wait-priority="${row.id}"></label><button type="button" class="koopo-btn koopo-btn--secondary" data-manual-offer="${row.id}" ${row.status === 'matched' ? 'disabled' : ''}>Offer a time</button></div>
    </article>`).join(''));
  }
  async function load(){
    const id = resourceId(); if (!id) return;
    $board.html('<div class="koopo-card"><p>Loading waitlist…</p></div>');
    try {
      const [rows, settings] = await Promise.all([u.api(`/vendor/waitlist?resource_id=${id}`), u.api(`/vendor/waitlist/settings/${id}`)]);
      $('[data-wait-mode]').val(settings.mode); $('[data-wait-minutes]').val(String(settings.offer_minutes)); $('[data-wait-batch]').val(settings.batch_size); $settings.prop('hidden', false); render(rows);
    } catch(error) { $board.html(`<div class="koopo-card"><p>${esc(error.message)}</p></div>`); }
  }
  u.loadBookingContexts($select).then(() => { if ($select.find('option').length === 2) $select.prop('selectedIndex', 1).trigger('change'); });
  $select.on('change', load);
  $('[data-wait-save]').on('click', async function(){
    const id=resourceId(); const $status=$('[data-wait-status]'); $(this).prop('disabled',true); $status.text('Saving…');
    try { await u.api(`/vendor/waitlist/settings/${id}`, {method:'POST',body:JSON.stringify({mode:$('[data-wait-mode]').val(),offer_minutes:Number($('[data-wait-minutes]').val()),batch_size:Number($('[data-wait-batch]').val())})}); $status.text('Saved.'); }
    catch(error){ $status.text(error.message); } finally { $(this).prop('disabled',false); }
  });
  $board.on('click','[data-manual-offer]',async function(){
    const now=new Date(); now.setMinutes(now.getMinutes()+30,0,0); const end=new Date(now.getTime()+60*60*1000);
    const startValue=window.prompt('Opening start (YYYY-MM-DD HH:MM:SS)',u.toYmdHms(now)); if(!startValue)return;
    const endValue=window.prompt('Opening end (YYYY-MM-DD HH:MM:SS)',u.toYmdHms(end)); if(!endValue)return;
    const $button=$(this).prop('disabled',true).text('Sending…');
    try { await u.api(`/vendor/waitlist/${Number($button.data('manual-offer'))}/offer`,{method:'POST',body:JSON.stringify({start_datetime:startValue,end_datetime:endValue,timezone:Intl.DateTimeFormat().resolvedOptions().timeZone})}); await load(); }
    catch(error){ window.alert(error.message); $button.prop('disabled',false).text('Offer a time'); }
  });
  $board.on('change','[data-wait-priority]',async function(){const id=Number($(this).data('wait-priority'));try{await u.api(`/vendor/waitlist/${id}`,{method:'POST',body:JSON.stringify({priority:Number($(this).val())})});await load();}catch(error){window.alert(error.message);}});
})(jQuery);
