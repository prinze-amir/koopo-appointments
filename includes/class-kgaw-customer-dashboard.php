<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 23: Customer Dashboard
 * Integrates customer appointments into WooCommerce My Account and provides shortcode
 */
class Customer_Dashboard {

  public static function init(): void {
    // Add My Account endpoint
    add_action('init', [__CLASS__, 'add_endpoints']);
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item'], 20);
    add_action('woocommerce_account_appointments_endpoint', [__CLASS__, 'appointments_content']);
    
    // Register shortcode
    add_shortcode('koopo_my_appointments', [__CLASS__, 'appointments_shortcode']);
    
    // Enqueue assets
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
  }

  /**
   * Add WooCommerce My Account endpoint
   */
  public static function add_endpoints(): void {
    add_rewrite_endpoint('appointments', EP_ROOT | EP_PAGES);
    
    // Flush rewrite rules on activation (done in main plugin file)
  }

  /**
   * Add menu item to My Account navigation
   */
  public static function add_menu_item(array $items): array {
    
    // Insert after 'orders'
    $new_items = [];
    
    foreach ($items as $key => $label) {
      $new_items[$key] = $label;
      
      if ($key === 'orders') {
        $new_items['appointments'] = __('Appointments', 'koopo-appointments');
      }
    }
    
    return $new_items;
  }

  /**
   * Content for My Account appointments endpoint
   */
  public static function appointments_content(): void {
    if (!is_user_logged_in()) {
      echo '<p>' . esc_html__('Please log in to view your appointments.', 'koopo-appointments') . '</p>';
      return;
    }

    self::load_template();
  }

  /**
   * Shortcode: [koopo_my_appointments]
   */
  public static function appointments_shortcode($atts = []): string {
    
    if (!is_user_logged_in()) {
      return '<p>' . esc_html__('Please log in to view your appointments.', 'koopo-appointments') . '</p>';
    }

    ob_start();
    self::load_template();
    return ob_get_clean();
  }

  /**
   * Load the appointments template
   */
  private static function load_template(): void {
    $template_path = KOOPO_APPT_PATH . 'templates/customer/my-appointments.php';
    
    if (file_exists($template_path)) {
      include $template_path;
    } else {
      echo '<p>' . esc_html__('Appointments template not found.', 'koopo-appointments') . '</p>';
    }
  }

  /**
   * Enqueue customer dashboard assets
   */
  public static function enqueue_assets(): void {
    
    // Only load on relevant pages
    if (!is_user_logged_in()) {
      return;
    }

    $load_assets = false;
    $url = wc_get_account_endpoint_url('appointments');
    //is_wc_account_page('appointments') does not work reliably

    // Check if we're on My Account appointments page
    if (is_account_page() && $url === home_url('/my-account-koopo/appointments/')) {
      $load_assets = true;
    }

    // Check if shortcode is present
    global $post;
    if ($post && has_shortcode($post->post_content, 'koopo_my_appointments')) {
      $load_assets = true;
    }
    // Also check if we're in a BuddyBoss/BuddyPress tab
    if (function_exists('bp_is_user') && bp_is_user()) {
      if (function_exists('bp_current_component') && bp_current_component() === 'appointments') {
        $load_assets = true;
      }
    }

    if (!$load_assets) {
      return;
    }

    // Enqueue CSS
    wp_enqueue_style(
      'koopo-customer-dashboard',
      KOOPO_APPT_URL . 'assets/customer-dashboard.css',
      [],
      KOOPO_APPT_VERSION
    );

    // Enqueue JS
    wp_enqueue_script(
      'koopo-customer-dashboard',
      KOOPO_APPT_URL . 'assets/customer-dashboard.js',
      ['jquery'],
      KOOPO_APPT_VERSION,
      true
    );

    // Localize script
    wp_localize_script('koopo-customer-dashboard', 'KOOPO_CUSTOMER', [
      'api_url' => rest_url('koopo/v1'),
      'nonce' => wp_create_nonce('wp_rest'),
      'currency_symbol' => get_woocommerce_currency_symbol(),
      'i18n' => [
        'confirm_cancel' => __('Are you sure you want to cancel this appointment? This action cannot be undone.', 'koopo-appointments'),
        'cancel_success' => __('Appointment cancelled successfully.', 'koopo-appointments'),
        'cancel_error' => __('Failed to cancel appointment. Please try again.', 'koopo-appointments'),
        'reschedule_success' => __('Reschedule request sent. The vendor will contact you soon.', 'koopo-appointments'),
        'loading' => __('Loading...', 'koopo-appointments'),
        'no_bookings' => __('No appointments found.', 'koopo-appointments'),
      ],
    ]);
  }

  /**
   * Flush rewrite rules (call on plugin activation)
   */
  public static function flush_rewrite_rules(): void {
    self::add_endpoints();
    flush_rewrite_rules();
  }
}
