<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Settings_UI_Shortcodes {

  public static function init() {
    add_shortcode('koopo_appointments_settings_button', [__CLASS__, 'button_shortcode']);
  }

  public static function button_shortcode($atts = []) {
    if (!is_user_logged_in() || !is_singular('gd_place')) return '';

    $listing_id = (int) get_the_ID();
    $owner_id = (int) get_post_field('post_author', $listing_id);

    if (get_current_user_id() !== $owner_id && !current_user_can('manage_options')) return '';
    $has_access = current_user_can('manage_options') || Access::vendor_has_feature($owner_id, 'appointments');

    $atts = shortcode_atts([
      'text' => 'Manage Booking Settings',
    ], $atts, 'koopo_appointments_settings_button');

    if (!$has_access) {
      $upgrade_url = apply_filters(
        'koopo_appt_upgrade_url',
        function_exists('dokan_get_navigation_url')
          ? dokan_get_navigation_url('subscription')
          : home_url('/seller-dashboard/subscription/')
      );
      $upgrade_label = apply_filters('koopo_appt_upgrade_label', 'Upgrade to Unlock Booking Tools');
      $upgrade_body = apply_filters(
        'koopo_appt_upgrade_body',
        'Appointments are part of your upgraded plan. Enable bookings to manage services, schedules, and appointments.'
      );
      ob_start();
      ?>
      <div class="koopo-appt-settings-inline koopo-appt-settings-inline--locked" data-listing-id="<?php echo esc_attr($listing_id); ?>">
        <div class="koopo-appt-settings__locked">
          <strong><?php echo esc_html($upgrade_label); ?></strong>
          <p><?php echo esc_html($upgrade_body); ?></p>
          <a class="koopo-appt-settings__cta" href="<?php echo esc_url($upgrade_url); ?>">Upgrade Plan</a>
        </div>
      </div>
      <?php
      return ob_get_clean();
    }

    // This mounts the same settings UI in a modal
    ob_start();
    ?>
    <div class="koopo-appt-settings-inline" data-listing-id="<?php echo esc_attr($listing_id); ?>">
      <div class="koopo-appt-settings__menu">
        <button type="button" class="koopo-appt-settings__menu-toggle">
          <?php echo esc_html($atts['text']); ?>
        </button>
        <div class="koopo-appt-settings__menu-list" aria-hidden="true">
          <a href="#" class="koopo-appt-settings__menu-item" data-action="settings">Booking Settings</a>
          <a href="#" class="koopo-appt-settings__menu-item" data-action="services">Add/Edit Services</a>
          <a href="#" class="koopo-appt-settings__menu-item" data-action="appointments">View Appointments</a>
          <a href="#" class="koopo-appt-settings__menu-item" data-action="calendar">View Calendar</a>
        </div>
      </div>

      <div class="koopo-appt-settings__overlay" aria-hidden="true" data-modal="settings">
        <div class="koopo-appt-settings__modal" role="dialog" aria-modal="true" aria-label="Appointment Settings">
          <button type="button" class="koopo-appt-settings__close" aria-label="Close">&times;</button>
          <h3>Appointment Settings</h3>
          <div class="koopo-appt-settings-mount" data-mode="listing"></div>
        </div>
      </div>

      <div class="koopo-appt-settings__overlay" aria-hidden="true" data-modal="services">
        <div class="koopo-appt-settings__modal" role="dialog" aria-modal="true" aria-label="Services">
          <button type="button" class="koopo-appt-settings__close" aria-label="Close">&times;</button>
          <h3>Services</h3>
          <div class="koopo-appt-services">
            <div class="koopo-appt-services__step" data-step="1">
              <div class="koopo-appt-services__list"></div>
            </div>
            <div class="koopo-appt-services__step" data-step="2">
              <button type="button" class="koopo-appt-services__back">← Back</button>
              <div class="koopo-appt-services__form">
                <input type="text" class="koopo-appt-services__title" placeholder="Service name" />
                <input type="number" class="koopo-appt-services__duration" placeholder="Duration (minutes)" min="1" />
                <input type="number" class="koopo-appt-services__price" placeholder="Price" min="0" step="0.01" />
                <select class="koopo-appt-services__status">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
                <div class="koopo-appt-services__actions">
                  <button type="button" class="koopo-appt-services__save">Save Service</button>
                  <button type="button" class="koopo-appt-services__cancel">Clear</button>
                </div>
                <div class="koopo-appt-services__notice"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="koopo-appt-settings__overlay" aria-hidden="true" data-modal="appointments">
        <div class="koopo-appt-settings__modal" role="dialog" aria-modal="true" aria-label="Upcoming Appointments">
          <button type="button" class="koopo-appt-settings__close" aria-label="Close">&times;</button>
          <h3>Upcoming Appointments</h3>
          <div class="koopo-appt-appointments">
            <div class="koopo-appt-appointments__list"></div>
            <a class="koopo-appt-appointments__all" href="<?php echo esc_url(function_exists('dokan_get_navigation_url') ? dokan_get_navigation_url('koopo-appointments') : home_url('/seller-dashboard/koopo-appointments/')); ?>">
              View All Appointments
            </a>
          </div>
        </div>
      </div>

      <div class="koopo-appt-settings__overlay" aria-hidden="true" data-modal="calendar">
        <div class="koopo-appt-settings__modal" role="dialog" aria-modal="true" aria-label="Appointments Calendar">
          <button type="button" class="koopo-appt-settings__close" aria-label="Close">&times;</button>
          <h3>Appointments Calendar</h3>
          <div class="koopo-appt-calendar">
            <div class="koopo-appt-calendar__header">
              <button type="button" class="koopo-appt-calendar__nav" data-dir="-1">‹</button>
              <div class="koopo-appt-calendar__title"></div>
              <button type="button" class="koopo-appt-calendar__nav" data-dir="1">›</button>
            </div>
            <div class="koopo-appt-calendar__grid"></div>
            <div class="koopo-appt-calendar__list"></div>
            <a class="koopo-appt-appointments__all" href="<?php echo esc_url(function_exists('dokan_get_navigation_url') ? dokan_get_navigation_url('koopo-appointments') : home_url('/seller-dashboard/koopo-appointments/')); ?>">
              View All Appointments
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}
