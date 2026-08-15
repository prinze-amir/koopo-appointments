<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Provider-owned mobile coverage. Exact origins are never returned publicly. */
final class Service_Areas {
  const TYPE_RADIUS = 'radius';
  const MIN_RADIUS_METERS = 1609;
  const MAX_RADIUS_METERS = 160934;

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('before_delete_post', [__CLASS__, 'delete_with_provider'], 20, 2);
  }

  public static function delete_with_provider(int $post_id, ?\WP_Post $post = null): void {
    $post = $post ?: get_post($post_id);
    if (!$post || $post->post_type !== Provider_Profiles::POST_TYPE) return;
    global $wpdb;
    $wpdb->delete(DB::service_areas_table(), ['provider_id' => $post_id], ['%d']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)/service-area', [
      [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'read'],
        'permission_callback' => '__return_true',
      ],
      [
        'methods' => ['POST', 'PUT'],
        'callback' => [__CLASS__, 'update'],
        'permission_callback' => static fn(\WP_REST_Request $request): bool => self::can_manage(absint($request['id'])),
      ],
    ]);
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)/service-area/check', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'check'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
  }

  private static function can_manage(int $provider_id): bool {
    $post = get_post($provider_id);
    return (bool) ($post && $post->post_type === Provider_Profiles::POST_TYPE && (Access::is_admin_bypass() || (int) $post->post_author === get_current_user_id()));
  }

  public static function read(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    $area = self::get_for_provider($provider_id);
    if (!$area) return new \WP_REST_Response(['configured' => false], 200);
    return new \WP_REST_Response(self::format($area, self::can_manage($provider_id)), 200);
  }

  public static function update(\WP_REST_Request $request): \WP_REST_Response {
    $result = self::save_for_provider(absint($request['id']), (array) $request->get_json_params());
    if (is_wp_error($result)) return new \WP_REST_Response(['error' => $result->get_error_message(), 'code' => $result->get_error_code()], 422);
    return new \WP_REST_Response(self::format($result, true), 200);
  }

  public static function check(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    $provider = get_post($provider_id);
    $modes = $provider ? (array) get_post_meta($provider_id, Provider_Profiles::META_SERVICE_MODES, true) : [];
    if (!$provider || $provider->post_type !== Provider_Profiles::POST_TYPE || $provider->post_status !== 'publish' || !in_array('mobile', $modes, true)) {
      return new \WP_REST_Response(['error' => __('Mobile coverage is unavailable for this service profile.', 'koopo-appointments'), 'code' => 'mobile_area_unavailable'], 404);
    }
    $limit = self::consume_check_rate_limit(get_current_user_id(), $provider_id);
    if (is_wp_error($limit)) {
      return new \WP_REST_Response(['error' => $limit->get_error_message(), 'code' => $limit->get_error_code()], 429);
    }
    $payload = (array) $request->get_json_params();
    $result = self::check_destination($provider_id, self::sanitize_address($payload));
    if (is_wp_error($result)) return new \WP_REST_Response(['error' => $result->get_error_message(), 'code' => $result->get_error_code()], 422);
    return new \WP_REST_Response($result, 200);
  }

  public static function get_for_provider(int $provider_id, bool $active_only = true): ?object {
    global $wpdb;
    if (!$provider_id) return null;
    $sql = 'SELECT * FROM ' . DB::service_areas_table() . ' WHERE provider_id = %d';
    if ($active_only) $sql .= " AND status = 'active'";
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $row = $wpdb->get_row($wpdb->prepare($sql, $provider_id));
    return $row ?: null;
  }

  public static function save_for_provider(int $provider_id, array $payload) {
    global $wpdb;
    if (!$provider_id || get_post_type($provider_id) !== Provider_Profiles::POST_TYPE) return new \WP_Error('invalid_provider', __('Invalid service profile.', 'koopo-appointments'));
    $enabled = !array_key_exists('enabled', $payload) || !empty($payload['enabled']);
    $existing = self::get_for_provider($provider_id, false);
    if (!$enabled) {
      if ($existing) $wpdb->update(DB::service_areas_table(), ['status' => 'inactive'], ['id' => (int) $existing->id], ['%s'], ['%d']);
      return $existing ?: (object) ['id'=>0,'provider_id'=>$provider_id,'status'=>'inactive'];
    }

    $address = self::sanitize_address($payload);
    $latitude = self::coordinate($payload['latitude'] ?? null, -90, 90);
    $longitude = self::coordinate($payload['longitude'] ?? null, -180, 180);
    if ($latitude === null || $longitude === null) {
      $geocoded = self::geocode($address, 'provider_private', 'service_area_origin');
      if (is_wp_error($geocoded)) return $geocoded;
      $latitude = (float) $geocoded['latitude'];
      $longitude = (float) $geocoded['longitude'];
      update_post_meta($provider_id, '_koopo_service_area_geocode_provider', sanitize_key((string) ($geocoded['provider'] ?? 'custom')));
      update_post_meta($provider_id, '_koopo_service_area_geocoded_at', sanitize_text_field((string) ($geocoded['geocoded_at'] ?? current_time('mysql', true))));
    } else {
      update_post_meta($provider_id, '_koopo_service_area_geocode_provider', 'manual');
      update_post_meta($provider_id, '_koopo_service_area_geocoded_at', current_time('mysql', true));
    }
    $radius_meters = isset($payload['radius_meters'])
      ? absint($payload['radius_meters'])
      : (int) round(max(0, (float) ($payload['radius'] ?? 0)) * (!empty($payload['radius_unit']) && $payload['radius_unit'] === 'km' ? 1000 : 1609.344));
    if ($radius_meters < self::MIN_RADIUS_METERS || $radius_meters > self::MAX_RADIUS_METERS) {
      return new \WP_Error('invalid_service_radius', __('Choose a mobile service radius between 1 and 100 miles.', 'koopo-appointments'));
    }
    $public_label = sanitize_text_field((string) ($payload['public_label'] ?? ''));
    if ($public_label === '') {
      $public_label = sprintf(__('Serving %s and nearby communities', 'koopo-appointments'), $address['city'] ?: $address['region']);
    }
    $data = [
      'provider_id' => $provider_id,
      'area_type' => self::TYPE_RADIUS,
      'origin_address' => $address['address_1'],
      'origin_city' => $address['city'],
      'origin_region' => $address['region'],
      'origin_postal_code' => $address['postal_code'],
      'origin_country' => $address['country'],
      'origin_latitude' => $latitude,
      'origin_longitude' => $longitude,
      'radius_meters' => $radius_meters,
      'public_label' => $public_label,
      'travel_buffer_minutes' => min(240, absint($payload['travel_buffer_minutes'] ?? 30)),
      'status' => 'active',
      'updated_at' => current_time('mysql'),
    ];
    if ($existing) {
      $saved = $wpdb->update(DB::service_areas_table(), $data, ['id' => (int) $existing->id]);
      $id = (int) $existing->id;
    } else {
      $data['created_at'] = current_time('mysql');
      $saved = $wpdb->insert(DB::service_areas_table(), $data);
      if (false === $saved) {
        // A concurrent first save may have won the provider unique key.
        $existing = self::get_for_provider($provider_id, false);
        if ($existing) {
          $saved = $wpdb->update(DB::service_areas_table(), $data, ['id' => (int) $existing->id]);
          $wpdb->insert_id = (int) $existing->id;
        }
      }
      $id = (int) $wpdb->insert_id;
    }
    if (false === $saved || $id <= 0) {
      return new \WP_Error('service_area_save_failed', __('The mobile service area could not be saved. Please try again.', 'koopo-appointments'));
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::service_areas_table() . ' WHERE id = %d', $id));
    return $row ?: new \WP_Error('service_area_save_failed', __('The mobile service area could not be loaded after saving.', 'koopo-appointments'));
  }

  public static function public_area(int $provider_id): ?array {
    $area = self::get_for_provider($provider_id);
    return $area ? self::format($area, false) : null;
  }

  public static function check_destination(int $provider_id, array $address) {
    $area = self::get_for_provider($provider_id);
    if (!$area) return new \WP_Error('mobile_area_unavailable', __('This provider has not finished setting up mobile coverage.', 'koopo-appointments'));
    $geocoded = self::geocode($address, 'customer_private', 'mobile_coverage_check');
    if (is_wp_error($geocoded)) return $geocoded;
    $distance = self::distance_meters((float) $area->origin_latitude, (float) $area->origin_longitude, (float) $geocoded['latitude'], (float) $geocoded['longitude']);
    return [
      'eligible' => $distance <= (int) $area->radius_meters,
      'distance_meters' => (int) round($distance),
      'radius_meters' => (int) $area->radius_meters,
      'public_label' => (string) $area->public_label,
      'service_area_id' => (int) $area->id,
      'travel_buffer_minutes' => (int) $area->travel_buffer_minutes,
      'coordinates' => ['latitude'=>(float)$geocoded['latitude'],'longitude'=>(float)$geocoded['longitude']],
      'address' => $address,
    ];
  }

  public static function geocode(array $address, string $privacy_class = 'customer_private', string $purpose = 'mobile_coverage_check') {
    $address = self::sanitize_address($address);
    if ($address['city'] === '' || ($address['address_1'] === '' && $address['postal_code'] === '')) {
      return new \WP_Error('incomplete_service_address', __('Enter a street or postal code and city before checking coverage.', 'koopo-appointments'));
    }
    $cacheable = sanitize_key($privacy_class) !== 'customer_private';
    $cache_key = 'koopo_appt_geo_v2_' . hash('sha256', wp_json_encode([$address, sanitize_key($privacy_class)]));
    if ($cacheable) {
      $cached = get_transient($cache_key);
      if (is_array($cached) && isset($cached['latitude'], $cached['longitude'])) return $cached;
    }

    $context = ['privacy_class'=>sanitize_key($privacy_class), 'purpose'=>sanitize_key($purpose)];
    $filtered = apply_filters('koopo_appt_geocode_address', null, $address, $context);
    if (is_wp_error($filtered)) return $filtered;
    if (is_array($filtered) && isset($filtered['latitude'], $filtered['longitude'])) {
      if ($cacheable) set_transient($cache_key, $filtered, 30 * DAY_IN_SECONDS);
      return $filtered;
    }
    if (!class_exists(Geocoding_Router::class)) return new \WP_Error('geocoder_unavailable', __('Address validation is temporarily unavailable.', 'koopo-appointments'));
    $result = Geocoding_Router::geocode($address, $context);
    if (is_wp_error($result)) return $result;
    if ($cacheable) set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
    return $result;
  }

  public static function sanitize_address(array $payload): array {
    return [
      'address_1' => sanitize_text_field((string) ($payload['address_1'] ?? $payload['address'] ?? '')),
      'address_2' => sanitize_text_field((string) ($payload['address_2'] ?? '')),
      'city' => sanitize_text_field((string) ($payload['city'] ?? '')),
      'region' => sanitize_text_field((string) ($payload['region'] ?? '')),
      'postal_code' => sanitize_text_field((string) ($payload['postal_code'] ?? '')),
      'country' => sanitize_text_field((string) ($payload['country'] ?? 'United States')),
    ];
  }

  private static function format(object $area, bool $private): array {
    $data = [
      'configured' => (string) ($area->status ?? '') === 'active',
      'id' => (int) ($area->id ?? 0),
      'type' => (string) ($area->area_type ?? self::TYPE_RADIUS),
      'radius_meters' => (int) ($area->radius_meters ?? 0),
      'radius_miles' => round(((int) ($area->radius_meters ?? 0)) / 1609.344, 1),
      'public_label' => (string) ($area->public_label ?? ''),
      'travel_buffer_minutes' => (int) ($area->travel_buffer_minutes ?? 0),
      'approximate_center' => [
        'latitude' => round((float) ($area->origin_latitude ?? 0), 1),
        'longitude' => round((float) ($area->origin_longitude ?? 0), 1),
      ],
    ];
    if ($private) {
      $data['origin'] = [
        'address_1' => (string) ($area->origin_address ?? ''),
        'city' => (string) ($area->origin_city ?? ''),
        'region' => (string) ($area->origin_region ?? ''),
        'postal_code' => (string) ($area->origin_postal_code ?? ''),
        'country' => (string) ($area->origin_country ?? ''),
        'latitude' => (float) ($area->origin_latitude ?? 0),
        'longitude' => (float) ($area->origin_longitude ?? 0),
      ];
    }
    return $data;
  }

  private static function coordinate($value, float $minimum, float $maximum): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float) $value;
    return $number >= $minimum && $number <= $maximum ? round($number, 7) : null;
  }

  private static function consume_check_rate_limit(int $user_id, int $provider_id) {
    $window = 60;
    $maximum = max(1, (int) apply_filters('koopo_appt_service_area_checks_per_minute', 10, $user_id, $provider_id));
    $key = 'koopo_appt_area_check_' . md5($user_id . '|' . $provider_id);
    $count = (int) get_transient($key);
    if ($count >= $maximum) {
      return new \WP_Error('service_area_rate_limited', __('Too many coverage checks. Please wait a minute and try again.', 'koopo-appointments'));
    }
    set_transient($key, $count + 1, $window);
    return true;
  }

  public static function distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth = 6371008.8;
    $lat_delta = deg2rad($lat2 - $lat1);
    $lng_delta = deg2rad($lng2 - $lng1);
    $a = sin($lat_delta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lng_delta / 2) ** 2;
    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
  }
}
