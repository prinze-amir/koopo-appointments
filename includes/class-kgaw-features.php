<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Features {

  public static function wc_subscriptions_active(): bool {
    // WooCommerce Subscriptions exposes WC_Subscriptions class + wcs_* functions
    if (class_exists('\WC_Subscriptions')) return true;
    if (function_exists('wcs_get_users_subscriptions')) return true;
    if (defined('WC_SUBSCRIPTIONS_VERSION')) return true;
    return false;
  }

  public static function dokan_subscriptions_active(): bool {
    // Dokan Product Subscription module class/function presence varies by version
    if (defined('DOKAN_PRODUCT_SUBSCRIPTION_VERSION')) return true;
    if (class_exists('\WeDevs\DokanPro\Modules\Subscription\Module')) return true;
    if (function_exists('dokan_pro')) return true; // broad but ok for gating Dokan Pro modules
    return false;
  }

  public static function memberships_enabled(): bool {
    // Our feature should require:
    // - WooCommerce Subscriptions (entitlement source)
    // - Dokan subscription module (vendor UX + selling plans)
    // If you want to allow WCS without Dokan module, we can loosen this.
    return self::wc_subscriptions_active() && self::dokan_subscriptions_active();
  }
}
