// Commit 21: Enhanced Reschedule Calendar UI
// Append to assets/vendor.js

(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;

  // Calendar state
  let rescheduleState = {
    bookingId: null,
    serviceId: null,
    duration: 0,
    currentMonth: new Date(),
    selectedDate: null,
    selectedSlot: null,
    currentStart: '',
    currentEnd: '',
    timezone: '',
  };

  /**
   * Show reschedule modal with calendar UI
   */
  async function showRescheduleModal(bookingId) {
    try {
      // Get booking details
      const bookings = await api(`/vendor/bookings?page=1&per_page=200`, { method: 'GET' });
      const booking = bookings.items.find(b => b.id === bookingId);
      
      if (!booking) {
        alert('Booking not found');
        return;
      }

      // Initialize state
      rescheduleState.bookingId = bookingId;
      rescheduleState.serviceId = booking.service_id;
      rescheduleState.currentStart = booking.start_datetime;
      rescheduleState.currentEnd = booking.end_datetime;
      rescheduleState.timezone = booking.timezone || '';
      
      // Calculate duration
      const startTs = new Date(booking.start_datetime).getTime();
      const endTs = new Date(booking.end_datetime).getTime();
      rescheduleState.duration = Math.round((endTs - startTs) / 60000); // minutes

      // Build modal HTML
      const modalHtml = `
        <div class="koopo-reschedule-modal">
          <div class="koopo-modal__card">
            <button class="koopo-modal__close koopo-reschedule-modal__close" type="button">&times;</button>
            <h3>Reschedule Appointment</h3>
            
            <div class="koopo-reschedule-current">
              <strong>Current Time</strong>
              <div>${booking.start_datetime_formatted || booking.start_datetime} (${rescheduleState.duration} min)</div>
            </div>
            
            <div class="koopo-reschedule-step" data-step="1">
              <h4>Step 1: Select New Date</h4>
              <div id="reschedule-calendar"></div>
            </div>
            
            <div class="koopo-reschedule-step" data-step="2" style="display:none;">
              <h4>Step 2: Select New Time</h4>
              <div class="koopo-reschedule-slots"></div>
            </div>
            
            <div class="koopo-reschedule-summary" style="display:none;">
              <strong>New Time</strong>
              <div class="koopo-reschedule-summary__text"></div>
            </div>
            
            <div class="koopo-modal__footer">
              <button type="button" class="koopo-btn koopo-reschedule-back" style="display:none;">‹ Back</button>
              <button type="button" class="koopo-btn koopo-reschedule-cancel">Cancel</button>
              <button type="button" class="koopo-btn koopo-btn--gold koopo-reschedule-submit" disabled>Reschedule</button>
            </div>
          </div>
        </div>
      `;

      // Remove existing modal
      $('.koopo-reschedule-modal').remove();

      // Add to DOM
      $('body').append(modalHtml);

      // Initialize calendar
      renderCalendar();

      // Show modal
      $('.koopo-reschedule-modal').fadeIn(200);

    } catch (e) {
      alert('Failed to load reschedule UI: ' + (e.message || 'Unknown error'));
    }
  }

  /**
   * Render calendar for current month
   */
  function renderCalendar() {
    const $calendar = $('#reschedule-calendar');
    const month = rescheduleState.currentMonth;
    const year = month.getFullYear();
    const monthIndex = month.getMonth();
    
    // Month names
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
    
    // Build calendar HTML
    let html = `
      <div class="koopo-calendar">
        <div class="koopo-calendar__header">
          <div class="koopo-calendar__month">${monthNames[monthIndex]} ${year}</div>
          <div class="koopo-calendar__nav">
            <button type="button" class="koopo-calendar__prev">‹</button>
            <button type="button" class="koopo-calendar__next">›</button>
          </div>
        </div>
        <div class="koopo-calendar__grid">
          <div class="koopo-calendar__day-name">Sun</div>
          <div class="koopo-calendar__day-name">Mon</div>
          <div class="koopo-calendar__day-name">Tue</div>
          <div class="koopo-calendar__day-name">Wed</div>
          <div class="koopo-calendar__day-name">Thu</div>
          <div class="koopo-calendar__day-name">Fri</div>
          <div class="koopo-calendar__day-name">Sat</div>
    `;

    // Get first day of month and number of days
    const firstDay = new Date(year, monthIndex, 1).getDay();
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const today = new Date();
    const todayStr = formatDate(today);

    // Previous month days (filler)
    const prevMonthDays = new Date(year, monthIndex, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
      const day = prevMonthDays - i;
      html += `<div class="koopo-calendar__date is-other-month">${day}</div>`;
    }

    // Current month days
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, monthIndex, day);
      const dateStr = formatDate(date);
      const isPast = date < today && dateStr !== todayStr;
      const isToday = dateStr === todayStr;
      const isSelected = dateStr === rescheduleState.selectedDate;
      
      let classes = 'koopo-calendar__date';
      if (isPast) classes += ' is-disabled';
      if (isToday) classes += ' is-today';
      if (isSelected) classes += ' is-selected';
      
      html += `<div class="${classes}" data-date="${dateStr}">${day}</div>`;
    }

    // Next month days (filler)
    const totalCells = firstDay + daysInMonth;
    const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let day = 1; day <= remainingCells; day++) {
      html += `<div class="koopo-calendar__date is-other-month">${day}</div>`;
    }

    html += `</div></div>`;
    
    $calendar.html(html);
  }

  /**
   * Load available slots for selected date
   */
  async function loadAvailableSlots(date) {
    const $slotsContainer = $('.koopo-reschedule-slots');
    $slotsContainer.html('<div class="koopo-reschedule-loading">Loading available times...</div>');

    try {
      // Fetch availability from the standard availability endpoint
      // We'll exclude the current booking from conflicts by using the service's availability
      const data = await api(`/availability/by-service/${rescheduleState.serviceId}?date=${encodeURIComponent(date)}`, {
        method: 'GET'
      });

      const slots = data.slots || [];

      if (slots.length === 0) {
        $slotsContainer.html(`
          <div class="koopo-reschedule-empty">
            <strong>No Times Available</strong>
            <p>This date is fully booked or outside business hours. Please select another date.</p>
          </div>
        `);
        return;
      }

      // Group slots by time of day
      const groups = { Morning: [], Afternoon: [], Evening: [] };
      
      slots.forEach(slot => {
        const hour = parseInt(slot.start.split(' ')[1].split(':')[0]);
        const period = hour < 12 ? 'Morning' : hour < 17 ? 'Afternoon' : 'Evening';
        groups[period].push(slot);
      });

      // Render slots
      let html = '';
      ['Morning', 'Afternoon', 'Evening'].forEach(period => {
        if (groups[period].length === 0) return;
        
        html += `
          <div class="koopo-slot-group">
            <h5>${period}</h5>
            <div class="koopo-slot-grid">
        `;
        
        groups[period].forEach(slot => {
          const isSelected = rescheduleState.selectedSlot && 
                            rescheduleState.selectedSlot.start === slot.start;
          html += `
            <button type="button" 
                    class="koopo-slot ${isSelected ? 'is-selected' : ''}" 
                    data-start="${slot.start}" 
                    data-end="${slot.end}">
              ${slot.label}
            </button>
          `;
        });
        
        html += `</div></div>`;
      });

      $slotsContainer.html(html);

    } catch (e) {
      $slotsContainer.html(`
        <div class="koopo-reschedule-empty">
          <strong>Error Loading Times</strong>
          <p>${e.message || 'Failed to load available times. Please try again.'}</p>
        </div>
      `);
    }
  }

  /**
   * Process reschedule submission
   */
  async function submitReschedule() {
    if (!rescheduleState.selectedSlot) {
      alert('Please select a new date and time');
      return;
    }

    const $btn = $('.koopo-reschedule-submit');
    $btn.prop('disabled', true).text('Processing...');

    try {
      const result = await api(`/vendor/bookings/${rescheduleState.bookingId}/action`, {
        method: 'POST',
        body: JSON.stringify({
          action: 'reschedule',
          start_datetime: rescheduleState.selectedSlot.start,
          end_datetime: rescheduleState.selectedSlot.end,
          timezone: rescheduleState.timezone || '',
        })
      });

      $('.koopo-reschedule-modal').fadeOut(200, function() {
        $(this).remove();
      });

      // Reload bookings table
      if (typeof loadAppointments === 'function') {
        await loadAppointments();
      }

      alert('✓ Appointment rescheduled successfully!\n\nThe customer will receive a notification about the new time.');

    } catch (err) {
      const msg = err.message || 'Unknown error';
      if (msg.includes('conflict')) {
        alert('⚠️ Cannot reschedule: The selected time conflicts with another booking.\n\nPlease choose a different time.');
      } else {
        alert('⚠️ Reschedule failed: ' + msg);
      }
      $btn.prop('disabled', false).text('Reschedule');
    }
  }

  /**
   * Format date as YYYY-MM-DD
   */
  function formatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  /**
   * Format datetime for display
   */
  function formatDateTime(datetime) {
    const date = new Date(datetime);
    const options = { 
      weekday: 'long', 
      year: 'numeric', 
      month: 'long', 
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    };
    return date.toLocaleDateString('en-US', options);
  }

  // ===== Event Handlers =====

  // Close modal
  $(document).on('click', '.koopo-reschedule-modal__close, .koopo-reschedule-cancel', function() {
    $('.koopo-reschedule-modal').fadeOut(200, function() {
      $(this).remove();
    });
  });

  // Calendar navigation
  $(document).on('click', '.koopo-calendar__prev', function() {
    rescheduleState.currentMonth = new Date(
      rescheduleState.currentMonth.getFullYear(),
      rescheduleState.currentMonth.getMonth() - 1,
      1
    );
    renderCalendar();
  });

  $(document).on('click', '.koopo-calendar__next', function() {
    rescheduleState.currentMonth = new Date(
      rescheduleState.currentMonth.getFullYear(),
      rescheduleState.currentMonth.getMonth() + 1,
      1
    );
    renderCalendar();
  });

  // Date selection
  $(document).on('click', '.koopo-calendar__date:not(.is-disabled):not(.is-other-month)', async function() {
    const date = $(this).data('date');
    rescheduleState.selectedDate = date;
    rescheduleState.selectedSlot = null;
    
    // Update UI
    $('.koopo-calendar__date').removeClass('is-selected');
    $(this).addClass('is-selected');
    
    // Show step 2
    $('[data-step="1"]').hide();
    $('[data-step="2"]').show();
    $('.koopo-reschedule-back').show();
    $('.koopo-reschedule-submit').prop('disabled', true);
    $('.koopo-reschedule-summary').hide();
    
    // Load slots
    await loadAvailableSlots(date);
  });

  // Time slot selection
  $(document).on('click', '.koopo-slot:not(:disabled)', function() {
    const start = $(this).data('start');
    const end = $(this).data('end');
    
    rescheduleState.selectedSlot = { start, end };
    
    // Update UI
    $('.koopo-slot').removeClass('is-selected');
    $(this).addClass('is-selected');
    
    // Show summary
    const formatted = formatDateTime(start);
    $('.koopo-reschedule-summary__text').text(`${formatted} (${rescheduleState.duration} min)`);
    $('.koopo-reschedule-summary').show();
    
    // Enable submit
    $('.koopo-reschedule-submit').prop('disabled', false);
  });

  // Back button
  $(document).on('click', '.koopo-reschedule-back', function() {
    $('[data-step="2"]').hide();
    $('[data-step="1"]').show();
    $(this).hide();
    $('.koopo-reschedule-summary').hide();
    $('.koopo-reschedule-submit').prop('disabled', true);
  });

  // Submit reschedule
  $(document).on('click', '.koopo-reschedule-submit', async function(e) {
    e.preventDefault();
    
    if (!confirm('Reschedule this appointment to the selected time?')) {
      return;
    }
    
    await submitReschedule();
  });

  // REPLACE existing reschedule button handler
  $(document).on('click', '.koopo-appt-action[data-action="reschedule"]', async function(e) {
    e.preventDefault();
    const bookingId = parseInt($(this).data('id'), 10);
    if (!bookingId) return;

    await showRescheduleModal(bookingId);
  });

})(jQuery);
