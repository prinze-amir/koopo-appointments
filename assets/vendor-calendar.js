(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;
  const utils = window.KOOPO_VENDOR_UTILS || {};
  const escapeHtml = utils.escapeHtml;
  const renderAvatar = utils.renderAvatar;
  const formatTime = utils.formatTime;
  if (!escapeHtml || !renderAvatar || !formatTime) return;

  function getCalendarTitleText(booking){
    return escapeHtml(booking?.service_title || 'Appointment');
  }

  function getCalendarCustomerLabel(booking){
    const customer = booking?.customer_name || booking?.customer_email || 'Guest';
    return booking?.customer_is_guest ? `${customer} (Guest)` : customer;
  }

  function renderCalendarEventContent(booking, opts = {}){
    const statusBadge = opts.statusBadgeHtml || '';
    let html = `<div class="koopo-cal-event__title"><strong>${getCalendarTitleText(booking)}</strong>${statusBadge}</div>`;
    if (opts.showCustomer) {
      if (opts.customerStyle === 'avatar') {
        html += `<div class="koopo-cal-event__customer">${renderAvatar(booking)}${booking?.customer_is_guest ? ' <span class="koopo-guest-badge">Guest</span>' : ''}</div>`;
      } else {
        html += `<div class="koopo-cal-event__customer">${escapeHtml(getCalendarCustomerLabel(booking))}</div>`;
      }
    }
    if (opts.showTime) {
      html += `<div class="koopo-cal-event__time">${escapeHtml(formatTime(booking?.start_datetime))}</div>`;
    }
    return html;
  }

  utils.getCalendarTitleText = getCalendarTitleText;
  utils.getCalendarCustomerLabel = getCalendarCustomerLabel;
  utils.renderCalendarEventContent = renderCalendarEventContent;

  window.KOOPO_VENDOR_UTILS = utils;
})(jQuery);
