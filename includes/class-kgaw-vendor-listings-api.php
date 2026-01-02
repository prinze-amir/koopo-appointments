<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Vendor-only helper endpoints used by the Dokan dashboard UI.
 */
class Vendor_Listings_API {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/vendor/listings', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_my_listings'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  public static function get_my_listings(\WP_REST_Request $req) {
    $user_id = get_current_user_id();
    if (!$user_id) return new \WP_REST_Response([], 200);

    // GeoDirectory listing post type(s). Default: gd_place.
    $types = apply_filters('koopo_appt_listing_post_types', ['gd_place']);
    if (!is_array($types) || !$types) $types = ['gd_place'];

    $q = new \WP_Query([
      'post_type'      => $types,
      'post_status'    => 'publish',
      'author'         => $user_id,
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'fields'         => 'ids',
    ]);

    $out = [];
    foreach ($q->posts as $id) {
      $out[] = [
        'id'    => (int) $id,
        'title' => get_the_title($id),
        'type'  => get_post_type($id),
      ];
    }

    return new \WP_REST_Response($out, 200);
  }
}
