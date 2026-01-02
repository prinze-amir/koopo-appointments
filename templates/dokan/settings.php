<?php defined('ABSPATH') || exit; ?>
<div class="dokan-dashboard-wrap">
          <?php
            do_action( 'dokan_dashboard_content_before' );
        ?>
  <div class="dokan-dashboard-content koopo-vendor-page">
    <header class="koopo-vendor-header">
      <h2><?php esc_html_e('Appointment Settings', 'appointments'); ?></h2>
      <div class="koopo-vendor-header__right">
        <select id="koopo-settings-listing-picker" class="koopo-input">
          <option value=""><?php esc_html_e('Select listing…', 'appointments'); ?></option>
        </select>
      </div>
    </header>

    <div class="koopo-card" id="koopo-settings-card">
      <div class="koopo-setting-row">
        <label class="koopo-toggle">
          <input type="checkbox" id="koopo-setting-enabled" />
          <span><?php esc_html_e('Enable appointment on this listing', 'appointments'); ?></span>
        </label>
      </div>

      <div class="koopo-divider"></div>

      <h3><?php esc_html_e('Business Hours', 'appointments'); ?></h3>
      <p class="koopo-help"><?php esc_html_e('Next: multi-range hours, validation, cloning, breaks, smart tools.', 'appointments'); ?></p>

      <div id="koopo-hours-placeholder" class="koopo-muted">
        <?php esc_html_e('Hours UI coming next.', 'appointments'); ?>
      </div>

      <div class="koopo-actions">
        <button type="button" class="koopo-btn koopo-btn--gold" id="koopo-settings-save"><?php esc_html_e('Save', 'appointments'); ?></button>
      </div>
    </div>
  </div>
</div>
