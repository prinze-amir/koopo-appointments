// Commit 20: Enhanced Refund UI with Policy Display
// This is a PARTIAL file showing the enhanced refund handling
// Append these functions to the existing vendor.js file

(function($){
  if (typeof KOOPO_APPT_VENDOR === 'undefined') return;

  // ... (existing code remains) ...

  /**
   * Commit 20: Show refund modal with policy and amount calculation
   */
  async function showRefundModal(bookingId) {
    try {
      // Get refund info from API
      const info = await api(`/vendor/bookings/${bookingId}/refund-info`, { method: 'GET' });
      
      const policy = info.policy || {};
      const wc = info.woocommerce || {};
      const bookingPrice = info.booking_price || 0;

      // Build modal HTML
      let modalHtml = `
        <div class="koopo-modal koopo-refund-modal">
          <div class="koopo-modal__card">
            <button class="koopo-modal__close koopo-refund-modal__close" type="button">&times;</button>
            <h3>Process Refund</h3>
      `;

      // Show refund eligibility
      if (!policy.refundable) {
        modalHtml += `
          <div class="koopo-refund-warning">
            <strong>⚠️ Refund Not Available</strong>
            <p>${escapeHtml(policy.message || 'This booking cannot be refunded.')}</p>
          </div>
          <div class="koopo-modal__footer">
            <button type="button" class="koopo-btn koopo-refund-modal__close">Close</button>
          </div>
        `;
      } else {
        // Refundable - show policy and options
        modalHtml += `
          <div class="koopo-refund-summary">
            <div class="koopo-refund-row">
              <span>Booking Price:</span>
              <strong>$${bookingPrice.toFixed(2)}</strong>
            </div>
        `;

        if (policy.fee > 0) {
          modalHtml += `
            <div class="koopo-refund-row koopo-refund-row--fee">
              <span>Cancellation Fee:</span>
              <strong>-$${policy.fee.toFixed(2)}</strong>
            </div>
          `;
        }

        modalHtml += `
            <div class="koopo-refund-row koopo-refund-row--total">
              <span>Refund Amount:</span>
              <strong>$${policy.amount.toFixed(2)}</strong>
            </div>
          </div>

          <div class="koopo-refund-policy-note">
            <small>${escapeHtml(policy.message || '')}</small>
          </div>
        `;

        // Show WooCommerce refund method
        if (wc.can_refund) {
          modalHtml += `
            <div class="koopo-refund-gateway">
              <strong>Payment Method:</strong> ${escapeHtml(wc.gateway || 'Unknown')}
              <br>
              ${wc.automatic 
                ? '<span class="koopo-refund-auto">✓ Automatic refund via payment gateway</span>'
                : '<span class="koopo-refund-manual">⚠️ Manual refund required in payment gateway</span>'}
            </div>
          `;

          if (!wc.automatic && wc.instructions) {
            modalHtml += `
              <div class="koopo-refund-instructions">
                <small>${escapeHtml(wc.instructions)}</small>
              </div>
            `;
          }
        } else {
          modalHtml += `
            <div class="koopo-refund-warning">
              <strong>⚠️ Cannot Process Refund</strong>
              <p>${escapeHtml(wc.instructions || 'No order available for refund.')}</p>
            </div>
          `;
        }

        // Refund reason input
        modalHtml += `
          <div class="koopo-refund-form">
            <label class="koopo-label">
              Refund Reason (Optional)
              <textarea class="koopo-input koopo-refund-reason" rows="3" placeholder="Enter reason for refund (visible to customer)"></textarea>
            </label>

            <label class="koopo-label">
              <input type="checkbox" class="koopo-refund-custom-amount-toggle" />
              Use custom refund amount
            </label>

            <label class="koopo-label koopo-refund-custom-amount-field" style="display:none;">
              Custom Amount
              <input type="number" step="0.01" min="0.01" max="${wc.available_amount || bookingPrice}" 
                     class="koopo-input koopo-refund-custom-amount" 
                     value="${policy.amount.toFixed(2)}" />
              <small>Maximum: $${(wc.available_amount || bookingPrice).toFixed(2)}</small>
            </label>
          </div>

          <div class="koopo-modal__footer">
            <button type="button" class="koopo-btn koopo-refund-modal__close">Cancel</button>
            <button type="button" class="koopo-btn koopo-btn--danger koopo-refund-submit" 
                    data-booking-id="${bookingId}">
              Process Refund
            </button>
          </div>
        `;
      }

      modalHtml += `
          </div>
        </div>
      `;

      // Remove existing modal if present
      $('.koopo-refund-modal').remove();

      // Add to DOM
      $('body').append(modalHtml);

      // Show modal
      $('.koopo-refund-modal').fadeIn(200);

    } catch(e) {
      alert('Failed to load refund information: ' + (e.message || 'Unknown error'));
    }
  }

  /**
   * Commit 20: Process refund with enhanced API
   */
  async function processRefund(bookingId, reason, customAmount) {
    const $btn = $(`.koopo-refund-submit[data-booking-id="${bookingId}"]`);
    $btn.prop('disabled', true).text('Processing...');

    try {
      const payload = { action: 'refund', note: reason };
      
      if (customAmount !== null) {
        payload.amount = customAmount;
      }

      const result = await api(`/vendor/bookings/${bookingId}/action`, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      $('.koopo-refund-modal').fadeOut(200, function() {
        $(this).remove();
      });

      // Reload bookings table
      await loadAppointments();

      // Show success message
      let message = `✓ Refund processed: $${result.amount.toFixed(2)}\n\n`;
      
      if (result.automatic) {
        message += 'The refund was processed automatically via your payment gateway.';
      } else {
        message += 'The refund was created in WooCommerce. Please complete the refund manually in your payment gateway.';
      }

      alert(message);

    } catch(err) {
      alert('⚠️ Refund failed: ' + (err.message || 'Unknown error'));
      $btn.prop('disabled', false).text('Process Refund');
    }
  }

  // Event handlers for refund modal
  $(document).on('click', '.koopo-refund-modal__close', function() {
    $('.koopo-refund-modal').fadeOut(200, function() {
      $(this).remove();
    });
  });

  $(document).on('change', '.koopo-refund-custom-amount-toggle', function() {
    $('.koopo-refund-custom-amount-field').toggle($(this).is(':checked'));
  });

  $(document).on('click', '.koopo-refund-submit', async function(e) {
    e.preventDefault();
    
    const bookingId = parseInt($(this).data('booking-id'), 10);
    const reason = $('.koopo-refund-reason').val().trim();
    const useCustom = $('.koopo-refund-custom-amount-toggle').is(':checked');
    const customAmount = useCustom ? parseFloat($('.koopo-refund-custom-amount').val()) : null;

    if (!confirm('Process this refund? This action cannot be undone.')) {
      return;
    }

    await processRefund(bookingId, reason, customAmount);
  });

  // MODIFY EXISTING: Update refund button click handler in appointments table
  // Replace the existing refund button handler with this:
  $(document).on('click', '.koopo-appt-action[data-action="refund"]', async function(e) {
    e.preventDefault();
    const bookingId = parseInt($(this).data('id'), 10);
    if (!bookingId) return;

    await showRefundModal(bookingId);
  });

  // ... (rest of existing code remains) ...

})(jQuery);
