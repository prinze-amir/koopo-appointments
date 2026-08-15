<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Server-side, storage-compatible geocoding with configurable free-first routing. */
final class Geocoding_Router {
  const PROVIDERS = ['geocodefarm', 'geoapify', 'google', 'osm'];
  const PUBLIC_PRIVACY_CLASS = 'public_profile';

  public static function geocode(array $address, array $context = []) {
    $address = Service_Areas::sanitize_address($address);
    if ($address['city'] === '' || ($address['address_1'] === '' && $address['postal_code'] === '')) {
      return new \WP_Error('incomplete_service_address', __('Enter a street or postal code and city before checking the location.', 'koopo-appointments'));
    }

    $context = wp_parse_args($context, [
      'privacy_class' => 'customer_private',
      'purpose' => 'service_location',
    ]);
    $mode = Admin_Settings::geocoder_mode();
    if ($mode === 'disabled') {
      return new \WP_Error('geocoder_disabled', __('Address verification is disabled by the site administrator.', 'koopo-appointments'));
    }

    $privacy_class = sanitize_key((string) $context['privacy_class']);
    $cacheable = $privacy_class !== 'customer_private';
    $cache_key = 'koopo_appt_geocode_v2_' . hash('sha256', wp_json_encode([$address, $mode, $privacy_class]));
    if ($cacheable) {
      $cached = get_transient($cache_key);
      if (is_array($cached) && self::valid_coordinates($cached['latitude'] ?? null, $cached['longitude'] ?? null)) return $cached;
    }

    $providers = $mode === 'auto' ? Admin_Settings::geocoder_auto_order() : [$mode];
    $errors = [];
    foreach ($providers as $provider) {
      $provider = self::canonical_provider((string) $provider);
      if (!in_array($provider, self::PROVIDERS, true)) continue;
      if (!self::eligible($provider, $context, $mode === 'auto')) continue;

      $result = self::request($provider, $address);
      if (is_wp_error($result)) {
        $errors[$provider] = $result->get_error_message();
        continue;
      }
      $result['provider'] = $provider;
      $result['geocoded_at'] = current_time('mysql', true);
      $result['purpose'] = sanitize_key((string) $context['purpose']);
      if ($cacheable) set_transient($cache_key, $result, (int) apply_filters('koopo_appt_geocode_cache_ttl', 30 * DAY_IN_SECONDS, $address, $context));
      do_action('koopo_appt_geocoded', $result, $address, $context);
      return $result;
    }

    return new \WP_Error(
      'geocoder_unavailable',
      $errors ? __('No configured geocoder could locate that address. Check it and try again.', 'koopo-appointments') : __('No eligible geocoding provider is configured for this address.', 'koopo-appointments'),
      ['providers' => array_keys($errors)]
    );
  }

  public static function canonical_provider(string $provider): string {
    $provider = sanitize_key($provider);
    if (in_array($provider, ['geofarm', 'geocode_farm'], true)) return 'geocodefarm';
    if (in_array($provider, ['apify', 'goeapify'], true)) return 'geoapify';
    return $provider;
  }

  private static function eligible(string $provider, array $context, bool $automatic): bool {
    if (get_transient('koopo_appt_geocoder_down_' . $provider)) return false;
    if ($provider === 'osm') {
      if ((string) $context['privacy_class'] !== self::PUBLIC_PRIVACY_CLASS) return false;
      if (!Admin_Settings::geocoder_osm_public_enabled()) return false;
    } elseif (Admin_Settings::geocoder_api_key($provider) === '') {
      return false;
    }
    if ($automatic) {
      $limit = Admin_Settings::geocoder_daily_limit($provider);
      if ($limit <= 0 || self::usage_today($provider) >= $limit) return false;
    }
    return true;
  }

  private static function request(string $provider, array $address) {
    $osm_locked = false;
    if ($provider === 'osm') {
      global $wpdb;
      $lock_name = 'koopo_appt_geocoder_osm';
      $osm_locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name)) === '1';
      if (!$osm_locked) return new \WP_Error('geocoder_busy', __('Public geocoding is busy. Please try again.', 'koopo-appointments'));
      $last = (float) get_option('koopo_appt_geocoder_osm_last_request', 0);
      if ($last > 0 && microtime(true) - $last < 1.05) {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        return new \WP_Error('geocoder_busy', __('Public geocoding is rate limited. Please try again.', 'koopo-appointments'));
      }
      update_option('koopo_appt_geocoder_osm_last_request', (string) microtime(true), false);
    }
    self::increment_usage($provider);
    $query = implode(', ', array_values(array_filter([
      $address['address_1'], $address['address_2'], $address['city'], $address['region'], $address['postal_code'], $address['country'],
    ])));

    if ($provider === 'geocodefarm') {
      $url = add_query_arg(['key' => Admin_Settings::geocoder_api_key($provider), 'addr' => $query], 'https://api.geocodefarm.com/forward/');
    } elseif ($provider === 'geoapify') {
      $url = add_query_arg(['text' => $query, 'format' => 'geojson', 'limit' => 1, 'apiKey' => Admin_Settings::geocoder_api_key($provider)], 'https://api.geoapify.com/v1/geocode/search');
    } elseif ($provider === 'google') {
      $url = add_query_arg(['address' => $query, 'key' => Admin_Settings::geocoder_api_key($provider)], 'https://maps.googleapis.com/maps/api/geocode/json');
    } else {
      $url = add_query_arg(['format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 1, 'q' => $query, 'email' => get_option('admin_email')], 'https://nominatim.openstreetmap.org/search');
    }

    $response = wp_safe_remote_get($url, [
      'timeout' => 8,
      'redirection' => 2,
      'sslverify' => true,
      'headers' => ['User-Agent' => 'KoopoAppointments/' . KOOPO_APPT_VERSION . ' (+' . home_url('/') . ')'],
    ]);
    if ($osm_locked) {
      global $wpdb;
      $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'koopo_appt_geocoder_osm'));
    }
    if (is_wp_error($response)) {
      self::cooldown($provider, 120);
      return new \WP_Error('geocoder_network_error', __('The geocoding provider could not be reached.', 'koopo-appointments'));
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300) {
      if (in_array($status, [401, 402, 403], true)) self::cooldown($provider, HOUR_IN_SECONDS);
      elseif ($status === 429) self::cooldown($provider, 15 * MINUTE_IN_SECONDS);
      elseif ($status >= 500) self::cooldown($provider, 120);
      return new \WP_Error('geocoder_http_error', __('The geocoding provider rejected the request.', 'koopo-appointments'), ['status' => $status]);
    }

    $result = self::parse($provider, is_array($body) ? $body : []);
    if (is_wp_error($result)) return $result;
    return $result;
  }

  private static function parse(string $provider, array $body) {
    $lat = null;
    $lng = null;
    $formatted = '';
    $accuracy = '';
    if ($provider === 'geocodefarm') {
      if (($body['STATUS']['status'] ?? '') !== 'SUCCESS') return new \WP_Error('address_not_found', __('The address was not found.', 'koopo-appointments'));
      $result = (array) ($body['RESULTS']['result'] ?? []);
      $lat = $result['coordinates']['lat'] ?? null;
      $lng = $result['coordinates']['lon'] ?? null;
      $formatted = (string) ($result['address']['full_address'] ?? '');
      $accuracy = sanitize_key((string) ($result['accuracy'] ?? ''));
    } elseif ($provider === 'geoapify') {
      $feature = (array) ($body['features'][0] ?? []);
      $coordinates = (array) ($feature['geometry']['coordinates'] ?? []);
      $properties = (array) ($feature['properties'] ?? []);
      $lng = $coordinates[0] ?? null;
      $lat = $coordinates[1] ?? null;
      $formatted = (string) ($properties['formatted'] ?? '');
      $accuracy = isset($properties['rank']['confidence']) ? (string) $properties['rank']['confidence'] : '';
    } elseif ($provider === 'google') {
      if (($body['status'] ?? '') !== 'OK') return new \WP_Error('address_not_found', __('The address was not found.', 'koopo-appointments'));
      $result = (array) ($body['results'][0] ?? []);
      $location = (array) ($result['geometry']['location'] ?? []);
      $lat = $location['lat'] ?? null;
      $lng = $location['lng'] ?? null;
      $formatted = (string) ($result['formatted_address'] ?? '');
      $accuracy = sanitize_key((string) ($result['geometry']['location_type'] ?? ''));
    } else {
      $result = (array) ($body[0] ?? []);
      $lat = $result['lat'] ?? null;
      $lng = $result['lon'] ?? null;
      $formatted = (string) ($result['display_name'] ?? '');
      $accuracy = sanitize_key((string) ($result['type'] ?? ''));
    }
    if (!self::valid_coordinates($lat, $lng)) return new \WP_Error('address_not_found', __('The address was not found.', 'koopo-appointments'));
    return [
      'latitude' => round((float) $lat, 7),
      'longitude' => round((float) $lng, 7),
      'formatted_address' => sanitize_text_field($formatted),
      'accuracy' => sanitize_text_field($accuracy),
    ];
  }

  private static function valid_coordinates($lat, $lng): bool {
    return is_numeric($lat) && is_numeric($lng) && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180;
  }

  private static function usage_key(): string {
    return 'koopo_appt_geocoder_usage_' . gmdate('Ymd');
  }

  public static function usage_today(string $provider = '') {
    $usage = get_transient(self::usage_key());
    $usage = is_array($usage) ? $usage : [];
    return $provider === '' ? $usage : (int) ($usage[$provider] ?? 0);
  }

  private static function increment_usage(string $provider): void {
    $usage = self::usage_today();
    $usage[$provider] = (int) ($usage[$provider] ?? 0) + 1;
    set_transient(self::usage_key(), $usage, 2 * DAY_IN_SECONDS);
  }

  private static function cooldown(string $provider, int $seconds): void {
    set_transient('koopo_appt_geocoder_down_' . $provider, 1, max(30, $seconds));
  }
}
