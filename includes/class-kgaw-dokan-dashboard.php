<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Dokan_Dashboard {

  public static function init() {
    // Only run if Dokan is active
    if (!function_exists('dokan_get_navigation_url')) return;

    
    add_filter('dokan_query_var_filter', [__CLASS__, 'register_query_vars']);
    add_filter('dokan_get_dashboard_nav', [__CLASS__, 'add_nav_items'], 20);
    add_action('dokan_load_custom_template', [__CLASS__, 'load_templates'], 20);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
  }

  public static function register_query_vars($vars) {
    $vars[] = 'koopo-appointments';
    $vars[] = 'koopo-services';
    $vars[] = 'koopo-appointment-settings';
    return $vars;
  }

  public static function add_nav_items($urls) {
    $urls['appointments'] = [
      'title' => __('Appointments', 'appointments'),
      'icon'  => '<i class="fas fa-calendar-check"></i>',
      'url'   => dokan_get_navigation_url('koopo-appointments'),
      'pos'   => 55,
      'submenu' => [
        'koopo-appointments' => [
          'title' => __('My Appointments', 'appointments'),
          'url'   => dokan_get_navigation_url('koopo-appointments'),
          'pos'   => 10,
        ],
        'koopo-services' => [
          'title' => __('Services', 'appointments'),
          'url'   => dokan_get_navigation_url('koopo-services'),
          'pos'   => 20,
        ],
        'koopo-appointment-settings' => [
          'title' => __('Appointment Settings', 'appointments'),
          'url'   => dokan_get_navigation_url('koopo-appointment-settings'),
          'pos'   => 30,
        ],
      ],
    ];

    return $urls;
  }

public static function load_templates($query_vars) {
    if (!is_array($query_vars)) return;

    if (isset($query_vars['koopo-appointments'])) {
      self::load('appointments.php'); return;
    }
    if (isset($query_vars['koopo-services'])) {
      self::load('services.php'); return;
    }
    if (isset($query_vars['koopo-appointment-settings'])) {
      self::load('settings.php'); return;
    }
  }

  private static function load(string $template_file): void {
    $file = KOOPO_APPT_PATH . 'templates/dokan/' . $template_file;
    if (file_exists($file)) include $file;
  }

  public static function enqueue_assets(): void {
    if (!function_exists('dokan_is_seller_dashboard')) return;
    if (!dokan_is_seller_dashboard()) return;

    // Load only on our Koopo sub-pages
    global $wp_query;
    $is_koopo = isset($wp_query->query_vars['koopo-appointments'])
      || isset($wp_query->query_vars['koopo-services'])
      || isset($wp_query->query_vars['koopo-appointment-settings']);

    if (!$is_koopo) return;

    wp_enqueue_style('koopo-appt-vendor', KOOPO_APPT_URL . 'assets/vendor.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-appt-vendor', KOOPO_APPT_URL . 'assets/vendor.js', ['jquery'], KOOPO_APPT_VERSION, true);

    wp_localize_script('koopo-appt-vendor', 'KOOPO_APPT_VENDOR', [
      'rest' => esc_url_raw(rest_url('koopo/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'userId' => get_current_user_id(),
      'admin_url' => admin_url(),
      'orders_url' => function_exists('dokan_get_navigation_url')
        ? dokan_get_navigation_url('orders')
        : home_url('/seller-dashboard/orders/'),
      'orders_nonce' => wp_create_nonce('dokan_view_order'),
      'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
    ]);

    // status badges reused (colors)
    wp_enqueue_style('koopo-appt-badges', KOOPO_APPT_URL . 'assets/badges.css', [], KOOPO_APPT_VERSION);
  }
}
