<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Lightweight global settings for the plugin.
 *
 * Per-listing settings (hours, breaks, etc.) live in Settings_API and are saved as post meta.
 * These are site-wide defaults / behavior toggles.
 */
class Admin_Settings {

  const OPTION_HOLD_MINUTES = 'koopo_appt_hold_minutes';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);

    // Provide a simple, centralized way to control the pending-hold expiration.
    add_filter('koopo_appt_pending_expire_minutes', [__CLASS__, 'filter_hold_minutes'], 10, 1);
  }

  public static function filter_hold_minutes($minutes) {
    $opt = get_option(self::OPTION_HOLD_MINUTES, '');
    $opt = absint($opt);
    if ($opt > 0) return $opt;
    return (int) $minutes;
  }

  public static function menu() {
    add_options_page(
      'Koopo Appointments',
      'Koopo Appointments',
      'manage_options',
      'koopo-appointments-settings',
      [__CLASS__, 'render_page']
    );
  }

  public static function register_settings() {
    register_setting('koopo_appt_settings', self::OPTION_HOLD_MINUTES, [
      'type' => 'integer',
      'sanitize_callback' => [__CLASS__, 'sanitize_hold_minutes'],
      'default' => 10,
    ]);

    add_settings_section(
      'koopo_appt_general',
      'General',
      '__return_false',
      'koopo-appointments-settings'
    );

    add_settings_field(
      self::OPTION_HOLD_MINUTES,
      'Checkout hold (minutes)',
      [__CLASS__, 'field_hold_minutes'],
      'koopo-appointments-settings',
      'koopo_appt_general'
    );
  }

  public static function sanitize_hold_minutes($value) {
    $v = absint($value);
    if ($v < 1) $v = 10;
    if ($v > 120) $v = 120; // safety cap
    return $v;
  }

  public static function field_hold_minutes() {
    $val = absint(get_option(self::OPTION_HOLD_MINUTES, 10));
    if ($val < 1) $val = 10;
    ?>
    <input
      type="number"
      min="1"
      max="120"
      step="1"
      name="<?php echo esc_attr(self::OPTION_HOLD_MINUTES); ?>"
      value="<?php echo esc_attr($val); ?>"
      class="small-text"
    />
    <p class="description">
      How long a selected appointment time is reserved while the customer completes checkout.
      Default is <strong>10</strong> minutes.
    </p>
    <?php
  }

  public static function render_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>Koopo Appointments</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('koopo_appt_settings');
          do_settings_sections('koopo-appointments-settings');
          submit_button();
        ?>
      </form>
    </div>
    <?php
  }
}
