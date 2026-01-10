<?php defined('ABSPATH') || exit; ?>
<div class="dokan-dashboard-wrap">
   <?php
            do_action( 'dokan_dashboard_content_before' );
    ?>
  <div class="dokan-dashboard-content koopo-vendor-page">
    <header class="koopo-vendor-header">
      <h2><?php esc_html_e('Services', 'appointments'); ?></h2>
      <div class="koopo-vendor-header__right">
        <select id="koopo-listing-picker" class="koopo-input">
          <option value=""><?php esc_html_e('Select listing…', 'appointments'); ?></option>
        </select>
        <button type="button" class="koopo-btn koopo-btn--gold" id="koopo-add-service">
          <?php esc_html_e('Add Service', 'appointments'); ?>
        </button>
      </div>
    </header>

    <div class="koopo-grid" id="koopo-services-grid">
      <div class="koopo-card koopo-muted"><?php esc_html_e('Pick a listing to load services.', 'appointments'); ?></div>
    </div>

    <div class="koopo-modal" id="koopo-service-modal" style="display:none;">
      <div class="koopo-modal__card koopo-modal__card--wide">
        <div class="koopo-modal__loading" style="display:none;">
          <div class="koopo-spinner"></div>
          <div class="koopo-modal__loading-text">Loading...</div>
        </div>
        <button class="koopo-modal__close" type="button">&times;</button>
        <h3 id="koopo-service-modal-title"><?php esc_html_e('Edit Service', 'appointments'); ?></h3>

        <div class="koopo-form-grid">
          <label class="koopo-label">
            <?php esc_html_e('Service Name', 'appointments'); ?>
            <input type="text" class="koopo-input" id="koopo-service-name" />
          </label>

          <label class="koopo-label">
            <?php esc_html_e('Description', 'appointments'); ?>
            <input type="text" class="koopo-input" id="koopo-service-description" />
          </label>

          <label class="koopo-label">
            <?php esc_html_e('Color', 'appointments'); ?>
            <input type="color" class="koopo-input koopo-input--color" id="koopo-service-color" value="#F4B400" />
          </label>

          <label class="koopo-label">
            <?php esc_html_e('Status', 'appointments'); ?>
            <select class="koopo-input" id="koopo-service-status">
              <option value="active"><?php esc_html_e('Active', 'appointments'); ?></option>
              <option value="inactive"><?php esc_html_e('Inactive', 'appointments'); ?></option>
            </select>
          </label>

          <label class="koopo-toggle koopo-label--full">
            <input type="checkbox" id="koopo-service-addon" />
            <span><?php esc_html_e('Enable as Add-on', 'appointments'); ?></span>
            <small><?php esc_html_e('Add-on services can be attached to a booking during manual scheduling.', 'appointments'); ?></small>
          </label>

          <label class="koopo-label koopo-label--full">
            <?php esc_html_e('Category', 'appointments'); ?>
            <select class="koopo-input" id="koopo-service-categories">
              <option value=""><?php esc_html_e('Loading categories...', 'appointments'); ?></option>
            </select>
            <small class="koopo-help"><?php esc_html_e('Select a category for tax tagging purposes.', 'appointments'); ?></small>
          </label>

          <div class="koopo-section-title"><?php esc_html_e('Service Duration & Price', 'appointments'); ?></div>

          <label class="koopo-label">
            <?php esc_html_e('Duration (minutes)', 'appointments'); ?>
            <input type="number" min="0" step="5" class="koopo-input" id="koopo-service-duration" />
          </label>

          <label class="koopo-label">
            <?php esc_html_e('Charge Amount', 'appointments'); ?>
            <input type="number" min="0" step="0.01" class="koopo-input" id="koopo-service-price" />
          </label>

          <label class="koopo-label koopo-label--full">
            <?php esc_html_e('Custom Price Display (Optional)', 'appointments'); ?>
            <input type="text" class="koopo-input" id="koopo-service-price-label"
                   placeholder="<?php esc_attr_e('$4/hour, From $50, Free consultation', 'appointments'); ?>" />
            <small class="koopo-help"><?php esc_html_e('Display only; does not affect checkout price.', 'appointments'); ?></small>
          </label>

          <div class="koopo-divider"></div>

          <div class="koopo-section-title"><?php esc_html_e('Buffers', 'appointments'); ?></div>

          <label class="koopo-label">
            <?php esc_html_e('Buffer Before (minutes)', 'appointments'); ?>
            <input type="number" min="0" step="5" class="koopo-input" id="koopo-service-buffer-before" />
          </label>

          <label class="koopo-label">
            <?php esc_html_e('Buffer After (minutes)', 'appointments'); ?>
            <input type="number" min="0" step="5" class="koopo-input" id="koopo-service-buffer-after" />
          </label>

          <div class="koopo-divider"></div>

          <label class="koopo-toggle koopo-label--full">
            <input type="checkbox" id="koopo-service-instant" />
            <span><?php esc_html_e('Instant Booking', 'appointments'); ?></span>
            <small><?php esc_html_e('Allow customers to confirm immediately (approval not required).', 'appointments'); ?></small>
          </label>
        </div>

        <div class="koopo-modal__footer koopo-modal__footer--between">
          <button type="button" class="koopo-btn koopo-btn--danger" id="koopo-service-delete"><?php esc_html_e('Trash', 'appointments'); ?></button>
          <div>
            <button type="button" class="koopo-btn" id="koopo-service-cancel"><?php esc_html_e('Back', 'appointments'); ?></button>
            <button type="button" class="koopo-btn koopo-btn--gold" id="koopo-service-save"><?php esc_html_e('Save Changes', 'appointments'); ?></button>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>
