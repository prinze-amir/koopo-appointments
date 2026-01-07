<?php defined('ABSPATH') || exit; ?>
<div class="dokan-dashboard-wrap">
  <?php do_action('dokan_dashboard_content_before'); ?>
  <div class="dokan-dashboard-content koopo-vendor-page">
    <header class="koopo-vendor-header">
      <h2><?php esc_html_e('Appointment Settings', 'appointments'); ?></h2>
      <div class="koopo-vendor-header__right">
        <select class="koopo-appt-settings__listing koopo-input">
          <option value=""><?php esc_html_e('Select listing…', 'appointments'); ?></option>
        </select>
      </div>
    </header>

    <div class="koopo-card" id="koopo-settings-card">
      <!-- Settings UI will be mounted here by JavaScript -->
      <div class="koopo-appt-settings-mount" data-mode="dokan"></div>
    </div>
  </div>
  <?php do_action('dokan_dashboard_content_after'); ?>
</div>
