<?php
/**
 * Commit 23: Customer Appointments Template
 * Template: templates/customer/my-appointments.php
 * 
 * Displays customer's appointment bookings with filter and actions
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

?>

<div class="koopo-customer-appointments">
  
  <h2 class="koopo-customer-appointments__title">
    <?php esc_html_e('My Appointments', 'koopo-appointments'); ?>
  </h2>

  <!-- Filter Tabs -->
  <div class="koopo-appointments-filter">
    <button class="koopo-filter-tab koopo-filter-tab--active" data-status="upcoming">
      <?php esc_html_e('Upcoming', 'koopo-appointments'); ?>
    </button>
    <button class="koopo-filter-tab" data-status="past">
      <?php esc_html_e('Past', 'koopo-appointments'); ?>
    </button>
    <button class="koopo-filter-tab" data-status="cancelled">
      <?php esc_html_e('Cancelled', 'koopo-appointments'); ?>
    </button>
    <button class="koopo-filter-tab" data-status="all">
      <?php esc_html_e('All', 'koopo-appointments'); ?>
    </button>
  </div>

  <!-- Appointments List -->
  <div class="koopo-appointments-list" id="koopo-appointments-list">
    <!-- Loading state -->
    <div class="koopo-appointments-loading">
      <div class="koopo-spinner"></div>
      <p><?php esc_html_e('Loading appointments...', 'koopo-appointments'); ?></p>
    </div>
  </div>

  <!-- Pagination -->
  <div class="koopo-appointments-pagination" id="koopo-pagination" style="display:none;">
    <!-- Pagination buttons rendered by JS -->
  </div>

</div>

<!-- Cancel Modal -->
<div class="koopo-modal koopo-cancel-modal" id="koopo-cancel-modal" style="display:none;">
  <div class="koopo-modal__card">
    <button class="koopo-modal__close" type="button">&times;</button>
    
    <h3><?php esc_html_e('Cancel Appointment', 'koopo-appointments'); ?></h3>
    
    <div class="koopo-cancel-details">
      <!-- Populated by JS -->
    </div>

    <div class="koopo-cancel-policy">
      <!-- Populated by JS -->
    </div>

    <div class="koopo-cancel-reason">
      <label for="cancel-reason-input">
        <?php esc_html_e('Reason for cancellation (optional)', 'koopo-appointments'); ?>
      </label>
      <textarea id="cancel-reason-input" rows="3" 
                placeholder="<?php esc_attr_e('Let us know why you\'re cancelling...', 'koopo-appointments'); ?>"></textarea>
    </div>

    <div class="koopo-modal__footer">
      <button type="button" class="koopo-btn koopo-cancel-modal__close">
        <?php esc_html_e('Go Back', 'koopo-appointments'); ?>
      </button>
      <button type="button" class="koopo-btn koopo-btn--danger koopo-confirm-cancel">
        <?php esc_html_e('Confirm Cancellation', 'koopo-appointments'); ?>
      </button>
    </div>
  </div>
</div>

<!-- Reschedule Modal -->
<div class="koopo-modal koopo-reschedule-request-modal" id="koopo-reschedule-modal" style="display:none;">
  <div class="koopo-modal__card">
    <button class="koopo-modal__close" type="button">&times;</button>
    
    <h3><?php esc_html_e('Reschedule Appointment', 'koopo-appointments'); ?></h3>
    <div class="koopo-reschedule-message" style="display:none;"></div>

    <div class="koopo-reschedule-details">
      <!-- Populated by JS -->
    </div>

    <div class="koopo-reschedule-step" data-step="1">
      <h4><?php esc_html_e('Step 1: Select New Date', 'koopo-appointments'); ?></h4>
      <div id="koopo-reschedule-calendar"></div>
    </div>

    <div class="koopo-reschedule-step" data-step="2" style="display:none;">
      <h4><?php esc_html_e('Step 2: Select New Time', 'koopo-appointments'); ?></h4>
      <div class="koopo-reschedule-slots"></div>
    </div>

    <div class="koopo-reschedule-summary" style="display:none;">
      <strong><?php esc_html_e('New Time', 'koopo-appointments'); ?></strong>
      <div class="koopo-reschedule-summary__text"></div>
    </div>

    <div class="koopo-modal__footer">
      <button type="button" class="koopo-btn koopo-reschedule-back" style="display:none;">‹ <?php esc_html_e('Back', 'koopo-appointments'); ?></button>
      <button type="button" class="koopo-btn koopo-reschedule-modal__close">
        <?php esc_html_e('Cancel', 'koopo-appointments'); ?>
      </button>
      <button type="button" class="koopo-btn koopo-btn--primary koopo-confirm-reschedule" disabled>
        <?php esc_html_e('Reschedule', 'koopo-appointments'); ?>
      </button>
    </div>
  </div>
</div>

<!-- Template for appointment card (hidden, cloned by JS) -->
<template id="koopo-appointment-card-template">
  <div class="koopo-appointment-card" data-id="">
    <div class="koopo-appointment-card__header">
      <div class="koopo-appointment-card__service">
        <h3 class="koopo-service-title"></h3>
        <p class="koopo-listing-title"></p>
      </div>
      <span class="koopo-status-badge"></span>
    </div>

    <div class="koopo-appointment-card__body">
      <div class="koopo-appointment-info">
        <div class="koopo-info-row">
          <span class="koopo-info-icon">📅</span>
          <span class="koopo-info-text koopo-datetime"></span>
        </div>
        <div class="koopo-info-row">
          <span class="koopo-info-icon">⏱️</span>
          <span class="koopo-info-text koopo-duration"></span>
        </div>
        <div class="koopo-info-row">
          <span class="koopo-info-icon">💰</span>
          <span class="koopo-info-text koopo-price"></span>
        </div>
        <div class="koopo-info-row koopo-pending-payment-row" style="display:none;">
          <span class="koopo-info-icon">⚠️</span>
          <span class="koopo-info-text koopo-pending-payment"></span>
        </div>
        <div class="koopo-info-row koopo-reschedule-window-row" style="display:none;">
          <span class="koopo-info-icon">⏳</span>
          <span class="koopo-info-text koopo-reschedule-window"></span>
        </div>
        <div class="koopo-info-row koopo-addon-row" style="display:none;">
          <span class="koopo-info-icon">➕</span>
          <span class="koopo-info-text koopo-addons"></span>
        </div>
        <div class="koopo-info-row koopo-relative-time-row" style="display:none;">
          <span class="koopo-info-icon">⏰</span>
          <span class="koopo-info-text koopo-relative-time"></span>
        </div>
      </div>
    </div>

    <div class="koopo-appointment-card__actions">
      <a href="#" class="koopo-btn koopo-btn--small koopo-btn-pay" target="_blank" style="display:none;">
        <?php esc_html_e('Pay Now', 'koopo-appointments'); ?>
      </a>
      <button type="button" class="koopo-btn koopo-btn--small koopo-btn-cancel" style="display:none;">
        <?php esc_html_e('Cancel', 'koopo-appointments'); ?>
      </button>
      <button type="button" class="koopo-btn koopo-btn--small koopo-btn-reschedule" style="display:none;">
        <?php esc_html_e('Request Reschedule', 'koopo-appointments'); ?>
      </button>
      <div class="koopo-calendar-dropdown" style="display:none;">
        <button type="button" class="koopo-btn koopo-btn--small koopo-btn-calendar">
          <?php esc_html_e('Add to Calendar', 'koopo-appointments'); ?> ▾
        </button>
        <div class="koopo-calendar-menu">
          <a href="#" class="koopo-calendar-link koopo-calendar-google" target="_blank" rel="noopener">
            <?php esc_html_e('Google Calendar', 'koopo-appointments'); ?>
          </a>
          <a href="#" class="koopo-calendar-link koopo-calendar-apple" download="appointment.ics">
            <?php esc_html_e('Apple Calendar', 'koopo-appointments'); ?>
          </a>
          <a href="#" class="koopo-calendar-link koopo-calendar-outlook" target="_blank" rel="noopener">
            <?php esc_html_e('Outlook', 'koopo-appointments'); ?>
          </a>
        </div>
      </div>
      <a href="#" class="koopo-btn koopo-btn--small koopo-btn-order" target="_blank" style="display:none;">
        <?php esc_html_e('View Order', 'koopo-appointments'); ?>
      </a>
    </div>
  </div>
</template>

<!-- Empty state template -->
<template id="koopo-empty-state-template">
  <div class="koopo-empty-state">
    <div class="koopo-empty-state__icon">📅</div>
    <h3><?php esc_html_e('No appointments found', 'koopo-appointments'); ?></h3>
    <p class="koopo-empty-state__message"></p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="koopo-btn koopo-btn--primary">
      <?php esc_html_e('Browse Services', 'koopo-appointments'); ?>
    </a>
  </div>
</template>
