// Commit 23: Customer Dashboard JavaScript
// File: assets/customer-dashboard-app.js

(function($) {
  'use strict';

  if (typeof KOOPO_CUSTOMER === 'undefined') return;
  const utils = window.KOOPO_CUSTOMER_UTILS || {};
  const api = utils.api;
  const fmtMoney = utils.fmtMoney;
  if (!api || !fmtMoney) return;

  let currentFilter = 'upcoming';
  let currentPage = 1;
  let currentBookingId = null;
  let $list = null;
  let $pagination = null;
  let $cancelModal = null;
  let $rescheduleModal = null;
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
    $list = $('#koopo-appointments-list');
    $pagination = $('#koopo-pagination');
    $cancelModal = $('#koopo-cancel-modal');
    $rescheduleModal = $('#koopo-reschedule-modal');
    loadAppointments();
    bindEvents();
  }

  /**
   * Load appointments from API
   */
  async function loadAppointments(page = 1) {
    currentPage = page;
    if (!$list || !$list.length) return;
    
    // Show loading
    $list.html(`
      <div class="koopo-appointments-loading">
        <div class="koopo-spinner"></div>
        <p>${KOOPO_CUSTOMER.i18n.loading}</p>
      </div>
    `);

    try {
      const data = await api(`/customer/bookings?status=${currentFilter}&page=${page}&per_page=10`, { method: 'GET' });
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
    if (!$list || !$list.length) return;
    
    if (items.length === 0) {
      renderEmptyState();
      return;
    }

    const frag = document.createDocumentFragment();
    items.forEach(booking => {
      const $card = createAppointmentCard(booking);
      if ($card && $card.length) {
        frag.appendChild($card[0]);
      }
    });
    $list.empty().append(frag);
  }

  function formatRescheduleWindow(booking) {
    const value = Number(booking.reschedule_cutoff_value || 0);
    const unit = (booking.reschedule_cutoff_unit || 'hours').toLowerCase();
    if (!value) return '';
    const label = value === 1 ? unit.replace(/s$/, '') : unit;
    return `Reschedule up to ${value} ${label} before start time.`;
  }

  function formatRescheduleDateTime(datetime) {
    const date = new Date(datetime);
    if (Number.isNaN(date.getTime())) return '';
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    const yy = String(date.getFullYear()).slice(-2);
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'pm' : 'am';
    hours = hours % 12 || 12;
    return `${mm}/${dd}/${yy} at ${hours}:${minutes} ${ampm}`;
  }

  function formatHoldNotice(booking) {
    if (booking.status === 'expired') {
      return 'Booking deleted due to non-payment. Please book again.';
    }
    if (booking.status !== 'pending_payment') return '';
    const expiresAt = Number(booking.payment_hold_expires_at || 0);
    const totalHold = Number(booking.payment_hold_minutes || 10);
    const minutesLeftFromNotice = Math.max(1, totalHold - 3);
    const payLink = booking.pay_now_url
      ? ` <a href="${booking.pay_now_url}" class="koopo-inline-link">Pay now</a>`
      : '';
    if (!expiresAt) {
      return `Pending payment. Please complete checkout within ${minutesLeftFromNotice} minutes or it will be deleted.${payLink}`;
    }
    const now = Date.now() / 1000;
    const minutesLeft = Math.max(0, Math.ceil((expiresAt - now) / 60));
    if (!minutesLeft) {
      return 'Booking deleted due to non-payment. Please book again.';
    }
    const displayMinutes = Math.min(minutesLeftFromNotice, minutesLeft);
    return `Pending payment. Please pay within ${displayMinutes} minutes or it will be deleted.${payLink}`;
  }

  /**
   * Create appointment card
   */
  function createAppointmentCard(booking) {
    const $template = $('#koopo-appointment-card-template');
    const $card = $($template.html()).clone();

    $card.attr('data-id', booking.id);
    $card.find('.koopo-service-title').text(booking.service_title);
    if (booking.listing_url) {
      $card.find('.koopo-listing-title').html(
        `<a href="${booking.listing_url}" class="koopo-inline-link" target="_blank" rel="noopener">${booking.listing_title}</a>`
      );
    } else {
      $card.find('.koopo-listing-title').text(booking.listing_title);
    }
    $card.find('.koopo-datetime').text(booking.start_datetime_formatted);
    $card.find('.koopo-duration').text(booking.duration_formatted);
    $card.find('.koopo-price').text(fmtMoney(booking.price));

    const pendingNotice = formatHoldNotice(booking);
    if (pendingNotice) {
      $card.find('.koopo-pending-payment').html(pendingNotice);
      $card.find('.koopo-pending-payment-row').show();
    }

    if (booking.status === 'cancelled' || booking.status === 'refunded') {
      const cancelledBy = (booking.cancelled_by || '').trim();
      const cancelledLabel = cancelledBy ? `Cancelled by ${cancelledBy}` : 'Cancelled';
      $card.find('.koopo-cancel-info').text(cancelledLabel);
      $card.find('.koopo-cancel-info-row').show();

      const refundAmount = Number(booking.refund_amount || 0);
      const refundStatus = (booking.refund_status || '').trim();
      let refundText = 'No refund';
      if (refundStatus === 'refunded' || refundAmount > 0) {
        refundText = `Refunded ${fmtMoney(refundAmount)}`;
      } else if (refundStatus === 'pending') {
        refundText = `Refund pending (${fmtMoney(refundAmount)})`;
      }
      $card.find('.koopo-refund-info').text(refundText);
      $card.find('.koopo-refund-info-row').show();
    }

    const addonTitles = Array.isArray(booking.addon_titles) ? booking.addon_titles.filter(Boolean) : [];
    if (addonTitles.length) {
      $card.find('.koopo-addons').text(`Add-ons: ${addonTitles.join(', ')}`);
      $card.find('.koopo-addon-row').show();
    }

    const rescheduleWindow = formatRescheduleWindow(booking);
    if (rescheduleWindow) {
      $card.find('.koopo-reschedule-window').text(rescheduleWindow);
      $card.find('.koopo-reschedule-window-row').show();
    }

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
    if (booking.status === 'pending_payment' && booking.pay_now_url) {
      $card.find('.koopo-btn-pay')
        .show()
        .attr('href', booking.pay_now_url)
        .attr('data-booking-id', booking.id);
    }

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
    if (!$list || !$list.length) return;
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
    if (!$pagination || !$pagination.length) return;

    if (pagination.total_pages <= 1) {
      $pagination.hide();
      return;
    }

    const start = Math.max(1, pagination.page - 2);
    const end = Math.min(pagination.total_pages, pagination.page + 2);
    let html = '';
    if (pagination.page > 1) {
      html += `<button class="koopo-pagination-btn" data-page="${pagination.page - 1}">« Previous</button>`;
    }
    for (let i = start; i <= end; i++) {
      const active = i === pagination.page ? ' koopo-pagination-btn--active' : '';
      html += `<button class="koopo-pagination-btn${active}" data-page="${i}">${i}</button>`;
    }
    if (pagination.page < pagination.total_pages) {
      html += `<button class="koopo-pagination-btn" data-page="${pagination.page + 1}">Next »</button>`;
    }
    $pagination.html(html).show();
  }

  /**
   * Show cancel modal
   */
  function showCancelModal(booking) {
    currentBookingId = booking.id;

    const $modal = $cancelModal && $cancelModal.length ? $cancelModal : $('#koopo-cancel-modal');
    const addonTitles = Array.isArray(booking.addon_titles) ? booking.addon_titles.filter(Boolean) : [];
    const addonLabel = addonTitles.length ? addonTitles.join(', ') : '—';
    const basePrice = Number(booking.service_price || 0);
    const addonTotal = Number(booking.addon_total_price || 0);
    const baseDuration = Number(booking.service_duration || 0);
    const addonDuration = Number(booking.addon_total_duration || 0);
    
    // Populate details
    $modal.find('.koopo-cancel-details').html(`
      <div class="koopo-booking-summary">
        <h4>${booking.service_title}</h4>
        <p>${booking.listing_url ? `<a href="${booking.listing_url}" class="koopo-inline-link" target="_blank" rel="noopener">${booking.listing_title}</a>` : booking.listing_title}</p>
        <p><strong>Date:</strong> ${booking.start_datetime_formatted}</p>
        <p><strong>Duration:</strong> ${booking.duration_formatted}</p>
        <p><strong>Add-ons:</strong> ${addonLabel}</p>
        <p><strong>Base Duration:</strong> ${baseDuration ? `${baseDuration} min` : '—'}</p>
        <p><strong>Add-on Duration:</strong> ${addonDuration ? `${addonDuration} min` : '—'}</p>
        <p><strong>Service Price:</strong> ${basePrice ? fmtMoney(basePrice) : '—'}</p>
        <p><strong>Add-ons Total:</strong> ${addonTotal ? fmtMoney(addonTotal) : '—'}</p>
        <p><strong>Total Price:</strong> ${fmtMoney(booking.price)}</p>
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
      const data = await api(`/customer/bookings/${currentBookingId}/cancel`, {
        method: 'POST',
        body: JSON.stringify({ reason })
      });

      // Close modal
      const $modal = $cancelModal && $cancelModal.length ? $cancelModal : $('#koopo-cancel-modal');
      $modal.fadeOut(200);
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

    const $modal = $rescheduleModal && $rescheduleModal.length ? $rescheduleModal : $('#koopo-reschedule-modal');
    $modal.find('.koopo-reschedule-message').hide();
    $modal.find('.koopo-reschedule-step[data-step="2"]').hide();
    $modal.find('.koopo-reschedule-summary').hide();
    $modal.find('.koopo-confirm-reschedule').prop('disabled', true).text('Reschedule');
    $modal.find('.koopo-reschedule-back').hide();
    
    // Populate details
    const addonTitles = Array.isArray(booking.addon_titles) ? booking.addon_titles.filter(Boolean) : [];
    const addonLabel = addonTitles.length ? addonTitles.join(', ') : '—';
    const basePrice = Number(booking.service_price || 0);
    const addonTotal = Number(booking.addon_total_price || 0);
    const baseDuration = Number(booking.service_duration || 0);
    const addonDuration = Number(booking.addon_total_duration || 0);

    const rescheduleWindow = formatRescheduleWindow(booking);

    $modal.find('.koopo-reschedule-details').html(`
      <div class="koopo-booking-summary">
        <h4>${booking.service_title}</h4>
        <p><strong>Current time:</strong> ${booking.start_datetime_formatted}</p>
        <p><strong>Add-ons:</strong> ${addonLabel}</p>
        <p><strong>Base Duration:</strong> ${baseDuration ? `${baseDuration} min` : '—'}</p>
        <p><strong>Add-on Duration:</strong> ${addonDuration ? `${addonDuration} min` : '—'}</p>
        <p><strong>Service Price:</strong> ${basePrice ? fmtMoney(basePrice) : '—'}</p>
        <p><strong>Add-ons Total:</strong> ${addonTotal ? fmtMoney(addonTotal) : '—'}</p>
        <p><strong>Total Price:</strong> ${fmtMoney(booking.price)}</p>
        ${rescheduleWindow ? `<p><strong>Reschedule window:</strong> ${rescheduleWindow}</p>` : ''}
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
      const data = await api(`/availability/by-service/${rescheduleState.serviceId}?date=${encodeURIComponent(date)}`, { method: 'GET' });
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
      await api(`/customer/bookings/${currentBookingId}/reschedule`, {
        method: 'POST',
        body: JSON.stringify({
          start_datetime: rescheduleState.selectedSlot.start,
          end_datetime: rescheduleState.selectedSlot.end,
          timezone: rescheduleState.timezone || '',
        })
      });

      showRescheduleMessage('success', 'Appointment rescheduled successfully.');
      setTimeout(() => {
        const $modal = $rescheduleModal && $rescheduleModal.length ? $rescheduleModal : $('#koopo-reschedule-modal');
        $modal.fadeOut(200);
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

    // Pay now button
    $(document).on('click', '.koopo-btn-pay', async function(e) {
      e.preventDefault();
      const bookingId = parseInt($(this).attr('data-booking-id'), 10);
      if (!bookingId) return;
      try {
        const data = await api(`/bookings/${bookingId}/checkout-cart`, {
          method: 'POST',
          body: JSON.stringify({ clear_cart: true })
        });
        window.location.href = data.checkout_url;
      } catch (error) {
        alert(error.message || 'Unable to start checkout.');
      }
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
      const formatted = formatRescheduleDateTime(start);
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

    if ($pagination && $pagination.length) {
      $pagination.on('click', '.koopo-pagination-btn', function() {
        const nextPage = parseInt($(this).data('page'), 10) || 1;
        loadAppointments(nextPage);
      });
    }
  }

  // Initialize on document ready
  $(document).ready(init);

})(jQuery);
