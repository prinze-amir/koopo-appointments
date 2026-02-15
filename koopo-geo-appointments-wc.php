<?php
/**
 * Plugin Name: Koopo Appointments
 * Description: GeoDirectory Addon For Appointments with WooCommerce/Dokan Integration.
 * Version: 0.1.8
 * Author: Koopo
 */

defined('ABSPATH') || exit;

define('KOOPO_APPT_VERSION', '0.1.8');
define('KOOPO_APPT_PATH', plugin_dir_path(__FILE__));
define('KOOPO_APPT_URL', plugin_dir_url(__FILE__));

final class Koopo_Appointments {
  const VERSION = '0.1.8';
  const SLUG = 'koopo-geo-appointments-wc';

  private static $instance = null;

  public static function instance() {
    if (null === self::$instance) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_action('plugins_loaded', [$this, 'boot'], 20);
    register_activation_hook(__FILE__, [$this, 'activate']);
    register_deactivation_hook(__FILE__, [$this, 'deactivate']);
  }

  public function activate() {
    require_once __DIR__ . '/includes/core/class-kgaw-db.php';
    Koopo_Appointments\DB::create_tables();
  }

  public function deactivate() {
    wp_clear_scheduled_hook('koopo_appt_cleanup_pending');
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

    \Koopo_Appointments\DB::maybe_upgrade();

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
    Koopo_Appointments\BuddyBoss_Appointments::init();

    Koopo_Appointments\Service_Categories::init();
    Koopo_Appointments\Settings_Assets::init();
    Koopo_Appointments\Admin_Settings::init();
    Koopo_Appointments\MyAccount::init();
    Koopo_Appointments\Settings_UI_Shortcodes::init();
    Koopo_Appointments\Dokan_Dashboard::init();
    Koopo_Appointments\Settings_API::init();
    Koopo_Appointments\Availability::init();
    Koopo_Appointments\Services_List::init();
    Koopo_Appointments\Vendor_Listings_API::init();
    Koopo_Appointments\Vendor_Bookings_API::init();
    Koopo_Appointments\UI::init();
    Koopo_Appointments\Cart::init();
    Koopo_Appointments\Checkout_Cart::init();
    Koopo_Appointments\Product_Guard::init();
    Koopo_Appointments\Services_API::init();
    Koopo_Appointments\Services_CPT::init();
    Koopo_Appointments\Service_Categories::init();
    Koopo_Appointments\Bookings::init_cleanup_cron();
    Koopo_Appointments\Bookings::init();
    Koopo_Appointments\Checkout::init();
    Koopo_Appointments\Order_Hooks::init();
    Koopo_Appointments\Order_Display::init();
    Koopo_Appointments\Notifications::init();
//admin settings dashboard
    Koopo_Appointments\Admin_Dashboard::init();
    Koopo_Appointments\Analytics_Dashboard::init();
    Koopo_Appointments\Automated_Reminders::init();
    // Commit 20: Enhanced Refund Tooling
    //Koopo_Appointments\Refund_Policy::init();
    //Koopo_Appointments\Refund_Processor::init();

  }
}

Koopo_Appointments::instance();
