(function($){
  async function api(path, opts = {}) {
    const url = `${KOOPO_APPT_SETTINGS.restUrl}${path}`;
    const headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': KOOPO_APPT_SETTINGS.nonce
    }, opts.headers || {});
    const res = await fetch(url, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(()=>({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }


  function timeToMin(t){
  if (!t || !/^\d{2}:\d{2}$/.test(t)) return null;
  const [h,m] = t.split(':').map(Number);
  return h*60 + m;
}

function sortRanges(ranges){
  return ranges.slice().sort((a,b) => timeToMin(a[0]) - timeToMin(b[0]));
}

function overlaps(a, b){
  // a=[from,to] b=[from,to] in minutes
  return a[0] < b[1] && a[1] > b[0];
}

function normalizeRanges(rawRanges){
  // rawRanges: [[HH:MM,HH:MM],...]
  const normalized = [];
  const errors = [];

  rawRanges.forEach((r, idx) => {
    const f = timeToMin(r[0]);
    const t = timeToMin(r[1]);
    if (f === null || t === null) return; // ignore incomplete
    if (f >= t) {
      errors.push(`Invalid range ${r[0]}–${r[1]} (start must be before end).`);
      return;
    }
    normalized.push([f, t, r[0], r[1]]); // keep both min + original
  });

  // sort
  normalized.sort((x,y) => x[0]-y[0]);

  // output as HH:MM pairs
  const out = normalized.map(x => [x[2], x[3]]);
  return { out, mins: normalized.map(x => [x[0], x[1]]), errors };
}

function findOverlaps(minRanges){
  // minRanges: [[fromMin,toMin],...], already sorted
  const warnings = [];
  for (let i=0;i<minRanges.length;i++){
    for (let j=i+1;j<minRanges.length;j++){
      if (overlaps(minRanges[i], minRanges[j])) {
        warnings.push('Overlapping ranges detected.');
        return warnings; // one warning is enough
      }
      if (minRanges[j][0] >= minRanges[i][1]) break;
    }
  }
  return warnings;
}

function breaksOutsideHours(breaksMin, hoursMin){
  // any break that doesn't overlap ANY hours range is a warning
  const warnings = [];
  if (!breaksMin.length) return warnings;
  if (!hoursMin.length) {
    warnings.push('Breaks are set on a day with no business hours.');
    return warnings;
  }

  breaksMin.forEach(br => {
    const ok = hoursMin.some(hr => overlaps(br, hr));
    if (!ok) warnings.push('A break is outside business hours.');
  });

  return warnings.length ? ['Some breaks are outside business hours.'] : [];
}


  function mount($mount, listingId){
    $mount.html(`
      <div class="kas">
        <div class="kas__notice kas__notice--hidden"></div>
        <div class="kas__warnings kas__warnings--hidden"></div>
        <div class="kas__toolbar">
        <div class="kas__presets">
          <strong>Presets:</strong>
          <button type="button" class="kas__preset" data-preset="9_5_weekdays">9–5 Weekdays</button>
          <button type="button" class="kas__preset" data-preset="10_6_weekdays">10–6 Weekdays</button>
          <button type="button" class="kas__preset" data-preset="24_7">24/7</button>
          <button type="button" class="kas__preset" data-preset="weekends_only">Weekends Only</button>
        </div>

        <div class="kas__bulk">
          <strong>Bulk:</strong>
          <button type="button" class="kas__paste-weekdays" data-kind="hours">Paste Hours → Weekdays</button>
          <button type="button" class="kas__paste-all" data-kind="hours">Paste Hours → All</button>
          <button type="button" class="kas__paste-weekdays" data-kind="breaks">Paste Breaks → Weekdays</button>
          <button type="button" class="kas__paste-all" data-kind="breaks">Paste Breaks → All</button>
          <button type="button" class="kas__clear-all">Clear All</button>
        </div>
      </div>

        <label class="kas__row">
          <span>Enable booking</span>
          <input type="checkbox" class="kas__enabled" />
        </label>

        <label class="kas__row">
          <span>Timezone</span>
          <input type="text" class="kas__tz" placeholder="America/Detroit" />
        </label>

       <div class="kas__section">
        <h4>Business Hours</h4>
        ${['mon','tue','wed','thu','fri','sat','sun'].map(d => `
            <div class="kas__dayblock" data-dayblock="${d}">
            <div class="kas__dayhead">
                <strong>${d.toUpperCase()}</strong>
                <div class="kas__dayactions">
                  <button type="button" class="kas__copy-day" data-kind="hours" data-day="${d}">Copy</button>
                  <button type="button" class="kas__paste-day" data-kind="hours" data-day="${d}">Paste</button>
                  <button type="button" class="kas__clear-day" data-kind="hours" data-day="${d}">Clear</button>
                  <button type="button" class="kas__add-range" data-kind="hours" data-day="${d}">+ Add range</button>
                </div>
            </div>
            <div class="kas__ranges" data-kind="hours" data-day="${d}"></div>
            </div>
        `).join('')}
        </div>


       <div class="kas__section">
        <h4>Breaks</h4>
        ${['mon','tue','wed','thu','fri','sat','sun'].map(d => `
            <div class="kas__dayblock" data-dayblock-break="${d}">
            <div class="kas__dayhead">
                <strong>${d.toUpperCase()}</strong>
                <div class="kas__dayactions">
                <button type="button" class="kas__copy-day" data-kind="breaks" data-day="${d}">Copy</button>
                <button type="button" class="kas__paste-day" data-kind="breaks" data-day="${d}">Paste</button>
                <button type="button" class="kas__clear-day" data-kind="breaks" data-day="${d}">Clear</button>
                <button type="button" class="kas__add-range" data-kind="breaks" data-day="${d}">+ Add break</button>
              </div>
            </div>
            <div class="kas__ranges" data-kind="breaks" data-day="${d}"></div>
            </div>
        `).join('')}
        </div>


        <div class="kas__grid">
          <label>
            Slot interval (minutes, optional)
            <input type="number" class="kas__interval" min="0" step="1" placeholder="0 = use duration" />
          </label>
          <label>
            Buffer before (minutes)
            <input type="number" class="kas__buf_before" min="0" step="1" value="0" />
          </label>
          <label>
            Buffer after (minutes)
            <input type="number" class="kas__buf_after" min="0" step="1" value="0" />
          </label>
        </div>

        <label class="kas__row kas__row--block">
          <span>Days off (YYYY-MM-DD, comma-separated)</span>
          <textarea class="kas__days_off" rows="3" placeholder="2026-01-01, 2026-01-15"></textarea>
        </label>

        <button type="button" class="kas__save">Save Settings</button>
      </div>
    `);

    load($mount, listingId);
  }

  function showNotice($mount, msg, type){
    const $n = $mount.find('.kas__notice');
    $n.removeClass('kas__notice--hidden kas__notice--error kas__notice--success')
      .addClass(type === 'success' ? 'kas__notice--success' : 'kas__notice--error')
      .text(msg);
  }
    function rangeRow(kind, day, from = '', to = '') {
    return `
        <div class="kas__range">
        <input type="time" class="kas__rfrom" value="${from || ''}" />
        <input type="time" class="kas__rto" value="${to || ''}" />
        <button type="button" class="kas__remove-range" data-kind="${kind}" data-day="${day}">Remove</button>
        </div>
    `;
    }

    function ensureAtLeastOneRange($mount, kind, day) {
        const $wrap = $mount.find(`.kas__ranges[data-kind="${kind}"][data-day="${day}"]`);
        if ($wrap.children().length === 0) {
            // start empty by default; GeoAppointments allows empty days
            // If you want at least one row always, uncomment:
            // $wrap.append(rangeRow(kind, day, '', ''));
        }
    }

    function collectRangesFromUI($mount, kind, day){
  const ranges = [];
  $mount.find(`.kas__ranges[data-kind="${kind}"][data-day="${day}"] .kas__range`).each(function(){
    const from = $(this).find('.kas__rfrom').val();
    const to = $(this).find('.kas__rto').val();
    if (from && to) ranges.push([from, to]);
  });
  return ranges;
}

function renderRangesToUI($mount, kind, day, ranges){
  const $wrap = $mount.find(`.kas__ranges[data-kind="${kind}"][data-day="${day}"]`);
  $wrap.empty();
  ranges.forEach(r => $wrap.append(rangeRow(kind, day, r[0], r[1])));
}

function setWarnings($mount, warnings){
  const $w = $mount.find('.kas__warnings');
  if (!warnings.length) {
    $w.addClass('kas__warnings--hidden').html('');
    return;
  }
  $w.removeClass('kas__warnings--hidden')
    .html(`<ul>${warnings.map(x => `<li>${x}</li>`).join('')}</ul>`);
}
//visual conflict highlights
function clearRowStates($mount){
  $mount.find('.kas__range').removeClass('is-invalid is-overlap is-outside');
}

function markOverlaps($wrap){
  // $wrap is .kas__ranges for one day/kind
  const rows = [];
  $wrap.find('.kas__range').each(function(){
    const from = $(this).find('.kas__rfrom').val();
    const to = $(this).find('.kas__rto').val();
    const f = timeToMin(from);
    const t = timeToMin(to);
    if (f === null || t === null) return;
    rows.push({ el: $(this), f, t });
  });

  rows.sort((a,b)=>a.f-b.f);

  for (let i=0;i<rows.length;i++){
    for (let j=i+1;j<rows.length;j++){
      if (rows[j].f >= rows[i].t) break;
      if (overlaps([rows[i].f, rows[i].t], [rows[j].f, rows[j].t])) {
        rows[i].el.addClass('is-overlap');
        rows[j].el.addClass('is-overlap');
      }
    }
  }
}

function markBreaksOutsideHours($mount, day){
  const $breakWrap = $mount.find(`.kas__ranges[data-kind="breaks"][data-day="${day}"]`);
  const $hoursWrap = $mount.find(`.kas__ranges[data-kind="hours"][data-day="${day}"]`);

  const hoursMin = [];
  $hoursWrap.find('.kas__range').each(function(){
    const f = timeToMin($(this).find('.kas__rfrom').val());
    const t = timeToMin($(this).find('.kas__rto').val());
    if (f !== null && t !== null && f < t) hoursMin.push([f,t]);
  });

  $breakWrap.find('.kas__range').each(function(){
    const f = timeToMin($(this).find('.kas__rfrom').val());
    const t = timeToMin($(this).find('.kas__rto').val());
    if (f === null || t === null || f >= t) return;

    const ok = hoursMin.some(hr => overlaps([f,t], hr));
    if (!ok) $(this).addClass('is-outside');
  });
}

function validateAll($mount){
  const days = ['mon','tue','wed','thu','fri','sat','sun'];
  const warnings = [];
  const errors = [];

  days.forEach(day => {
    // Normalize hours
    const hoursRaw = collectRangesFromUI($mount, 'hours', day);
    const nh = normalizeRanges(hoursRaw);
    if (nh.errors.length) errors.push(...nh.errors);
    // sort + re-render if changed order
    const sortedHours = sortRanges(nh.out);
    if (JSON.stringify(sortedHours) !== JSON.stringify(hoursRaw)) {
      renderRangesToUI($mount, 'hours', day, sortedHours);
    }

    // Normalize breaks
    const breaksRaw = collectRangesFromUI($mount, 'breaks', day);
    const nb = normalizeRanges(breaksRaw);
    if (nb.errors.length) errors.push(...nb.errors);
    const sortedBreaks = sortRanges(nb.out);
    if (JSON.stringify(sortedBreaks) !== JSON.stringify(breaksRaw)) {
      renderRangesToUI($mount, 'breaks', day, sortedBreaks);
    }

    clearRowStates($mount);

['mon','tue','wed','thu','fri','sat','sun'].forEach(day => {
  markOverlaps($mount.find(`.kas__ranges[data-kind="hours"][data-day="${day}"]`));
  markOverlaps($mount.find(`.kas__ranges[data-kind="breaks"][data-day="${day}"]`));
  markBreaksOutsideHours($mount, day);
});


    // warnings
    warnings.push(...findOverlaps(nh.mins).map(x => `${day.toUpperCase()}: ${x} (hours)`));
    warnings.push(...findOverlaps(nb.mins).map(x => `${day.toUpperCase()}: ${x} (breaks)`));
    warnings.push(...breaksOutsideHours(nb.mins, nh.mins).map(x => `${day.toUpperCase()}: ${x}`));
  });

  setWarnings($mount, warnings);

  return { ok: errors.length === 0, errors, warnings };
}


  async function load($mount, listingId){
    try {
      const data = await api(`/appointments/settings/${listingId}`, { method:'GET' });

      $mount.find('.kas__enabled').prop('checked', !!data.enabled);
      $mount.find('.kas__tz').val(data.timezone || KOOPO_APPT_SETTINGS.tzDefault);

      // Hours (multi-range)
        const hours = data.hours || {};
        ['mon','tue','wed','thu','fri','sat','sun'].forEach(day => {
        const $ranges = $mount.find(`.kas__ranges[data-kind="hours"][data-day="${day}"]`);
        $ranges.empty();
        (hours[day] || []).forEach(r => {
            $ranges.append(rangeRow('hours', day, r[0], r[1]));
        });
        ensureAtLeastOneRange($mount, 'hours', day);
        });

        // Breaks (multi-range)
        const breaks = data.breaks || {};
        ['mon','tue','wed','thu','fri','sat','sun'].forEach(day => {
        const $ranges = $mount.find(`.kas__ranges[data-kind="breaks"][data-day="${day}"]`);
        $ranges.empty();
        (breaks[day] || []).forEach(r => {
            $ranges.append(rangeRow('breaks', day, r[0], r[1]));
        });
        ensureAtLeastOneRange($mount, 'breaks', day);
        });


      $mount.find('.kas__interval').val(data.slot_interval || 0);
      $mount.find('.kas__buf_before').val(data.buffer_before || 0);
      $mount.find('.kas__buf_after').val(data.buffer_after || 0);
      $mount.find('.kas__days_off').val((data.days_off || []).join(', '));

      showNotice($mount, 'Loaded.', 'success');
      setTimeout(()=> $mount.find('.kas__notice').addClass('kas__notice--hidden'), 800);

    } catch(e) {
      showNotice($mount, e.message || 'Failed to load settings.', 'error');
    }
  }

  async function save($mount, listingId){
    try {
        const enabled = $mount.find('.kas__enabled').is(':checked');
        const timezone = $mount.find('.kas__tz').val().trim() || KOOPO_APPT_SETTINGS.tzDefault;

        const days = ['mon','tue','wed','thu','fri','sat','sun'];

        const hours = {};
        days.forEach(day => {
        const $rows = $mount.find(`.kas__ranges[data-kind="hours"][data-day="${day}"] .kas__range`);
        hours[day] = [];
        $rows.each(function(){
            const from = $(this).find('.kas__rfrom').val();
            const to   = $(this).find('.kas__rto').val();
            if (from && to) hours[day].push([from, to]);
        });
        });

        const breaks = {};
        days.forEach(day => {
        const $rows = $mount.find(`.kas__ranges[data-kind="breaks"][data-day="${day}"] .kas__range`);
        breaks[day] = [];
        $rows.each(function(){
            const from = $(this).find('.kas__rfrom').val();
            const to   = $(this).find('.kas__rto').val();
            if (from && to) breaks[day].push([from, to]);
        });
        });


      const slot_interval = parseInt($mount.find('.kas__interval').val(), 10) || 0;
      const buffer_before = parseInt($mount.find('.kas__buf_before').val(), 10) || 0;
      const buffer_after  = parseInt($mount.find('.kas__buf_after').val(), 10) || 0;

      const days_off = ($mount.find('.kas__days_off').val() || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean);

      await api(`/appointments/settings/${listingId}`, {
        method:'POST',
        body: JSON.stringify({
          enabled,
          timezone,
          hours,
          breaks,
          slot_interval,
          buffer_before,
          buffer_after,
          days_off
        })
      });

      showNotice($mount, 'Saved successfully.', 'success');
    } catch(e) {
      showNotice($mount, e.message || 'Save failed.', 'error');
    }
  }
    // Add range/break row
    $(document).on('click', '.kas__add-range', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    const kind = $(this).data('kind');
    const day = $(this).data('day');
    const $wrap = $mount.find(`.kas__ranges[data-kind="${kind}"][data-day="${day}"]`);
    $wrap.append(rangeRow(kind, day, '', ''));
    });

    // Remove range/break row
    $(document).on('click', '.kas__remove-range', function(){
    $(this).closest('.kas__range').remove();
    });

  // Dokan page: when vendor selects a listing, mount settings UI
  $(document).on('change', '.koopo-appt-settings__listing', function(){
    const listingId = parseInt($(this).val(), 10);
    const $mount = $('.koopo-appt-settings-mount[data-mode="dokan"]');
    if (!listingId) { $mount.html(''); return; }
    mount($mount, listingId);
  });

  // Listing modal: mount using embedded listing id
  function mountListingModal($container){
    const listingId = parseInt($container.closest('.koopo-appt-settings-inline').data('listing-id'), 10);
    const $mount = $container.find('.koopo-appt-settings-mount[data-mode="listing"]');
    mount($mount, listingId);
  }

  // Modal open/close
  $(document).on('click', '.koopo-appt-settings__open', function(){
    const $wrap = $(this).closest('.koopo-appt-settings-inline');
    $wrap.find('.koopo-appt-settings__overlay').attr('aria-hidden','false').addClass('is-open');
    $('body').addClass('koopo-appt--modal-open');
    mountListingModal($wrap);
  });

  $(document).on('click', '.koopo-appt-settings__close', function(){
    const $wrap = $(this).closest('.koopo-appt-settings-inline');
    $wrap.find('.koopo-appt-settings__overlay').attr('aria-hidden','true').removeClass('is-open');
    $('body').removeClass('koopo-appt--modal-open');
  });

  $(document).on('click', '.koopo-appt-settings__overlay', function(e){
    if ($(e.target).hasClass('koopo-appt-settings__overlay')) {
      $(this).attr('aria-hidden','true').removeClass('is-open');
      $('body').removeClass('koopo-appt--modal-open');
    }
  });

  $(document).on('change input', '.kas__rfrom, .kas__rto', function(){
  const $row = $(this).closest('.kas__range');
  const f = timeToMin($row.find('.kas__rfrom').val());
  const t = timeToMin($row.find('.kas__rto').val());
  $row.toggleClass('is-invalid', f !== null && t !== null && f >= t);

  const $mount = $(this).closest('.koopo-appt-settings-mount');
  validateAll($mount);
});


let KAS_CLIPBOARD = {
  hours: null,
  breaks: null
};

function getDayRanges($mount, kind, day){
  return collectRangesFromUI($mount, kind, day);
}

function setDayRanges($mount, kind, day, ranges){
  renderRangesToUI($mount, kind, day, sortRanges(ranges));
}

$(document).on('click', '.kas__copy-day', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const day = $(this).data('day');
  const kind = $(this).data('kind');

  KAS_CLIPBOARD[kind] = getDayRanges($mount, kind, day);
  showNotice($mount, `${day.toUpperCase()} ${kind} copied.`, 'success');
});

$(document).on('click', '.kas__paste-day', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const day = $(this).data('day');
  const kind = $(this).data('kind');

  if (!KAS_CLIPBOARD[kind]) {
    showNotice($mount, `Nothing to paste for ${kind}. Copy a day first.`, 'error');
    return;
  }

  setDayRanges($mount, kind, day, KAS_CLIPBOARD[kind]);
  validateAll($mount);
  showNotice($mount, `Pasted ${kind} into ${day.toUpperCase()}.`, 'success');
});

const WEEKDAYS = ['mon','tue','wed','thu','fri'];
const ALLDAYS = ['mon','tue','wed','thu','fri','sat','sun'];

$(document).on('click', '.kas__paste-weekdays', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const kind = $(this).data('kind');

  if (!KAS_CLIPBOARD[kind]) {
    showNotice($mount, `Nothing to paste for ${kind}.`, 'error');
    return;
  }

  WEEKDAYS.forEach(d => setDayRanges($mount, kind, d, KAS_CLIPBOARD[kind]));
  validateAll($mount);
  showNotice($mount, `Pasted ${kind} into weekdays.`, 'success');
});

$(document).on('click', '.kas__paste-all', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const kind = $(this).data('kind');

  if (!KAS_CLIPBOARD[kind]) {
    showNotice($mount, `Nothing to paste for ${kind}.`, 'error');
    return;
  }

  ALLDAYS.forEach(d => setDayRanges($mount, kind, d, KAS_CLIPBOARD[kind]));
  validateAll($mount);
  showNotice($mount, `Pasted ${kind} into all days.`, 'success');
});

$(document).on('click', '.kas__clear-day', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const kind = $(this).data('kind');
  const day = $(this).data('day');

  renderRangesToUI($mount, kind, day, []);
  validateAll($mount);
  showNotice($mount, `Cleared ${kind} for ${day.toUpperCase()}.`, 'success');
});

$(document).on('click', '.kas__clear-all', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');

  ['hours','breaks'].forEach(kind => {
    ALLDAYS.forEach(day => renderRangesToUI($mount, kind, day, []));
  });

  validateAll($mount);
  showNotice($mount, 'Cleared all hours and breaks.', 'success');
});

const PRESETS = {
  '9_5_weekdays': {
    hours: {
      mon:[['09:00','17:00']], tue:[['09:00','17:00']], wed:[['09:00','17:00']], thu:[['09:00','17:00']], fri:[['09:00','17:00']],
      sat:[], sun:[]
    },
    breaks: {}
  },
  '10_6_weekdays': {
    hours: {
      mon:[['10:00','18:00']], tue:[['10:00','18:00']], wed:[['10:00','18:00']], thu:[['10:00','18:00']], fri:[['10:00','18:00']],
      sat:[], sun:[]
    },
    breaks: {}
  },
  '24_7': {
    hours: {
      mon:[['00:00','23:59']], tue:[['00:00','23:59']], wed:[['00:00','23:59']], thu:[['00:00','23:59']], fri:[['00:00','23:59']],
      sat:[['00:00','23:59']], sun:[['00:00','23:59']]
    },
    breaks: {}
  },
  'weekends_only': {
    hours: {
      mon:[], tue:[], wed:[], thu:[], fri:[],
      sat:[['10:00','16:00']], sun:[['10:00','16:00']]
    },
    breaks: {}
  }
};

$(document).on('click', '.kas__preset', function(){
  const $mount = $(this).closest('.koopo-appt-settings-mount');
  const presetKey = $(this).data('preset');
  const preset = PRESETS[presetKey];
  if (!preset) return;

  // Apply hours
  ALLDAYS.forEach(day => {
    setDayRanges($mount, 'hours', day, preset.hours[day] || []);
  });

  // Optionally apply breaks if present; otherwise leave as-is
  if (preset.breaks && Object.keys(preset.breaks).length) {
    ALLDAYS.forEach(day => {
      setDayRanges($mount, 'breaks', day, preset.breaks[day] || []);
    });
  }

  validateAll($mount);
  showNotice($mount, 'Preset applied. Don’t forget to Save.', 'success');
});

  // Save button (works in both mounts)
  $(document).on('click', '.kas__save', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    // listing id is stored based on either dokan select or listing wrapper
    let listingId = parseInt($('.koopo-appt-settings__listing').val(), 10);

    if (!listingId) {
      const $wrap = $(this).closest('.koopo-appt-settings-inline');
      listingId = parseInt($wrap.data('listing-id'), 10);
    }

    if (!listingId) return;

  const result = validateAll($mount);
  if (!result.ok) {
    showNotice($mount, result.errors[0] || 'Please fix invalid ranges.', 'error');
    return;
  }
    save($mount, listingId);
  });

})(jQuery);
