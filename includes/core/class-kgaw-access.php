<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Access {

  /**
   * Admin bypass: never restrict admins.
   */
  public static function is_admin_bypass(?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;

    // Super admins (multisite) or admins (single)
    if (function_exists('is_super_admin') && is_super_admin($user_id)) return true;
    if (user_can($user_id, 'manage_options')) return true;

    // Optional: allow dev override
    return (bool) apply_filters('koopo_appt_admin_bypass', false, $user_id);
  }

  /**
   * Vendor feature gate (admin bypass built-in).
   * $vendor_id is the listing owner/vendor being checked.
   */
  public static function vendor_has_feature(int $vendor_id, string $feature_key): bool {
    // Admin bypass always passes
    if (self::is_admin_bypass()) return true;

    // Must be vendor
    if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($vendor_id)) {
      return false;
    }

    // Must have Dokan subscription module active (if it's off, treat as no features)
    if (!Features::dokan_subscriptions_active()) {
      return false;
    }

    // Get vendor's active pack product id (adapter via filter so we can support multiple Dokan versions)
    $pack_product_id = (int) apply_filters('koopo_get_vendor_pack_id', 0, $vendor_id);
    if (!$pack_product_id) return false;

    $features = get_post_meta($pack_product_id, '_koopo_features', true);
    if (is_string($features)) {
      $decoded = json_decode($features, true);
      if (is_array($decoded)) $features = $decoded;
    }
    if (!is_array($features)) $features = [];

    return !empty($features[$feature_key]);
  }

  /**
   * Common REST permission check for vendor-owned listing resources.
   */
  public static function can_manage_listing_feature(int $listing_id, string $feature_key): bool {
    // Admin bypass always passes
    if (self::is_admin_bypass()) return true;

    if (!is_user_logged_in()) return false;

    $current = get_current_user_id();
    $owner_id = (int) get_post_field('post_author', $listing_id);
    if (!$owner_id) return false;

    // Must be the owner (later we can allow delegated managers)
    if ($current !== $owner_id) return false;

    // Must have feature via vendor pack
    return self::vendor_has_feature($owner_id, $feature_key);
  }
}
