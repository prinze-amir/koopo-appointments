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

    // User-level overrides (admin-set)
    if (self::user_has_feature_override($vendor_id, $feature_key)) {
      return true;
    }

    return !empty(self::vendor_feature_values($vendor_id)[$feature_key]);
  }

  /** Return the active pack's normalized Koopo entitlement map. */
  public static function vendor_feature_values(int $vendor_id): array {
    if (!$vendor_id || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($vendor_id)) return [];
    if (!Features::dokan_subscriptions_active()) return [];
    $pack_product_id = (int) apply_filters('koopo_get_vendor_pack_id', 0, $vendor_id);
    if (!$pack_product_id) return [];
    $features = get_post_meta($pack_product_id, '_koopo_features', true);
    if (is_string($features)) {
      $decoded = json_decode($features, true);
      if (is_array($decoded)) $features = $decoded;
    }
    return is_array($features) ? $features : [];
  }

  /**
   * Resolve a numeric pack entitlement. -1 represents unlimited.
   * Legacy appointment-enabled packs default to one service profile.
   */
  public static function vendor_feature_limit(int $vendor_id, string $feature_key, int $legacy_default = 0): int {
    if (self::is_admin_bypass($vendor_id)) return -1;
    if (self::user_has_feature_override($vendor_id, 'appointments')) return max(1, $legacy_default);
    if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($vendor_id)) return 0;

    $features = self::vendor_feature_values($vendor_id);
    if (!$features) return max(0, $legacy_default);
    if ('service_profiles' === $feature_key && empty($features['appointments'])) return 0;
    if (array_key_exists($feature_key, $features)) {
      $value = $features[$feature_key];
      if ('unlimited' === $value || -1 === (int) $value) return -1;
      return min(1000, max(0, (int) $value));
    }
    return !empty($features['appointments']) ? max(0, $legacy_default) : 0;
  }

  private static function user_has_feature_override(int $user_id, string $feature_key): bool {
    if (!$user_id || !$feature_key) return false;
    $map = apply_filters('koopo_appt_user_feature_override_keys', [
      'appointments' => '_koopo_feature_appointments',
    ]);
    if (!is_array($map) || empty($map[$feature_key])) return false;
    $meta_key = (string) $map[$feature_key];
    return get_user_meta($user_id, $meta_key, true) === '1';
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
