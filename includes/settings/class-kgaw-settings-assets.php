<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Settings_Assets {

  public static function init() {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_dokan_dashboard'], 30);
  }

  public static function enqueue_frontend() {
    // Only on gd_place single pages (button/modal lives there)
    if (!is_singular('gd_place')) return;

    // Only enqueue if shortcode exists in content OR we assume you added it to template:
    // We'll enqueue if the user is the owner (so normal visitors don’t load settings UI),
    // or if the user is an admin (bypass).
    if (!is_user_logged_in()) return;
    $listing_id = (int) get_the_ID();
    $owner_id = (int) get_post_field('post_author', $listing_id);
    $current_id = (int) get_current_user_id();
    if ($current_id !== $owner_id && !Access::is_admin_bypass($current_id)) return;

    self::enqueue_common($listing_id);
  }

  public static function enqueue_dokan_dashboard() {
    // Dokan dashboard pages
    if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) return;

    // Only load on our custom route (URL contains /koopo-appointments or /koopo-appointment-settings)
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '';
    if (strpos($uri, 'koopo-appointments') === false && strpos($uri, 'koopo-appointment-settings') === false) return;

    self::enqueue_common(0);
  }

  private static function enqueue_common(int $listing_id_or_zero) {
    $ver = '0.1.0';

    wp_enqueue_style(
      'koopo-appt-settings-ui',
      KOOPO_APPT_URL . 'assets/appointments-settings.css',
      [],
      $ver
    );

    wp_enqueue_script(
      'koopo-appt-settings-ui',
      KOOPO_APPT_URL . 'assets/appointments-settings.js',
      ['jquery'],
      $ver,
      true
    );

    wp_localize_script('koopo-appt-settings-ui', 'KOOPO_APPT_SETTINGS', [
      'restUrl' => esc_url_raw(rest_url('koopo/v1')),
      'nonce'   => wp_create_nonce('wp_rest'),
      'listingId' => $listing_id_or_zero,
      'tzDefault' => 'America/Detroit',
    ]);
  }
}
