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
        <div class="kas__loading kas__loading--hidden"><span class="kas__spinner"></span></div>
        <div class="kas__warnings kas__warnings--hidden"></div>
        <label class="kas__row kas__row--toggle kas__row--primary">
          <span>Enable booking</span>
          <button type="button" class="kas__toggle-btn kas__enabled_toggle" aria-pressed="false">
            <span class="kas__toggle-knob"></span>
            <span class="kas__toggle-label">Off</span>
          </button>
        </label>
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

        <div class="kas__section">
          <h4>Rescheduling</h4>
          <label class="kas__row kas__row--toggle">
            <span>Allow customers to reschedule their appointments</span>
            <button type="button" class="kas__toggle-btn kas__reschedule_enabled" aria-pressed="true">
              <span class="kas__toggle-knob"></span>
              <span class="kas__toggle-label">On</span>
            </button>
          </label>
          <div class="kas__hint">If enabled, customers can reschedule in their booking details.</div>

          <label class="kas__row kas__row--toggle kas__reschedule-restrict-toggle">
            <span>Set restriction on when customers can reschedule</span>
            <button type="button" class="kas__toggle-btn kas__reschedule_restrict_enabled" aria-pressed="false">
              <span class="kas__toggle-knob"></span>
              <span class="kas__toggle-label">Off</span>
            </button>
          </label>

          <div class="kas__reschedule-restrict">
            <div class="kas__reschedule-line">
              <span>Customer can reschedule when it is at least</span>
              <input type="number" class="kas__reschedule_value" min="1" step="1" value="1" />
              <select class="kas__reschedule_unit">
                <option value="minutes">Minutes</option>
                <option value="hours">Hours</option>
                <option value="days" selected>Days</option>
              </select>
              <span>before appointment start time</span>
            </div>
          </div>
        </div>

        <div class="kas__section">
          <h4>Cancellation/Refund Policy</h4>
          <div class="kas__hint">Set refund percentage based on how far in advance a customer cancels.</div>
          <div class="kas__hint">If you don’t set a policy, the site’s standard default policy will be used.</div>
          <div class="kas__refund-table">
            <div class="kas__refund-rows"></div>
            <button type="button" class="kas__refund-add">+ Add rule</button>
          </div>
        </div>

        <div class="kas__section">
          <h4>Vacation/Holiday Dates</h4>
          <div class="kas__hint">Add full-day closures or specific time ranges.</div>
          <div class="kas__vacation-form">
            <input type="text" class="kas__vacation-date" placeholder="MM/DD/YYYY" />
            <label class="kas__vacation-allday">
              <input type="checkbox" class="kas__vacation-all-day" />
              All day
            </label>
            <input type="time" class="kas__vacation-start" />
            <input type="time" class="kas__vacation-end" />
            <button type="button" class="kas__vacation-add">Add</button>
          </div>
          <div class="kas__vacation-list"></div>
        </div>

        <button type="button" class="kas__save">Save Settings</button>
        <div class="kas__save-msg kas__save-msg--hidden"></div>
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

  function setLoading($mount, isLoading){
    const $loader = $mount.find('.kas__loading');
    $loader.toggleClass('kas__loading--hidden', !isLoading);
  }

  function setSaveMessage($mount, msg, type){
    const $m = $mount.find('.kas__save-msg');
    if (!$m.length) return;
    if (!msg) {
      $m.addClass('kas__save-msg--hidden').removeClass('kas__save-msg--success kas__save-msg--error').text('');
      return;
    }
    $m.removeClass('kas__save-msg--hidden kas__save-msg--success kas__save-msg--error')
      .addClass(type === 'success' ? 'kas__save-msg--success' : 'kas__save-msg--error')
      .text(msg);
    if (type === 'success') {
      setTimeout(() => {
        $m.addClass('kas__save-msg--hidden');
      }, 2000);
    }
  }

  function normalizeVacationItems(items){
    if (!Array.isArray(items)) return [];
    return items.map(item => {
      if (typeof item === 'string') {
        return { date: item, start: '', end: '' };
      }
      if (!item || typeof item !== 'object') return null;
      return {
        date: item.date || '',
        start: item.start || '',
        end: item.end || ''
      };
    }).filter(v => v && v.date);
  }

  function formatDateMmddyyyy(isoDate){
    const parts = String(isoDate || '').split('-');
    if (parts.length !== 3) return isoDate || '';
    const [yyyy, mm, dd] = parts;
    if (!yyyy || !mm || !dd) return isoDate || '';
    return `${mm}/${dd}/${yyyy}`;
  }

  function parseDateMmddyyyy(input){
    const value = String(input || '').trim();
    const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!match) return '';
    const mm = match[1];
    const dd = match[2];
    const yyyy = match[3];
    const iso = `${yyyy}-${mm}-${dd}`;
    const date = new Date(`${iso}T00:00:00`);
    if (isNaN(date.getTime())) return '';
    return iso;
  }

  function getVacationItems($mount){
    const items = $mount.data('kasVacations');
    return Array.isArray(items) ? items : [];
  }

  function renderVacationList($mount, items){
    const list = normalizeVacationItems(items);
    $mount.data('kasVacations', list);
    const $list = $mount.find('.kas__vacation-list');
    if (!$list.length) return;
    if (!list.length) {
      $list.html('<div class="kas__vacation-empty">No vacation/holiday dates added.</div>');
      return;
    }
    const rows = list.map((v, idx) => {
      const labelDate = formatDateMmddyyyy(v.date);
      const label = v.start && v.end ? `${labelDate} ${v.start}–${v.end}` : `${labelDate} (All day)`;
      return `
        <div class="kas__vacation-row" data-idx="${idx}">
          <span>${label}</span>
          <button type="button" class="kas__vacation-remove" data-idx="${idx}">Remove</button>
        </div>
      `;
    }).join('');
    $list.html(rows);
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

  function setToggleState($btn, on){
    $btn.toggleClass('is-on', !!on);
    $btn.attr('aria-pressed', on ? 'true' : 'false');
    $btn.find('.kas__toggle-label').text(on ? 'On' : 'Off');
  }

  function isToggleOn($btn){
    return $btn.hasClass('is-on');
  }

  function updateRescheduleVisibility($mount){
    const enabled = isToggleOn($mount.find('.kas__reschedule_enabled'));
    const restrictEnabled = isToggleOn($mount.find('.kas__reschedule_restrict_enabled'));
    $mount.find('.kas__reschedule-restrict-toggle').toggle(enabled);
    $mount.find('.kas__reschedule_restrict_enabled').prop('disabled', !enabled);
    if (!enabled) {
      setToggleState($mount.find('.kas__reschedule_restrict_enabled'), false);
    }
    $mount.find('.kas__reschedule-restrict').toggle(enabled && restrictEnabled);
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

  function refundRuleRow(value = 1, unit = 'days', refund = 100, otherwiseRefund = 0){
    return `
      <div class="kas__refund-row">
        <div class="kas__refund-line">
          <span>If canceled at least</span>
          <input type="number" class="kas__refund-value" min="1" step="1" value="${value}" />
          <select class="kas__refund-unit">
            <option value="hours" ${unit === 'hours' ? 'selected' : ''}>hours</option>
            <option value="days" ${unit === 'days' ? 'selected' : ''}>days</option>
          </select>
          <span>before the start time of your appointment then you will be refunded</span>
          <input type="number" class="kas__refund-percent" min="0" max="100" step="1" value="${refund}" />
          <span>%</span>
          <span>otherwise the refund will be</span>
          <input type="number" class="kas__refund-otherwise" min="0" max="100" step="1" value="${otherwiseRefund}" />
          <span>%</span>
        </div>
        <button type="button" class="kas__refund-remove">Remove</button>
      </div>
    `;
  }

  function renderRefundRules($mount, rules){
    const $rows = $mount.find('.kas__refund-rows');
    $rows.empty();
    if (!rules.length) {
      $rows.append(refundRuleRow(1, 'days', 100, 0));
      return;
    }
    const sorted = rules.slice().sort((a, b) => Number(b.hours_before || 0) - Number(a.hours_before || 0));
    const rows = [];
    sorted.forEach((rule, idx) => {
      const hours = Number(rule.hours_before || 0);
      if (hours <= 0) return;
      const fee = Number(rule.fee_percent || 0);
      const refund = Math.max(0, Math.min(100, 100 - fee));
      let unit = 'hours';
      let value = hours;
      if (hours % 24 === 0) {
        unit = 'days';
        value = hours / 24;
      }
      const next = sorted[idx + 1];
      let otherwiseRefund = 0;
      if (next) {
        const nextFee = Number(next.fee_percent || 0);
        otherwiseRefund = Math.max(0, Math.min(100, 100 - nextFee));
      }
      rows.push({ value, unit, refund, otherwiseRefund });
    });
    if (!rows.length) {
      $rows.append(refundRuleRow(1, 'days', 100, 0));
      return;
    }
    rows.forEach(r => $rows.append(refundRuleRow(r.value, r.unit, r.refund, r.otherwiseRefund)));
  }

  function collectRefundRules($mount){
    const rows = [];
    $mount.find('.kas__refund-row').each(function(){
      const value = parseInt($(this).find('.kas__refund-value').val(), 10);
      const unit = $(this).find('.kas__refund-unit').val() || 'days';
      const refundPercent = parseInt($(this).find('.kas__refund-percent').val(), 10);
      const otherwisePercent = parseInt($(this).find('.kas__refund-otherwise').val(), 10);
      if (Number.isNaN(value) || Number.isNaN(refundPercent) || Number.isNaN(otherwisePercent)) return;
      const hours = unit === 'days' ? value * 24 : value;
      rows.push({
        hours_before: Math.max(1, hours),
        refund_percent: Math.max(0, Math.min(100, refundPercent)),
        otherwise_refund_percent: Math.max(0, Math.min(100, otherwisePercent)),
      });
    });
    if (!rows.length) return [];

    rows.sort((a,b) => b.hours_before - a.hours_before);
    const rules = rows.map(row => ({
      hours_before: row.hours_before,
      fee_percent: Math.max(0, Math.min(100, 100 - row.refund_percent)),
    }));

    const last = rows[rows.length - 1];
    if (last && last.otherwise_refund_percent !== null && last.otherwise_refund_percent !== undefined) {
      rules.push({
        hours_before: 0,
        fee_percent: Math.max(0, Math.min(100, 100 - last.otherwise_refund_percent)),
      });
    }
    rules.sort((a,b) => b.hours_before - a.hours_before);
    return rules;
  }


  async function load($mount, listingId){
    try {
      setLoading($mount, true);
      const data = await api(`/appointments/settings/${listingId}`, { method:'GET' });

      setToggleState($mount.find('.kas__enabled_toggle'), !!data.enabled);
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
      const rescheduleEnabled = data.reschedule_enabled !== false;
      const restrictEnabled = !!data.reschedule_restrict_enabled;
      const cutoffValue = data.reschedule_cutoff_value ? Number(data.reschedule_cutoff_value) : 0;
      const cutoffUnit = data.reschedule_cutoff_unit || 'days';
      setToggleState($mount.find('.kas__reschedule_enabled'), rescheduleEnabled);
      setToggleState($mount.find('.kas__reschedule_restrict_enabled'), restrictEnabled);
      $mount.find('.kas__reschedule_value').val(cutoffValue > 0 ? cutoffValue : 1);
      $mount.find('.kas__reschedule_unit').val(cutoffUnit || 'days');
      updateRescheduleVisibility($mount);
      renderRefundRules($mount, data.refund_policy_rules || []);
      renderVacationList($mount, data.days_off || []);

      setLoading($mount, false);

    } catch(e) {
      setLoading($mount, false);
      showNotice($mount, e.message || 'Failed to load settings.', 'error');
    }
  }

  async function save($mount, listingId){
    try {
        setLoading($mount, true);
        const enabled = isToggleOn($mount.find('.kas__enabled_toggle'));
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
      const reschedule_enabled = isToggleOn($mount.find('.kas__reschedule_enabled'));
      const reschedule_restrict_enabled = isToggleOn($mount.find('.kas__reschedule_restrict_enabled'));
      const reschedule_cutoff_value = reschedule_enabled && reschedule_restrict_enabled
        ? (parseInt($mount.find('.kas__reschedule_value').val(), 10) || 1)
        : 0;
      const reschedule_cutoff_unit = reschedule_enabled && reschedule_restrict_enabled
        ? ($mount.find('.kas__reschedule_unit').val() || 'days')
        : 'days';
      const refund_policy_rules = collectRefundRules($mount);

      const days_off = getVacationItems($mount);

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
          reschedule_enabled,
          reschedule_restrict_enabled,
          reschedule_cutoff_value,
          reschedule_cutoff_unit,
          refund_policy_rules,
          days_off
        })
      });

      setLoading($mount, false);
      showNotice($mount, 'Saved successfully.', 'success');
      setSaveMessage($mount, 'Saved successfully.', 'success');
    } catch(e) {
      setLoading($mount, false);
      showNotice($mount, e.message || 'Save failed.', 'error');
      setSaveMessage($mount, e.message || 'Save failed.', 'error');
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

  // Vacation list add/remove
  $(document).on('change', '.kas__vacation-all-day', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    const isAllDay = $(this).is(':checked');
    const $start = $mount.find('.kas__vacation-start');
    const $end = $mount.find('.kas__vacation-end');
    $start.prop('disabled', isAllDay);
    $end.prop('disabled', isAllDay);
    if (isAllDay) {
      $start.val('');
      $end.val('');
    }
  });

  $(document).on('click', '.kas__vacation-add', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    const rawDate = String($mount.find('.kas__vacation-date').val() || '').trim();
    const date = parseDateMmddyyyy(rawDate);
    const start = String($mount.find('.kas__vacation-start').val() || '').trim();
    const end = String($mount.find('.kas__vacation-end').val() || '').trim();
    const isAllDay = $mount.find('.kas__vacation-all-day').is(':checked');
    if (!date) {
      showNotice($mount, 'Enter a date in MM/DD/YYYY format.', 'error');
      return;
    }
    if (!isAllDay && ((start && !end) || (!start && end))) {
      showNotice($mount, 'Provide both start and end time, or leave both blank for all-day.', 'error');
      return;
    }
    if (!isAllDay && start && end && start >= end) {
      showNotice($mount, 'End time must be after start time.', 'error');
      return;
    }
    const list = getVacationItems($mount);
    const normalizedStart = isAllDay ? '' : start;
    const normalizedEnd = isAllDay ? '' : end;
    const hasAllDay = list.some(v => v.date === date && !v.start && !v.end);
    if (hasAllDay) {
      showNotice($mount, 'An all-day closure already exists for this date.', 'error');
      return;
    }
    if (isAllDay && list.some(v => v.date === date)) {
      showNotice($mount, 'Remove existing time ranges before adding an all-day closure.', 'error');
      return;
    }
    const duplicate = list.some(v => v.date === date && (v.start || '') === normalizedStart && (v.end || '') === normalizedEnd);
    if (duplicate) {
      showNotice($mount, 'This date/time range already exists.', 'error');
      return;
    }
    list.push({ date, start: normalizedStart, end: normalizedEnd });
    renderVacationList($mount, list);
    $mount.find('.kas__vacation-date').val('');
    $mount.find('.kas__vacation-start').val('');
    $mount.find('.kas__vacation-end').val('');
    $mount.find('.kas__vacation-all-day').prop('checked', false);
  });

  $(document).on('click', '.kas__vacation-remove', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    const idx = parseInt($(this).data('idx'), 10);
    const list = getVacationItems($mount).filter((_, i) => i !== idx);
    renderVacationList($mount, list);
  });

  // Load vendor listings into dropdown on Dokan dashboard
  async function loadVendorListings($select) {
    try {
      $select.empty().append(`<option value="">Select listing…</option>`);
      const res = await fetch(`${KOOPO_APPT_SETTINGS.restUrl}/vendor/listings`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': KOOPO_APPT_SETTINGS.nonce
        }
      });
      const listings = await res.json();
      if (!res.ok) throw new Error('Failed to load listings');

      listings.forEach(l => {
        const title = String(l.title || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        $select.append(`<option value="${l.id}">${title}</option>`);
      });
    } catch(e) {
      console.error('Failed to load vendor listings:', e);
    }
  }

  // Initialize listing dropdown on page load if on Dokan dashboard
  $(document).ready(function(){
    const $dokanSelect = $('.koopo-appt-settings__listing');
    if ($dokanSelect.length) {
      loadVendorListings($dokanSelect).then(listings => {
        if (Array.isArray(listings) && listings.length) {
          $dokanSelect.prop('selectedIndex', 1).trigger('change');
        }
      });
    }
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

  $(document).on('click', '.kas__refund-add', function(){
    const $mount = $(this).closest('.koopo-appt-settings-mount');
    $mount.find('.kas__refund-rows').append(refundRuleRow(1, 'days', 100, 0));
  });

  $(document).on('click', '.kas__refund-remove', function(){
    $(this).closest('.kas__refund-row').remove();
  });

  $(document).on('click', '.kas__toggle-btn', function(){
    const $btn = $(this);
    if ($btn.prop('disabled')) return;
    setToggleState($btn, !isToggleOn($btn));
    const $mount = $btn.closest('.koopo-appt-settings-mount');
    updateRescheduleVisibility($mount);
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
