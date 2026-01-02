<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Product_Guard {

  public static function init() {
    // Fires whenever a product object is saved via WooCommerce CRUD
    add_action('woocommerce_admin_process_product_object', [__CLASS__, 'enforce_on_wc_admin_save'], 20, 1);

    // Fires on generic post save (covers Dokan + other flows)
    add_action('save_post_product', [__CLASS__, 'enforce_on_save_post'], 20, 3);

    // Dokan-specific save hooks (extra belt & suspenders)
    add_action('dokan_product_updated', [__CLASS__, 'enforce_on_dokan_updated'], 20, 2);
    add_action('dokan_new_product_added', [__CLASS__, 'enforce_on_dokan_new'], 20, 2);

    // If a linked service-product is trashed/deleted directly, disable the service to avoid phantom services.
    add_action('wp_trash_post', [__CLASS__, 'handle_product_trashed'], 20, 1);
    add_action('before_delete_post', [__CLASS__, 'handle_product_deleted'], 20, 1);
  }

  public static function is_koopo_service_product(int $product_id): bool {
    return (bool) get_post_meta($product_id, '_koopo_service_id', true);
  }

  public static function enforce_hidden(int $product_id): void {
    if (!$product_id || !self::is_koopo_service_product($product_id)) return;

    // Always keep these service products virtual + sold individually
    update_post_meta($product_id, '_virtual', 'yes');
    update_post_meta($product_id, '_sold_individually', 'yes');

    // Force hidden visibility using WC product_visibility taxonomy
    wp_set_object_terms(
      $product_id,
      ['exclude-from-catalog', 'exclude-from-search'],
      'product_visibility',
      false
    );

    // Also force the legacy catalog visibility value (some UIs still respect this)
    // Valid values: visible | catalog | search | hidden
    update_post_meta($product_id, '_visibility', 'hidden');

    // Prevent accidental “featured” exposures
    delete_post_meta($product_id, '_featured');
  }

  public static function enforce_on_wc_admin_save(\WC_Product $product): void {
    $product_id = $product->get_id();
    self::enforce_hidden($product_id);
  }

  public static function enforce_on_save_post($post_id, $post, $update): void {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    self::enforce_hidden((int)$post_id);
  }

  public static function enforce_on_dokan_updated($product_id, $data): void {
    self::enforce_hidden((int)$product_id);
  }

  public static function enforce_on_dokan_new($product_id, $data): void {
    self::enforce_hidden((int)$product_id);
  }


  /**
   * If a service-linked Woo product is trashed/deleted directly (instead of deleting the service),
   * disable the service so customers can't book a service that can't be purchased.
   */
  public static function handle_product_trashed($post_id) {
    self::maybe_disable_service_for_product($post_id, 'trashed');
  }

  public static function handle_product_deleted($post_id) {
    self::maybe_disable_service_for_product($post_id, 'deleted');
  }

  private static function maybe_disable_service_for_product($post_id, string $reason) {
    if (get_post_type($post_id) !== 'product') {
      return;
    }

    $service_id = (int) get_post_meta($post_id, '_koopo_service_id', true);
    if (!$service_id) {
      return;
    }

    $service = get_post($service_id);
    if (!$service || $service->post_type !== Services_CPT::POST_TYPE) {
      return;
    }

    // Remove stale link from service → product
    delete_post_meta($service_id, '_koopo_wc_product_id');

    // Disable service (draft) so it won't show publicly until repaired/re-saved
    if ($service->post_status !== 'draft') {
      wp_update_post([
        'ID' => $service_id,
        'post_status' => 'draft',
      ]);
    }

    /**
     * Fires when a service is disabled due to its linked product being removed.
     */
    do_action('koopo_appt_service_disabled_missing_product', $service_id, $post_id, $reason);
  }

}
