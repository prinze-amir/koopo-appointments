// Commit 23: Customer Dashboard JavaScript
// File: assets/customer-dashboard.js

(function($) {
  'use strict';

  if (typeof KOOPO_CUSTOMER === 'undefined') return;

  let currentFilter = 'upcoming';
  let currentPage = 1;
  let currentBookingId = null;
  let rescheduleState = {
    bookingId: null,
    serviceId: null,
    timezone: '',
    currentMonth: new Date(),
    selectedDate: null,
    selectedSlot: null,
    duration: 0,
  };

  /**
   * Initialize dashboard
   */
  function init() {
    loadAppointments();
    bindEvents();
  }

  /**
   * Load appointments from API
   */
  async function loadAppointments(page = 1) {
    currentPage = page;
    const $list = $('#koopo-appointments-list');
    
    // Show loading
    $list.html(`
      <div class="koopo-appointments-loading">
        <div class="koopo-spinner"></div>
        <p>${KOOPO_CUSTOMER.i18n.loading}</p>
      </div>
    `);

    try {
      const response = await fetch(
        `${KOOPO_CUSTOMER.api_url}/customer/bookings?status=${currentFilter}&page=${page}&per_page=10`,
        {
          headers: {
            'X-WP-Nonce': KOOPO_CUSTOMER.nonce
          }
        }
      );

      if (!response.ok) {
        throw new Error('Failed to load appointments');
      }

      const data = await response.json();
      renderAppointments(data.items);
      renderPagination(data.pagination);

    } catch (error) {
      console.error('Error loading appointments:', error);
      $list.html(`
        <div class="koopo-error-state">
          <p>Failed to load appointments. Please try again.</p>
          <button type="button" class="koopo-btn koopo-btn--small" onclick="location.reload()">Retry</button>
        </div>
      `);
    }
  }

  /**
   * Render appointments list
   */
  function renderAppointments(items) {
    const $list = $('#koopo-appointments-list');
    
    if (items.length === 0) {
      renderEmptyState();
      return;
    }

    $list.empty();

    items.forEach(booking => {
      const $card = createAppointmentCard(booking);
      $list.append($card);
    });
  }

  /**
   * Create appointment card
   */
  function createAppointmentCard(booking) {
    const $template = $('#koopo-appointment-card-template');
    const $card = $($template.html()).clone();

    $card.attr('data-id', booking.id);
    $card.find('.koopo-service-title').text(booking.service_title);
    $card.find('.koopo-listing-title').text(booking.listing_title);
    $card.find('.koopo-datetime').text(booking.start_datetime_formatted);
    $card.find('.koopo-duration').text(booking.duration_formatted);
    $card.find('.koopo-price').text(
      KOOPO_CUSTOMER.currency_symbol + booking.price.toFixed(2)
    );

    // Status badge
    const statusBadge = $card.find('.koopo-status-badge');
    const statusText = booking.status.replace('_', ' ');
    statusBadge
      .text(statusText.charAt(0).toUpperCase() + statusText.slice(1))
      .addClass('koopo-status--' + booking.status);

    // Relative time (for upcoming)
    if (booking.status === 'confirmed' && currentFilter === 'upcoming') {
      $card.find('.koopo-relative-time').text(booking.relative_time);
      $card.find('.koopo-relative-time-row').show();
    }

    // Action buttons
    if (booking.can_cancel) {
      $card.find('.koopo-btn-cancel')
        .show()
        .data('booking', booking);
    }

    if (booking.can_reschedule) {
      $card.find('.koopo-btn-reschedule')
        .show()
        .data('booking', booking);
    }

    // Calendar dropdown (for future confirmed bookings)
    if (booking.status === 'confirmed' && currentFilter === 'upcoming') {
      const $dropdown = $card.find('.koopo-calendar-dropdown');
      $dropdown.show();
      $dropdown.find('.koopo-calendar-google').attr('href', booking.calendar_links.google);
      $dropdown.find('.koopo-calendar-apple').attr('href', booking.calendar_links.ical);
      $dropdown.find('.koopo-calendar-outlook').attr('href', booking.calendar_links.outlook);
    }

    // Order link
    if (booking.wc_order_id) {
      $card.find('.koopo-btn-order')
        .show()
        .attr('href', `/my-account/view-order/${booking.wc_order_id}/`);
    }

    return $card;
  }

  /**
   * Render empty state
   */
  function renderEmptyState() {
    const $list = $('#koopo-appointments-list');
    const $template = $('#koopo-empty-state-template');
    const $empty = $($template.html()).clone();

    let message = '';
    switch(currentFilter) {
      case 'upcoming':
        message = 'You don\'t have any upcoming appointments.';
        break;
      case 'past':
        message = 'You don\'t have any past appointments.';
        break;
      case 'cancelled':
        message = 'You don\'t have any cancelled appointments.';
        break;
      default:
        message = 'You don\'t have any appointments yet.';
    }

    $empty.find('.koopo-empty-state__message').text(message);
    $list.html($empty);
  }

  /**
   * Render pagination
   */
  function renderPagination(pagination) {
    const $pagination = $('#koopo-pagination');

    if (pagination.total_pages <= 1) {
      $pagination.hide();
      return;
    }

    $pagination.empty().show();

    // Previous button
    if (pagination.page > 1) {
      $pagination.append(
        $('<button>')
          .addClass('koopo-pagination-btn')
          .text('« Previous')
          .data('page', pagination.page - 1)
          .on('click', function() {
            loadAppointments($(this).data('page'));
          })
      );
    }

    // Page numbers
    const start = Math.max(1, pagination.page - 2);
    const end = Math.min(pagination.total_pages, pagination.page + 2);

    for (let i = start; i <= end; i++) {
      const $btn = $('<button>')
        .addClass('koopo-pagination-btn')
        .text(i)
        .data('page', i);

      if (i === pagination.page) {
        $btn.addClass('koopo-pagination-btn--active');
      } else {
        $btn.on('click', function() {
          loadAppointments($(this).data('page'));
        });
      }

      $pagination.append($btn);
    }

    // Next button
    if (pagination.page < pagination.total_pages) {
      $pagination.append(
        $('<button>')
          .addClass('koopo-pagination-btn')
          .text('Next »')
          .data('page', pagination.page + 1)
          .on('click', function() {
            loadAppointments($(this).data('page'));
          })
      );
    }
  }

  /**
   * Show cancel modal
   */
  function showCancelModal(booking) {
    currentBookingId = booking.id;

    const $modal = $('#koopo-cancel-modal');
    
    // Populate details
    $modal.find('.koopo-cancel-details').html(`
      <div class="koopo-booking-summary">
        <h4>${booking.service_title}</h4>
        <p>${booking.listing_title}</p>
        <p><strong>Date:</strong> ${booking.start_datetime_formatted}</p>
        <p><strong>Duration:</strong> ${booking.duration_formatted}</p>
        <p><strong>Price:</strong> ${KOOPO_CUSTOMER.currency_symbol}${booking.price.toFixed(2)}</p>
      </div>
    `);

    // Show policy (this would ideally come from API)
    $modal.find('.koopo-cancel-policy').html(`
      <div class="koopo-policy-notice">
        <p><strong>Cancellation Policy:</strong></p>
        <p>Your refund amount will be calculated based on the time remaining until your appointment.</p>
      </div>
    `);

    $modal.fadeIn(200);
  }

  /**
   * Process cancellation
   */
  async function processCancellation() {
    const reason = $('#cancel-reason-input').val().trim();
    const $btn = $('.koopo-confirm-cancel');

    $btn.prop('disabled', true).text('Cancelling...');

    try {
      const response = await fetch(
        `${KOOPO_CUSTOMER.api_url}/customer/bookings/${currentBookingId}/cancel`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': KOOPO_CUSTOMER.nonce
          },
          body: JSON.stringify({ reason })
        }
      );

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Cancellation failed');
      }

      // Close modal
      $('#koopo-cancel-modal').fadeOut(200);
      $('#cancel-reason-input').val('');

      // Show success message
      let message = KOOPO_CUSTOMER.i18n.cancel_success;
      if (data.refund_amount > 0) {
        message += `\n\nRefund amount: ${KOOPO_CUSTOMER.currency_symbol}${data.refund_amount.toFixed(2)}\n${data.refund_message}`;
      }
      alert(message);

      // Reload appointments
      loadAppointments(currentPage);

    } catch (error) {
      console.error('Cancellation error:', error);
      alert(KOOPO_CUSTOMER.i18n.cancel_error + '\n\n' + error.message);
      $btn.prop('disabled', false).text('Confirm Cancellation');
    }
  }

  /**
   * Show reschedule request modal
   */
  function showRescheduleModal(booking) {
    currentBookingId = booking.id;
    rescheduleState.bookingId = booking.id;
    rescheduleState.serviceId = booking.service_id;
    rescheduleState.timezone = booking.timezone || '';
    rescheduleState.selectedDate = null;
    rescheduleState.selectedSlot = null;

    const startTs = new Date(booking.start_datetime).getTime();
    const endTs = new Date(booking.end_datetime).getTime();
    rescheduleState.duration = Math.round((endTs - startTs) / 60000);
    rescheduleState.currentMonth = new Date(booking.start_datetime);

    const $modal = $('#koopo-reschedule-modal');
    $modal.find('.koopo-reschedule-message').hide();
    $modal.find('.koopo-reschedule-step[data-step="2"]').hide();
    $modal.find('.koopo-reschedule-summary').hide();
    $modal.find('.koopo-confirm-reschedule').prop('disabled', true).text('Reschedule');
    $modal.find('.koopo-reschedule-back').hide();
    
    // Populate details
    $modal.find('.koopo-reschedule-details').html(`
      <div class="koopo-booking-summary">
        <h4>${booking.service_title}</h4>
        <p><strong>Current time:</strong> ${booking.start_datetime_formatted}</p>
      </div>
    `);

    $modal.fadeIn(200);
    renderRescheduleCalendar();
  }

  function renderRescheduleCalendar() {
    const $calendar = $('#koopo-reschedule-calendar');
    const month = rescheduleState.currentMonth;
    const year = month.getFullYear();
    const monthIndex = month.getMonth();

    const firstDay = new Date(year, monthIndex, 1);
    const lastDay = new Date(year, monthIndex + 1, 0);
    const startDay = firstDay.getDay();
    const totalDays = lastDay.getDate();
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

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

    for (let i = 0; i < startDay; i++) {
      html += `<div class="koopo-calendar__date is-other-month"></div>`;
    }

    for (let day = 1; day <= totalDays; day++) {
      const dateStr = `${year}-${String(monthIndex + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
      let classes = 'koopo-calendar__date';
      if (rescheduleState.selectedDate === dateStr) classes += ' is-selected';
      html += `<div class="${classes}" data-date="${dateStr}">${day}</div>`;
    }

    html += '</div></div>';
    $calendar.html(html);
  }

  async function loadRescheduleSlots(date) {
    const $slotsContainer = $('.koopo-reschedule-slots');
    $slotsContainer.html('<div class="koopo-reschedule-loading">Loading available times...</div>');

    try {
      const response = await fetch(
        `${KOOPO_CUSTOMER.api_url}/availability/by-service/${rescheduleState.serviceId}?date=${encodeURIComponent(date)}`,
        { headers: { 'X-WP-Nonce': KOOPO_CUSTOMER.nonce } }
      );
      const data = await response.json();
      const slots = data.slots || [];

      if (!slots.length) {
        $slotsContainer.html(`
          <div class="koopo-reschedule-empty">
            <strong>No available times</strong>
            <p>Please pick another date.</p>
          </div>
        `);
        return;
      }

      let html = '<div class="koopo-reschedule-slot-grid">';
      slots.forEach(slot => {
        const isSelected = rescheduleState.selectedSlot && rescheduleState.selectedSlot.start === slot.start;
        html += `
          <button type="button" class="koopo-reschedule-slot ${isSelected ? 'is-selected' : ''}"
                  data-start="${slot.start}" data-end="${slot.end}">
            ${slot.label}
          </button>
        `;
      });
      html += '</div>';
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

  async function submitReschedule() {
    if (!rescheduleState.selectedSlot) {
      showRescheduleMessage('error', 'Please select a new date and time.');
      return;
    }

    const $btn = $('.koopo-confirm-reschedule');
    $btn.prop('disabled', true).text('Rescheduling...');

    try {
      const response = await fetch(
        `${KOOPO_CUSTOMER.api_url}/customer/bookings/${currentBookingId}/reschedule`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': KOOPO_CUSTOMER.nonce
          },
          body: JSON.stringify({
            start_datetime: rescheduleState.selectedSlot.start,
            end_datetime: rescheduleState.selectedSlot.end,
            timezone: rescheduleState.timezone || '',
          })
        }
      );

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'Reschedule failed');
      }

      showRescheduleMessage('success', 'Appointment rescheduled successfully.');
      setTimeout(() => {
        $('#koopo-reschedule-modal').fadeOut(200);
      }, 1000);

      loadAppointments(currentPage);
    } catch (error) {
      showRescheduleMessage('error', error.message || 'Reschedule failed.');
      $btn.prop('disabled', false).text('Reschedule');
    }
  }

  function showRescheduleMessage(type, text) {
    const $msg = $('.koopo-reschedule-message');
    $msg.removeClass('is-error is-success');
    if (type === 'error') $msg.addClass('is-error');
    if (type === 'success') $msg.addClass('is-success');
    $msg.text(text).show();
  }

  /**
   * Bind event handlers
   */
  function bindEvents() {
    // Filter tabs
    $('.koopo-filter-tab').on('click', function() {
      $('.koopo-filter-tab').removeClass('koopo-filter-tab--active');
      $(this).addClass('koopo-filter-tab--active');
      currentFilter = $(this).data('status');
      loadAppointments(1);
    });

    // Cancel button
    $(document).on('click', '.koopo-btn-cancel', function() {
      const booking = $(this).data('booking');
      showCancelModal(booking);
    });

    // Reschedule button
    $(document).on('click', '.koopo-btn-reschedule', function() {
      const booking = $(this).data('booking');
      showRescheduleModal(booking);
    });

    // Confirm cancel
    $('.koopo-confirm-cancel').on('click', processCancellation);

    // Confirm reschedule
    $('.koopo-confirm-reschedule').on('click', submitReschedule);

    // Close modals
    $('.koopo-modal__close, .koopo-cancel-modal__close, .koopo-reschedule-modal__close').on('click', function() {
      $('.koopo-modal').fadeOut(200);
      $('#cancel-reason-input').val('');
      $('.koopo-reschedule-message').hide();
    });

    // Reschedule calendar navigation
    $(document).on('click', '.koopo-calendar__prev', function() {
      rescheduleState.currentMonth = new Date(
        rescheduleState.currentMonth.getFullYear(),
        rescheduleState.currentMonth.getMonth() - 1,
        1
      );
      renderRescheduleCalendar();
    });

    $(document).on('click', '.koopo-calendar__next', function() {
      rescheduleState.currentMonth = new Date(
        rescheduleState.currentMonth.getFullYear(),
        rescheduleState.currentMonth.getMonth() + 1,
        1
      );
      renderRescheduleCalendar();
    });

    $(document).on('click', '.koopo-calendar__date:not(.is-other-month)', function() {
      const date = $(this).data('date');
      if (!date) return;
      rescheduleState.selectedDate = date;
      rescheduleState.selectedSlot = null;
      $('.koopo-reschedule-summary').hide();
      $('.koopo-confirm-reschedule').prop('disabled', true);
      $('.koopo-reschedule-step[data-step="2"]').show();
      $('.koopo-reschedule-back').show();
      renderRescheduleCalendar();
      loadRescheduleSlots(date);
    });

    $(document).on('click', '.koopo-reschedule-slot', function() {
      const start = $(this).data('start');
      const end = $(this).data('end');
      if (!start || !end) return;
      rescheduleState.selectedSlot = { start, end };
      $('.koopo-reschedule-slot').removeClass('is-selected');
      $(this).addClass('is-selected');
      const formatted = `${start.replace('T',' ').replace('Z','')}`;
      $('.koopo-reschedule-summary__text').text(formatted);
      $('.koopo-reschedule-summary').show();
      $('.koopo-confirm-reschedule').prop('disabled', false);
    });

    $(document).on('click', '.koopo-reschedule-back', function() {
      $('.koopo-reschedule-step[data-step="2"]').hide();
      $('.koopo-reschedule-summary').hide();
      $('.koopo-reschedule-back').hide();
      $('.koopo-confirm-reschedule').prop('disabled', true);
    });

    // Calendar dropdown toggle
    $(document).on('click', '.koopo-btn-calendar', function(e) {
      e.stopPropagation();
      $(this).next('.koopo-calendar-menu').toggle();
    });

    // Close dropdown when clicking outside
    $(document).on('click', function() {
      $('.koopo-calendar-menu').hide();
    });

    $(document).on('click', '.koopo-calendar-menu', function(e) {
      e.stopPropagation();
    });
  }

  // Initialize on document ready
  $(document).ready(init);

})(jQuery);
