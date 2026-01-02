<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Dokan_Pack_Adapter {

  public static function init() {
    add_filter('koopo_get_vendor_pack_id', [__CLASS__, 'get_vendor_pack_id'], 10, 2);
  }

  public static function get_vendor_pack_id($pack_id, $vendor_id) {
    $vendor_id = (int) $vendor_id;
    if ($pack_id) return (int) $pack_id;

    // Try known Dokan Pro subscription module APIs if present.
    // (We keep it defensive so admin never sees fatal errors.)
    try {
      // Some versions store vendor subscription pack as user meta
      $meta_keys = [
        'product_package_id',
        'dokan_subscription_pack_id',
        'dokan_product_subscription_pack_id',
      ];

      foreach ($meta_keys as $k) {
        $val = (int) get_user_meta($vendor_id, $k, true);
        if ($val) return $val;
      }

      // If Dokan has a helper for seller subscription, use it when available
      if (function_exists('dokan()->vendor->get')) {
        // Not reliable for pack id, but kept as safe stub.
      }

    } catch (\Throwable $e) {
      // swallow
    }

    return 0;
  }
}
