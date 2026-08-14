<?php
/**
 * Plugin Name: Koopo Appointments
 * Description: Appointments for Koopo professionals and GeoDirectory places with WooCommerce/Dokan integration.
 * Version: 0.9.2
 * Author: Koopo
 */

defined('ABSPATH') || exit;

define('KOOPO_APPT_VERSION', '0.9.2');
define('KOOPO_APPT_PATH', plugin_dir_path(__FILE__));
define('KOOPO_APPT_URL', plugin_dir_url(__FILE__));

final class Koopo_Appointments {
  const VERSION = '0.9.2';
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
    $modules['appointments_media'] = [
      'label' => __('Appointment service profiles', 'koopo-appointments'),
      'description' => __('Service-profile portraits and galleries plus provider-private client files uploaded directly to remote storage.', 'koopo-appointments'),
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
    require_once __DIR__ . '/includes/core/class-kgaw-db.php';
    require_once __DIR__ . '/includes/core/class-kgaw-resources.php';
    require_once __DIR__ . '/includes/customer/class-kgaw-customer-dashboard.php';
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-repository.php';
    Koopo_Appointments\DB::create_tables();
    Koopo_Appointments\Calendar_Repository::create_tables();
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
    if (!class_exists('WooCommerce')) return;

    // Core classes
    require_once __DIR__ . '/includes/core/class-kgaw-db.php';
    require_once __DIR__ . '/includes/core/class-kgaw-date-formatter.php';
    require_once __DIR__ . '/includes/core/class-kgaw-bookings.php';
    require_once __DIR__ . '/includes/core/class-kgaw-availability.php';
    require_once __DIR__ . '/includes/core/class-kgaw-features.php';
    require_once __DIR__ . '/includes/core/class-kgaw-access.php';
    require_once __DIR__ . '/includes/core/class-kgaw-resources.php';

    // First-class service professionals. BuddyBoss supplies identity/presentation;
    // provider and resource records remain owned by Koopo Appointments.
    require_once __DIR__ . '/includes/providers/class-kgaw-provider-reviews.php';
    require_once __DIR__ . '/includes/providers/class-kgaw-provider-profiles.php';
    require_once __DIR__ . '/includes/providers/class-kgaw-provider-affiliations.php';
    require_once __DIR__ . '/includes/providers/class-kgaw-service-areas.php';

    // Outbound calendar synchronization. Koopo remains the source of truth.
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-crypto.php';
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-repository.php';
    require_once __DIR__ . '/includes/calendar/providers/class-kgaw-calendar-provider.php';
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-sync.php';
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-busy.php';
    require_once __DIR__ . '/includes/calendar/class-kgaw-calendar-api.php';
    require_once __DIR__ . '/includes/waitlist/class-kgaw-waitlist.php';
    require_once __DIR__ . '/includes/clients/class-kgaw-client-records.php';
    require_once __DIR__ . '/includes/privacy/class-kgaw-privacy.php';

    \Koopo_Appointments\DB::maybe_upgrade();
    \Koopo_Appointments\Calendar_Repository::maybe_upgrade();

    // WooCommerce / cart
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-checkout.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-order-hooks.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-order-display.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-cart.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-checkout-cart.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-product-guard.php';
    require_once __DIR__ . '/includes/woocommerce/class-kgaw-wc-service-product.php';

    // Services
    require_once __DIR__ . '/includes/services/class-kgaw-services-cpt.php';
    require_once __DIR__ . '/includes/services/class-kgaw-service-categories.php';
    require_once __DIR__ . '/includes/services/class-kgaw-services-api.php';
    require_once __DIR__ . '/includes/services/class-kgaw-services-list.php';
    require_once __DIR__ . '/includes/services/class-kgaw-bookable-listings-api.php';

    // UI
    require_once __DIR__ . '/includes/ui/class-kgaw-ui.php';

    // Vendor
    require_once __DIR__ . '/includes/vendor/class-kgaw-vendor-listings-api.php';
    require_once __DIR__ . '/includes/vendor/class-kgaw-vendor-bookings-api.php';

    // Customer
    require_once __DIR__ . '/includes/customer/class-kgaw-customer-bookings-api.php';
    require_once __DIR__ . '/includes/customer/class-kgaw-customer-dashboard.php';
    require_once __DIR__ . '/includes/customer/class-kgaw-myaccount.php';

    // Settings
    require_once __DIR__ . '/includes/settings/class-kgaw-settings-api.php';
    require_once __DIR__ . '/includes/settings/class-kgaw-settings-ui-shortcodes.php';
    require_once __DIR__ . '/includes/settings/class-kgaw-settings-assets.php';

    // Admin
    require_once __DIR__ . '/includes/admin/class-kgaw-admin-settings.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-admin-dashboard.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-analytics-dashboard.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-pack-features-admin.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-place-metabox.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-user-feature-overrides.php';
    require_once __DIR__ . '/includes/admin/class-kgaw-provider-service-admin.php';

    // Dokan
    require_once __DIR__ . '/includes/dokan/class-kgaw-dokan-dashboard.php';
    require_once __DIR__ . '/includes/dokan/class-kgaw-dokan-pack-adapter.php';

    // Notifications
    require_once __DIR__ . '/includes/notifications/class-kgaw-notifications.php';
    require_once __DIR__ . '/includes/notifications/class-kgaw-automated-reminders.php';

    // Refunds
    require_once __DIR__ . '/includes/refunds/class-kgaw-refund-policy.php';
    require_once __DIR__ . '/includes/refunds/class-kgaw-refund-processor.php';

    // Integrations
    require_once __DIR__ . '/includes/integrations/class-kgaw-buddyboss-appointments.php';
    
    Koopo_Appointments\Customer_Bookings_API::init();
    Koopo_Appointments\Customer_Dashboard::init();
    Koopo_Appointments\Dokan_Pack_Adapter::init();
    Koopo_Appointments\Pack_Features_Admin::init();
    Koopo_Appointments\Place_Metabox::init();
    Koopo_Appointments\User_Feature_Overrides::init();
    Koopo_Appointments\Provider_Service_Admin::init();
    Koopo_Appointments\BuddyBoss_Appointments::init();
    Koopo_Appointments\Provider_Profiles::init();
    Koopo_Appointments\Provider_Reviews::init();
    Koopo_Appointments\Provider_Affiliations::init();
    Koopo_Appointments\Service_Areas::init();

    Koopo_Appointments\Service_Categories::init();
    Koopo_Appointments\Settings_Assets::init();
    Koopo_Appointments\Admin_Settings::init();
    Koopo_Appointments\MyAccount::init();
    Koopo_Appointments\Settings_UI_Shortcodes::init();
    Koopo_Appointments\Dokan_Dashboard::init();
    Koopo_Appointments\Settings_API::init();
    Koopo_Appointments\Availability::init();
    Koopo_Appointments\Services_List::init();
    Koopo_Appointments\Bookable_Listings_API::init();
    Koopo_Appointments\Vendor_Listings_API::init();
    Koopo_Appointments\Vendor_Bookings_API::init();
    Koopo_Appointments\UI::init();
    Koopo_Appointments\Cart::init();
    Koopo_Appointments\Checkout_Cart::init();
    Koopo_Appointments\Product_Guard::init();
    Koopo_Appointments\Services_API::init();
    Koopo_Appointments\Services_CPT::init();
    Koopo_Appointments\Bookings::init_cleanup_cron();
    Koopo_Appointments\Bookings::init();
    Koopo_Appointments\Checkout::init();
    Koopo_Appointments\Order_Hooks::init();
    Koopo_Appointments\Order_Display::init();
    Koopo_Appointments\Notifications::init();
    // Admin settings dashboard.
    Koopo_Appointments\Admin_Dashboard::init();
    Koopo_Appointments\Analytics_Dashboard::init();
    Koopo_Appointments\Automated_Reminders::init();
    Koopo_Appointments\Calendar_API::init();
    Koopo_Appointments\Calendar_Sync::init();
    Koopo_Appointments\Calendar_Busy::init();
    Koopo_Appointments\Waitlist::init();
    Koopo_Appointments\Client_Records::init();
    Koopo_Appointments\Privacy::init();

  }
}

Koopo_Appointments::instance();
