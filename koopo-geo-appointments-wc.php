<?php
/**
 * Plugin Name: Koopo Appointments
 * Description: Appointments for Koopo professionals and GeoDirectory places with WooCommerce/Dokan integration.
 * Version: 0.11.0
 * Author: Koopo
 */

defined('ABSPATH') || exit;

define('KOOPO_APPT_VERSION', '0.11.0');
define('KOOPO_APPT_PATH', plugin_dir_path(__FILE__));
define('KOOPO_APPT_URL', plugin_dir_url(__FILE__));

require_once KOOPO_APPT_PATH . 'includes/core/class-kgaw-logger.php';
require_once KOOPO_APPT_PATH . 'includes/core/class-kgaw-module-loader.php';

final class Koopo_Appointments {
  const VERSION = '0.11.0';
  const SLUG = 'koopo-geo-appointments-wc';

  private static $instance = null;

  public static function instance() {
    if (null === self::$instance) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_filter('koopo_media_gateway_modules', [$this, 'register_media_gateway_module']);
    add_filter('koopo_media_gateway_projection_contexts', [$this, 'register_projection_context']);
    add_action('koopo_media_gateway_register_adapters', [$this, 'register_media_gateway_adapter'], 10, 5);
    add_action('plugins_loaded', [$this, 'boot'], 20);
    register_activation_hook(__FILE__, [$this, 'activate']);
    register_deactivation_hook(__FILE__, [$this, 'deactivate']);
  }

  public function register_media_gateway_module(array $modules): array {
    $translate = did_action('init');
    $modules['appointments_media'] = [
      'label' => $translate ? __('Appointment service profiles', 'koopo-appointments') : 'Appointment service profiles',
      'description' => $translate ? __('Service-profile portraits and galleries plus provider-private client files uploaded directly to remote storage.', 'koopo-appointments') : 'Service-profile portraits and galleries plus provider-private client files uploaded directly to remote storage.',
      'contexts' => ['profile'],
      'post_types' => ['koopo_provider'],
      'media_kinds' => ['image', 'document'],
      'adapter_status' => 'available',
      'default_enabled' => 0,
    ];
    return $modules;
  }

  public function register_projection_context(array $contexts): array {
    $contexts[] = 'profile';
    return $contexts;
  }

  public function register_media_gateway_adapter($adapters, $policy, $client, $coordinator, $projector): void {
    unset($client);
    if (!interface_exists('Koopo_Media_Gateway_Adapter') || !is_object($adapters) || !is_object($projector)) return;
    require_once __DIR__ . '/includes/media/class-kgaw-service-profile-media-adapter.php';
    $adapters->register(new \Koopo_Appointments\Service_Profile_Media_Adapter($coordinator, $policy, $projector));
  }

  public function activate() {
    Koopo_Appointments\Module_Loader::load_activation();
    Koopo_Appointments\DB::create_tables();
    Koopo_Appointments\Calendar_Repository::create_tables();
    Koopo_Appointments\Booking_Invitations::rewrite();
    Koopo_Appointments\Customer_Dashboard::flush_rewrite_rules();
  }

  public function deactivate() {
    wp_clear_scheduled_hook('koopo_appt_cleanup_pending');
    wp_clear_scheduled_hook('koopo_appt_calendar_reconcile');
    wp_clear_scheduled_hook('koopo_appt_calendar_refresh_busy');
    wp_clear_scheduled_hook('koopo_appt_waitlist_expire_offers');
    wp_clear_scheduled_hook('koopo_appt_send_intake_request');
  }

  public function boot() {
    if (!class_exists('WooCommerce')) {
      Koopo_Appointments\Logger::warning('boot_dependency_missing', ['dependency' => 'WooCommerce']);
      return;
    }

    $module_count = Koopo_Appointments\Module_Loader::load_foundation();

    \Koopo_Appointments\DB::maybe_upgrade();
    \Koopo_Appointments\Calendar_Repository::maybe_upgrade();
    $module_count += Koopo_Appointments\Module_Loader::load_features();
    $initializer_count = Koopo_Appointments\Module_Loader::initialize();
    Koopo_Appointments\Logger::debug('boot_complete', [
      'version' => self::VERSION,
      'module_count' => $module_count,
      'initializer_count' => $initializer_count,
    ]);
  }
}

Koopo_Appointments::instance();
