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

    $atts = shortcode_atts([
      'text' => 'Manage Booking Settings',
    ], $atts, 'koopo_appointments_settings_button');

    // This mounts the same settings UI in a modal
    ob_start();
    ?>
    <div class="koopo-appt-settings-inline" data-listing-id="<?php echo esc_attr($listing_id); ?>">
      <button type="button" class="koopo-appt-settings__open">
        <?php echo esc_html($atts['text']); ?>
      </button>

      <div class="koopo-appt-settings__overlay" aria-hidden="true">
        <div class="koopo-appt-settings__modal" role="dialog" aria-modal="true" aria-label="Appointment Settings">
          <button type="button" class="koopo-appt-settings__close" aria-label="Close">&times;</button>
          <h3>Appointment Settings</h3>
          <div class="koopo-appt-settings-mount" data-mode="listing"></div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}
