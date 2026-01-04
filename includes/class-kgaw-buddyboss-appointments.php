<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Updated BuddyBoss/BuddyPress Integration - Uses Commit 23 Customer Dashboard
 * Replaces old koopo-appointments endpoint with new customer dashboard template
 */
class BuddyBoss_Appointments {

  public static function init(): void {
    // Remove old endpoint registration
    // add_action('bp_setup_nav', [__CLASS__, 'setup_nav'], 100);
    
    // Use new registration
    add_action('bp_setup_nav', [__CLASS__, 'setup_appointments_tab'], 100);
  }

  /**
   * Register Appointments tab in BuddyBoss/BuddyPress profile
   */
  public static function setup_appointments_tab(): void {
    
    if (!function_exists('bp_core_new_nav_item')) {
      return;
    }

    $user_id = bp_displayed_user_id();
    
    // Only show to the profile owner
    if ($user_id !== get_current_user_id()) {
      return;
    }

    // Register main nav item
    bp_core_new_nav_item([
      'name'                => __('Appointments', 'koopo-appointments'),
      'slug'                => 'appointments',
      'screen_function'     => [__CLASS__, 'appointments_screen'],
      'position'            => 75,
      'default_subnav_slug' => 'my-appointments',
    ]);

    // Register subnav item
    bp_core_new_subnav_item([
      'name'            => __('My Appointments', 'koopo-appointments'),
      'slug'            => 'my-appointments',
      'parent_url'      => bp_core_get_user_domain($user_id) . 'appointments/',
      'parent_slug'     => 'appointments',
      'screen_function' => [__CLASS__, 'appointments_screen'],
      'position'        => 10,
    ]);
  }

  /**
   * Screen function for appointments tab
   */
  public static function appointments_screen(): void {
    add_action('bp_template_content', [__CLASS__, 'appointments_content']);
    
    // Load BuddyPress template
    bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
  }

  /**
   * Content for appointments tab - uses new customer dashboard template
   */
  public static function appointments_content(): void {
    
    if (!is_user_logged_in()) {
      echo '<p>' . esc_html__('Please log in to view your appointments.', 'koopo-appointments') . '</p>';
      return;
    }

    // Load the same template used by WooCommerce My Account and shortcode
    $template_path = KOOPO_APPT_PATH . 'templates/customer/my-appointments.php';
    
    if (file_exists($template_path)) {
      // Wrap in BuddyBoss-friendly container
      echo '<div class="koopo-buddyboss-appointments-wrapper">';
      include $template_path;
      echo '</div>';
    } else {
      echo '<p>' . esc_html__('Appointments template not found.', 'koopo-appointments') . '</p>';
    }
  }

  /**
   * DEPRECATED: Old setup_nav function (kept for reference, not used)
   * Remove this in future versions
   */
  public static function OLD_setup_nav(): void {
    // This function is replaced by setup_appointments_tab()
    // Keeping for backward compatibility documentation only
  }
}
