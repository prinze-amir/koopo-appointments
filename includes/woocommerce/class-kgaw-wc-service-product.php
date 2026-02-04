<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class WC_Service_Product {

  /**
   * Creates or updates a WC product for a given service.
   * Product is owned by the service author (vendor), so Dokan attributes it correctly.
   */
  public static function create_or_update_for_service(int $service_id): int {

    $service = get_post($service_id);
    if (!$service || $service->post_type !== Services_CPT::POST_TYPE) {
      return 0;
    }

    $vendor_id  = (int) $service->post_author;
    $title      = $service->post_title;
    // Canonical meta keys (with backwards-compat fallback)
    $price = get_post_meta($service_id, Services_API::META_PRICE, true);
    if ($price === '' || $price === null) {
      $price = get_post_meta($service_id, '_koopo_price', true);
    }
    $price = (float) $price;

    $duration = get_post_meta($service_id, Services_API::META_DURATION, true);
    if ($duration === '' || $duration === null) {
      $duration = get_post_meta($service_id, '_koopo_duration_minutes', true);
    }
    $duration = (int) $duration;

    $listing_id = get_post_meta($service_id, Services_API::META_LISTING_ID, true);
    if ($listing_id === '' || $listing_id === null) {
      $listing_id = get_post_meta($service_id, '_koopo_listing_id', true);
    }
    $listing_id = (int) $listing_id;

    $existing_product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);

    // If product exists and belongs to this service, update it
    if ($existing_product_id && get_post_meta($existing_product_id, '_koopo_service_id', true) == $service_id) {
      self::update_product($existing_product_id, $vendor_id, $title, $price, $duration, $listing_id);
      return $existing_product_id;
    }

    // Otherwise create a new product
    $product_id = wp_insert_post([
      'post_type'   => 'product',
      'post_status' => 'publish',
      'post_title'  => $title,
      'post_author' => $vendor_id,
    ], true);

    if (is_wp_error($product_id)) return 0;

    // Ensure it’s virtual + hidden from catalog/search
    update_post_meta($product_id, '_virtual', 'yes');
    update_post_meta($product_id, '_sold_individually', 'yes');
    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);

    // Linkage
    update_post_meta($product_id, '_koopo_service_id', $service_id);
    if ($listing_id) update_post_meta($product_id, '_koopo_listing_id', $listing_id);
    update_post_meta($product_id, '_koopo_service_duration_minutes', $duration);

    // Service-meta mirrors on product (handy for debugging/reporting)
    update_post_meta($product_id, Services_API::META_LISTING_ID, $listing_id);
    update_post_meta($product_id, Services_API::META_DURATION, $duration);
    update_post_meta($product_id, Services_API::META_PRICE, $price);

    // Hide from shop/catalog
    wp_set_object_terms($product_id, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility', false);

    // Tax handling (default non-taxable unless filter says otherwise)
    self::apply_tax_status($product_id, $service_id, $listing_id);

    // Store reverse link
    update_post_meta($service_id, '_koopo_wc_product_id', $product_id);

    return (int) $product_id;
  }

  private static function update_product(int $product_id, int $vendor_id, string $title, float $price, int $duration, int $listing_id): void {
    // Keep author aligned with vendor (Dokan cares)
    wp_update_post([
      'ID' => $product_id,
      'post_title' => $title,
      'post_author' => $vendor_id,
    ]);

    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_koopo_service_duration_minutes', $duration);
    if ($listing_id) update_post_meta($product_id, '_koopo_listing_id', $listing_id);

    // Keep hidden
    wp_set_object_terms($product_id, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility', false);
    update_post_meta($product_id, '_virtual', 'yes');
    update_post_meta($product_id, '_sold_individually', 'yes');

    // Keep tax status aligned with listing/state rules
    self::apply_tax_status($product_id, (int) get_post_meta($product_id, '_koopo_service_id', true), $listing_id);
  }

  private static function apply_tax_status(int $product_id, int $service_id, int $listing_id): void {
    $listing_state = self::get_listing_state($listing_id);
    $is_taxable = (bool) apply_filters('koopo_appt_service_is_taxable', false, $service_id, $listing_id, $listing_state);
    update_post_meta($product_id, '_tax_status', $is_taxable ? 'taxable' : 'none');
    if ($is_taxable) {
      $tax_class = (string) apply_filters('koopo_appt_service_tax_class', '', $service_id, $listing_id, $listing_state);
      update_post_meta($product_id, '_tax_class', $tax_class);
    } else {
      update_post_meta($product_id, '_tax_class', '');
    }
  }

  private static function get_listing_state(int $listing_id): string {
    if (!$listing_id) return '';
    $keys = ['state', 'region', 'gd_region', 'geodir_region', 'gd_state', 'geodir_state'];
    foreach ($keys as $key) {
      $val = get_post_meta($listing_id, $key, true);
      if (is_string($val) && $val !== '') {
        return $val;
      }
    }
    return '';
  }
}
