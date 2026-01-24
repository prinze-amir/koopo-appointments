<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Services_API {

  // Canonical meta keys for services (keep backwards-compat with earlier keys)
  const META_PRICE        = '_koopo_service_price';
  const META_DURATION     = '_koopo_service_duration_minutes';
  const META_LISTING_ID   = '_koopo_listing_id';
  const META_DESC         = '_koopo_service_description';
  const META_COLOR        = '_koopo_service_color';
  const META_STATUS       = '_koopo_service_status'; // active|inactive
  const META_PRICE_LABEL  = '_koopo_service_price_label';
  const META_BUF_BEFORE   = '_koopo_service_buffer_before';
  const META_BUF_AFTER    = '_koopo_service_buffer_after';
  const META_INSTANT      = '_koopo_service_instant'; // 1|0
  const META_ADDON        = '_koopo_service_addon'; // 1|0

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {

    // Create service
    register_rest_route('koopo/v1', '/services', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'create_service'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    // Update service
    register_rest_route('koopo/v1', '/services/(?P<id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'update_service'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    // Delete service
    register_rest_route('koopo/v1', '/services/(?P<id>\d+)', [
      'methods' => 'DELETE',
      'callback' => [__CLASS__, 'delete_service'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    // Get service categories
    register_rest_route('koopo/v1', '/service-categories', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_categories'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);
  }

  public static function can_manage(): bool {
    if (!is_user_logged_in()) return false;
    $user_id = get_current_user_id();
    if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id)) {
      return Access::vendor_has_feature($user_id, 'appointments');
    }
    return current_user_can('manage_options');
  }

  private static function assert_owner($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== Services_CPT::POST_TYPE) {
      return [false, new \WP_REST_Response(['error' => 'Service not found'], 404)];
    }
    if ((int)$post->post_author !== get_current_user_id()) {
      return [false, new \WP_REST_Response(['error' => 'Forbidden'], 403)];
    }
    return [true, $post];
  }

  public static function create_service(\WP_REST_Request $req) {
    $user_id = get_current_user_id();

    $title      = sanitize_text_field((string)$req->get_param('title'));
    $price      = (float)$req->get_param('price');
    $duration   = absint($req->get_param('duration_minutes'));
    $listing_id = absint($req->get_param('listing_id')); // service tied to a gd_place

    // Optional fields used by the vendor dashboard UI
    $desc        = sanitize_text_field((string)$req->get_param('description'));
    $color       = sanitize_hex_color((string)$req->get_param('color')) ?: '';
    $status      = sanitize_text_field((string)$req->get_param('status'));
    $price_label = sanitize_text_field((string)$req->get_param('price_label'));
    $buf_before  = absint($req->get_param('buffer_before'));
    $buf_after   = absint($req->get_param('buffer_after'));
    $instant     = !empty($req->get_param('instant')) ? '1' : '0';
    $is_addon    = !empty($req->get_param('is_addon')) ? '1' : '0';
    $category_ids = $req->get_param('category_ids'); // array of term IDs

    if (!$title || !$duration) {
      return new \WP_REST_Response(['error' => 'title and duration_minutes are required'], 400);
    }

    // Optional: validate listing ownership so vendors can’t attach services to other listings
    if ($listing_id) {
      $listing = get_post($listing_id);
      if (!$listing || (int)$listing->post_author !== $user_id) {
        return new \WP_REST_Response(['error' => 'Invalid listing ownership'], 403);
      }
    }

    $service_id = wp_insert_post([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $user_id,
    ], true);

    if (is_wp_error($service_id)) {
      return new \WP_REST_Response(['error' => $service_id->get_error_message()], 500);
    }

    // Canonical
    update_post_meta($service_id, self::META_PRICE, $price);
    update_post_meta($service_id, self::META_DURATION, $duration);
    if ($listing_id) update_post_meta($service_id, self::META_LISTING_ID, $listing_id);
    if ($desc) update_post_meta($service_id, self::META_DESC, $desc);
    if ($color) update_post_meta($service_id, self::META_COLOR, $color);
    if ($status) update_post_meta($service_id, self::META_STATUS, ($status === 'inactive' ? 'inactive' : 'active'));
    if ($price_label) update_post_meta($service_id, self::META_PRICE_LABEL, $price_label);
    update_post_meta($service_id, self::META_BUF_BEFORE, $buf_before);
    update_post_meta($service_id, self::META_BUF_AFTER, $buf_after);
    update_post_meta($service_id, self::META_INSTANT, $instant);
    update_post_meta($service_id, self::META_ADDON, $is_addon);

    // Assign categories
    if (is_array($category_ids)) {
      $category_ids = array_map('absint', $category_ids);
      wp_set_object_terms($service_id, $category_ids, Service_Categories::TAXONOMY);
    }

    // Backwards-compat (older keys used in earlier iterations)
    update_post_meta($service_id, '_koopo_price', $price);
    update_post_meta($service_id, '_koopo_duration_minutes', $duration);
    if ($listing_id) update_post_meta($service_id, '_koopo_listing_id', $listing_id);

    // Create linked WC product
    $product_id = WC_Service_Product::create_or_update_for_service($service_id);
    update_post_meta($service_id, '_koopo_wc_product_id', $product_id);

    return new \WP_REST_Response([
      'service_id' => (int)$service_id,
      'product_id' => (int)$product_id,
    ], 201);
  }

  public static function update_service(\WP_REST_Request $req) {
    $service_id = absint($req['id']);
    [$ok, $post_or_resp] = self::assert_owner($service_id);
    if (!$ok) return $post_or_resp;

    $title    = sanitize_text_field((string)$req->get_param('title'));
    $price    = $req->get_param('price');
    $duration = $req->get_param('duration_minutes');

    $desc        = $req->get_param('description');
    $color       = $req->get_param('color');
    $status      = $req->get_param('status');
    $price_label = $req->get_param('price_label');
    $buf_before  = $req->get_param('buffer_before');
    $buf_after   = $req->get_param('buffer_after');
    $instant     = $req->get_param('instant');
    $is_addon    = $req->get_param('is_addon');
    $category_ids = $req->get_param('category_ids');

    if ($title) {
      wp_update_post(['ID' => $service_id, 'post_title' => $title]);
    }
    if ($price !== null) {
      update_post_meta($service_id, self::META_PRICE, (float)$price);
      update_post_meta($service_id, '_koopo_price', (float)$price);
    }
    if ($duration !== null) {
      update_post_meta($service_id, self::META_DURATION, absint($duration));
      update_post_meta($service_id, '_koopo_duration_minutes', absint($duration));
    }
    if ($desc !== null) update_post_meta($service_id, self::META_DESC, sanitize_text_field((string)$desc));
    if ($color !== null) {
      $c = sanitize_hex_color((string)$color) ?: '';
      if ($c) update_post_meta($service_id, self::META_COLOR, $c);
    }
    if ($status !== null) update_post_meta($service_id, self::META_STATUS, ((string)$status === 'inactive' ? 'inactive' : 'active'));
    if ($price_label !== null) update_post_meta($service_id, self::META_PRICE_LABEL, sanitize_text_field((string)$price_label));
    if ($buf_before !== null) update_post_meta($service_id, self::META_BUF_BEFORE, absint($buf_before));
    if ($buf_after !== null) update_post_meta($service_id, self::META_BUF_AFTER, absint($buf_after));
    if ($instant !== null) update_post_meta($service_id, self::META_INSTANT, (!empty($instant) ? '1' : '0'));
    if ($is_addon !== null) update_post_meta($service_id, self::META_ADDON, (!empty($is_addon) ? '1' : '0'));

    // Update categories
    if ($category_ids !== null && is_array($category_ids)) {
      $category_ids = array_map('absint', $category_ids);
      wp_set_object_terms($service_id, $category_ids, Service_Categories::TAXONOMY);
    }

    // Sync WC product
    $product_id = WC_Service_Product::create_or_update_for_service($service_id);

    return new \WP_REST_Response([
      'service_id' => $service_id,
      'product_id' => (int)$product_id,
    ], 200);
  }

  public static function delete_service(\WP_REST_Request $req) {
    $service_id = absint($req['id']);
    [$ok, $post_or_resp] = self::assert_owner($service_id);
    if (!$ok) return $post_or_resp;

    $product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);

    // Delete service
    wp_trash_post($service_id);

    // Trash linked product (only if it’s ours)
    if ($product_id && get_post_meta($product_id, '_koopo_service_id', true) == $service_id) {
      wp_trash_post($product_id);
    }

    return new \WP_REST_Response([
      'deleted_service_id' => $service_id,
      'trashed_product_id' => $product_id ?: null,
    ], 200);
  }

  /**
   * Get all service categories
   */
  public static function get_categories(\WP_REST_Request $req) {
    $categories = Service_Categories::get_all_categories();

    return new \WP_REST_Response([
      'categories' => $categories,
    ], 200);
  }
}
