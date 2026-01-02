<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Services_List {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/services/by-listing/(?P<id>\d+)', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_services_for_listing'],
      'permission_callback' => '__return_true', // public listing page; services are “public” in this context
    ]);
  }

  public static function get_services_for_listing(\WP_REST_Request $req) {
    $listing_id = absint($req['id']);
    if (!$listing_id) return new \WP_REST_Response([], 200);

    $listing = get_post($listing_id);
    if (!$listing) return new \WP_REST_Response([], 200);

    $vendor_id = (int) $listing->post_author;

    $q = new \WP_Query([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'publish',
      'author' => $vendor_id,
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'meta_query' => [
        [
          'key' => '_koopo_listing_id',
          'value' => $listing_id,
          'compare' => '=',   // services tied to this listing
        ],
      ],
    ]);

    $out = [];
    foreach ($q->posts as $p) {
      $product_id = (int) get_post_meta($p->ID, '_koopo_wc_product_id', true);
      if ( !$product_id || get_post_type($product_id) !== 'product' ) {
        // Service exists but its Woo product is missing/invalid; hide it from the public selector.
        continue;
      }

      $price = get_post_meta($p->ID, Services_API::META_PRICE, true);
      if ($price === '' || $price === null) $price = get_post_meta($p->ID, '_koopo_price', true);
      $duration = get_post_meta($p->ID, Services_API::META_DURATION, true);
      if ($duration === '' || $duration === null) $duration = get_post_meta($p->ID, '_koopo_duration_minutes', true);

      $out[] = [
        'id' => (int)$p->ID,
        'title' => get_the_title($p->ID),
        'price' => (float) $price,
        'duration_minutes' => (int) $duration,
        // extra vendor-dashboard fields (harmless for public consumers)
        'description' => (string) get_post_meta($p->ID, Services_API::META_DESC, true),
        'color' => (string) get_post_meta($p->ID, Services_API::META_COLOR, true),
        'status' => (string) (get_post_meta($p->ID, Services_API::META_STATUS, true) ?: 'active'),
        'price_label' => (string) get_post_meta($p->ID, Services_API::META_PRICE_LABEL, true),
        'buffer_before' => (int) get_post_meta($p->ID, Services_API::META_BUF_BEFORE, true),
        'buffer_after' => (int) get_post_meta($p->ID, Services_API::META_BUF_AFTER, true),
        'instant' => (get_post_meta($p->ID, Services_API::META_INSTANT, true) === '1'),
      ];
    }

    return new \WP_REST_Response($out, 200);
  }
}
