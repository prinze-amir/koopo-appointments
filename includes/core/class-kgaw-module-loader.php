<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Explicit module manifest. It preserves the legacy load and initialization order
 * while keeping the plugin entrypoint small and auditable.
 */
final class Module_Loader {
  public static function load_activation(): int {
    return self::load_files([
      'includes/core/class-kgaw-db.php',
      'includes/core/class-kgaw-resources.php',
      'includes/customer/class-kgaw-customer-dashboard.php',
      'includes/calendar/class-kgaw-calendar-repository.php',
      'includes/invitations/class-kgaw-booking-invitations.php',
    ]);
  }

  public static function load_foundation(): int {
    return self::load_files([
      'includes/core/class-kgaw-db.php',
      'includes/core/class-kgaw-date-formatter.php',
      'includes/core/class-kgaw-bookings.php',
      'includes/core/class-kgaw-availability.php',
      'includes/core/class-kgaw-features.php',
      'includes/core/class-kgaw-access.php',
      'includes/core/class-kgaw-resources.php',
      'includes/geocoding/class-kgaw-geocoding-router.php',
      'includes/providers/class-kgaw-provider-reviews.php',
      'includes/providers/class-kgaw-provider-profiles.php',
      'includes/providers/class-kgaw-provider-affiliations.php',
      'includes/providers/class-kgaw-service-areas.php',
      'includes/calendar/class-kgaw-calendar-crypto.php',
      'includes/calendar/class-kgaw-calendar-repository.php',
      'includes/calendar/providers/class-kgaw-calendar-provider.php',
      'includes/calendar/class-kgaw-calendar-sync.php',
      'includes/calendar/class-kgaw-calendar-busy.php',
      'includes/calendar/class-kgaw-calendar-api.php',
      'includes/waitlist/class-kgaw-waitlist.php',
      'includes/clients/class-kgaw-client-records.php',
      'includes/privacy/class-kgaw-privacy.php',
    ]);
  }

  public static function load_features(): int {
    return self::load_files([
      'includes/woocommerce/class-kgaw-checkout.php',
      'includes/woocommerce/class-kgaw-order-hooks.php',
      'includes/woocommerce/class-kgaw-order-display.php',
      'includes/woocommerce/class-kgaw-cart.php',
      'includes/woocommerce/class-kgaw-checkout-cart.php',
      'includes/woocommerce/class-kgaw-product-guard.php',
      'includes/woocommerce/class-kgaw-wc-service-product.php',
      'includes/services/class-kgaw-services-cpt.php',
      'includes/services/class-kgaw-service-categories.php',
      'includes/onboarding/class-kgaw-provider-onboarding.php',
      'includes/services/class-kgaw-services-api.php',
      'includes/services/class-kgaw-services-list.php',
      'includes/services/class-kgaw-bookable-listings-api.php',
      'includes/ui/class-kgaw-ui.php',
      'includes/vendor/class-kgaw-vendor-listings-api.php',
      'includes/vendor/class-kgaw-vendor-bookings-api.php',
      'includes/customer/class-kgaw-customer-bookings-api.php',
      'includes/customer/class-kgaw-customer-dashboard.php',
      'includes/customer/class-kgaw-myaccount.php',
      'includes/settings/class-kgaw-settings-api.php',
      'includes/settings/class-kgaw-settings-ui-shortcodes.php',
      'includes/settings/class-kgaw-settings-assets.php',
      'includes/admin/class-kgaw-admin-settings.php',
      'includes/admin/class-kgaw-admin-dashboard.php',
      'includes/admin/class-kgaw-analytics-dashboard.php',
      'includes/admin/class-kgaw-pack-features-admin.php',
      'includes/admin/class-kgaw-place-metabox.php',
      'includes/admin/class-kgaw-user-feature-overrides.php',
      'includes/admin/class-kgaw-provider-service-admin.php',
      'includes/dokan/class-kgaw-dokan-dashboard.php',
      'includes/dokan/class-kgaw-dokan-pack-adapter.php',
      'includes/notifications/class-kgaw-notification-delivery.php',
      'includes/notifications/class-kgaw-sms-compliance.php',
      'includes/notifications/class-kgaw-transactional-sms.php',
      'includes/notifications/class-kgaw-sms-usage.php',
      'includes/notifications/class-kgaw-sms-delivery-receipts.php',
      'includes/notifications/class-kgaw-sms-provider.php',
      'includes/notifications/class-kgaw-notifications.php',
      'includes/notifications/class-kgaw-automated-reminders.php',
      'includes/notifications/class-kgaw-appointment-messaging.php',
      'includes/invitations/class-kgaw-booking-invitations.php',
      'includes/refunds/class-kgaw-refund-policy.php',
      'includes/refunds/class-kgaw-refund-processor.php',
      'includes/integrations/class-kgaw-buddyboss-appointments.php',
      'includes/integrations/class-kgaw-buddyboss-service-activity.php',
    ]);
  }

  public static function initialize(): int {
    $initializers = [
      [Customer_Bookings_API::class, 'init'],
      [Customer_Dashboard::class, 'init'],
      [Dokan_Pack_Adapter::class, 'init'],
      [Pack_Features_Admin::class, 'init'],
      [Place_Metabox::class, 'init'],
      [User_Feature_Overrides::class, 'init'],
      [Provider_Service_Admin::class, 'init'],
      [BuddyBoss_Appointments::class, 'init'],
      [BuddyBoss_Service_Activity::class, 'init'],
      [Provider_Profiles::class, 'init'],
      [Provider_Reviews::class, 'init'],
      [Provider_Affiliations::class, 'init'],
      [Service_Areas::class, 'init'],
      [Service_Categories::class, 'init'],
      [Provider_Onboarding::class, 'init'],
      [Settings_Assets::class, 'init'],
      [Admin_Settings::class, 'init'],
      [MyAccount::class, 'init'],
      [Settings_UI_Shortcodes::class, 'init'],
      [Dokan_Dashboard::class, 'init'],
      [Settings_API::class, 'init'],
      [Availability::class, 'init'],
      [Services_List::class, 'init'],
      [Bookable_Listings_API::class, 'init'],
      [Vendor_Listings_API::class, 'init'],
      [Vendor_Bookings_API::class, 'init'],
      [UI::class, 'init'],
      [Cart::class, 'init'],
      [Checkout_Cart::class, 'init'],
      [Product_Guard::class, 'init'],
      [Services_API::class, 'init'],
      [Services_CPT::class, 'init'],
      [Bookings::class, 'init_cleanup_cron'],
      [Bookings::class, 'init'],
      [Checkout::class, 'init'],
      [Order_Hooks::class, 'init'],
      [Order_Display::class, 'init'],
      [Notifications::class, 'init'],
      [SMS_Provider::class, 'init'],
      [SMS_Delivery_Receipts::class, 'init'],
      [Appointment_Messaging::class, 'init'],
      [Booking_Invitations::class, 'init'],
      [Admin_Dashboard::class, 'init'],
      [Analytics_Dashboard::class, 'init'],
      [Automated_Reminders::class, 'init'],
      [Calendar_API::class, 'init'],
      [Calendar_Sync::class, 'init'],
      [Calendar_Busy::class, 'init'],
      [Waitlist::class, 'init'],
      [Client_Records::class, 'init'],
      [Privacy::class, 'init'],
    ];

    foreach ($initializers as $initializer) {
      if (!is_callable($initializer)) {
        Logger::error('module_initializer_missing', ['class' => $initializer[0], 'method' => $initializer[1]]);
        throw new \RuntimeException('Koopo Appointments initializer is not callable: ' . $initializer[0] . '::' . $initializer[1]);
      }
      call_user_func($initializer);
    }
    return count($initializers);
  }

  private static function load_files(array $files): int {
    $root = defined('KOOPO_APPT_PATH') ? KOOPO_APPT_PATH : dirname(__DIR__, 2) . '/';
    foreach ($files as $relative_path) {
      $path = $root . $relative_path;
      if (!is_readable($path)) {
        Logger::error('module_file_missing', ['module' => $relative_path]);
        throw new \RuntimeException('Koopo Appointments module is not readable: ' . $relative_path);
      }
      require_once $path;
    }
    return count($files);
  }
}
