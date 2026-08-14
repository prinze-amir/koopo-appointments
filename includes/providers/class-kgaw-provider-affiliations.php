<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Provider_Affiliations {
  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/provider-affiliations', [
      [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'list_items'],
        'permission_callback' => '__return_true',
      ],
      [
        'methods' => 'POST',
        'callback' => [__CLASS__, 'request_affiliation'],
        'permission_callback' => fn() => is_user_logged_in(),
      ],
    ]);
    register_rest_route('koopo/v1', '/provider-affiliations/(?P<id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'update_status'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  public static function request_affiliation(\WP_REST_Request $request): \WP_REST_Response {
    global $wpdb;
    $provider_id = absint($request->get_param('provider_id'));
    $listing_id = absint($request->get_param('listing_id'));
    $provider = get_post($provider_id);
    $listing = get_post($listing_id);
    $user_id = get_current_user_id();
    if (!$provider || $provider->post_type !== Provider_Profiles::POST_TYPE || !$listing || $listing->post_type !== 'gd_place') {
      return new \WP_REST_Response(['error' => 'Invalid professional or place.'], 400);
    }
    if (!Access::is_admin_bypass() && (int) $provider->post_author !== $user_id && (int) $listing->post_author !== $user_id) {
      return new \WP_REST_Response(['error' => 'Forbidden'], 403);
    }
    $relationship = sanitize_key((string) $request->get_param('relationship_type'));
    if (!in_array($relationship, ['independent', 'staff'], true)) $relationship = 'independent';
    $wpdb->replace(DB::affiliations_table(), [
      'provider_id' => $provider_id,
      'listing_id' => $listing_id,
      'requested_by' => $user_id,
      'relationship_type' => $relationship,
      'status' => Access::is_admin_bypass() ? 'approved' : 'pending',
      'public_display' => 1,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ], ['%d','%d','%d','%s','%s','%d','%s','%s']);
    return new \WP_REST_Response(['id' => (int) $wpdb->insert_id, 'status' => Access::is_admin_bypass() ? 'approved' : 'pending'], 201);
  }

  public static function update_status(\WP_REST_Request $request): \WP_REST_Response {
    global $wpdb;
    $id = absint($request['id']);
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::affiliations_table() . ' WHERE id = %d', $id));
    if (!$row) return new \WP_REST_Response(['error' => 'Affiliation not found.'], 404);
    $status = sanitize_key((string) $request->get_param('status'));
    if (!in_array($status, ['approved', 'rejected', 'revoked'], true)) return new \WP_REST_Response(['error' => 'Invalid status.'], 400);
    $current_user = get_current_user_id();
    $listing_owner = (int) get_post_field('post_author', (int) $row->listing_id);
    $provider_owner = (int) get_post_field('post_author', (int) $row->provider_id);
    $is_party = in_array($current_user, [$listing_owner, $provider_owner], true);
    $is_counterparty = $is_party && $current_user !== (int) $row->requested_by;
    $allowed = Access::is_admin_bypass()
      || ($status === 'revoked' ? $is_party : $is_counterparty);
    if (!$allowed) return new \WP_REST_Response(['error' => 'The other party must approve or reject this affiliation.'], 403);
    $wpdb->update(DB::affiliations_table(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id], ['%s','%s'], ['%d']);
    return new \WP_REST_Response(['id' => $id, 'status' => $status], 200);
  }

  public static function list_items(\WP_REST_Request $request): \WP_REST_Response {
    global $wpdb;
    $provider_id = absint($request->get_param('provider_id'));
    $listing_id = absint($request->get_param('listing_id'));
    $public = !is_user_logged_in() || (!$provider_id && !$listing_id);
    $where = $public ? "WHERE status = 'approved' AND public_display = 1" : 'WHERE 1=1';
    $args = [];
    if ($provider_id) { $where .= ' AND provider_id = %d'; $args[] = $provider_id; }
    if ($listing_id) { $where .= ' AND listing_id = %d'; $args[] = $listing_id; }
    $sql = 'SELECT * FROM ' . DB::affiliations_table() . " {$where} ORDER BY updated_at DESC LIMIT 200";
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    $out = [];
    foreach ($rows ?: [] as $row) {
      $is_public = $row['status'] === 'approved' && !empty($row['public_display']);
      $can_see = $is_public || Access::is_admin_bypass() || (int) get_post_field('post_author', (int) $row['provider_id']) === get_current_user_id() || (int) get_post_field('post_author', (int) $row['listing_id']) === get_current_user_id();
      if (!$can_see) continue;
      $row['provider_name'] = get_the_title((int) $row['provider_id']);
      $row['provider_url'] = get_permalink((int) $row['provider_id']);
      $row['listing_name'] = get_the_title((int) $row['listing_id']);
      $row['listing_url'] = get_permalink((int) $row['listing_id']);
      $out[] = $row;
    }
    return new \WP_REST_Response($out, 200);
  }

  public static function approved_locations(int $provider_id): array {
    global $wpdb;
    if (!$provider_id) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT listing_id, relationship_type FROM ' . DB::affiliations_table() . " WHERE provider_id = %d AND status = 'approved' AND public_display = 1 ORDER BY updated_at DESC",
      $provider_id
    ), ARRAY_A) ?: [];
    return array_values(array_filter(array_map(static function(array $row): array {
      $listing_id = (int) $row['listing_id'];
      if (!$listing_id || get_post_status($listing_id) !== 'publish') return [];
      $details = function_exists('geodir_get_post_info') ? geodir_get_post_info($listing_id) : null;
      $read = static function(array $keys) use ($details, $listing_id) {
        foreach ($keys as $key) {
          if (is_object($details) && isset($details->{$key}) && '' !== $details->{$key}) return $details->{$key};
          if (is_array($details) && isset($details[$key]) && '' !== $details[$key]) return $details[$key];
          $value = get_post_meta($listing_id, $key, true);
          if ('' !== $value) return $value;
        }
        return '';
      };
      $address_parts = array_filter([
        sanitize_text_field((string) $read(['street', 'address'])),
        sanitize_text_field((string) $read(['city'])),
        sanitize_text_field((string) $read(['region', 'state'])),
      ]);
      return [
        'listing_id' => $listing_id,
        'name' => get_the_title($listing_id),
        'url' => get_permalink($listing_id),
        'relationship_type' => sanitize_key((string) $row['relationship_type']),
        'address' => implode(', ', array_unique($address_parts)),
        'latitude' => (float) $read(['latitude', '_latitude', 'lat']),
        'longitude' => (float) $read(['longitude', '_longitude', 'lng', 'lon']),
      ];
    }, $rows)));
  }
}
