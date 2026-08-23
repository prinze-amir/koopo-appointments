<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Bookings {
  private const EXTRA_FIELD_DEFAULTS = [
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'customer_notes' => '',
    'fulfillment_mode' => 'at_location',
    'service_address_1' => '',
    'service_address_2' => '',
    'service_city' => '',
    'service_region' => '',
    'service_postal_code' => '',
    'service_country' => '',
    'service_latitude' => null,
    'service_longitude' => null,
    'service_area_id' => 0,
    'travel_buffer_minutes' => 0,
    'virtual_provider' => '',
    'virtual_join_url' => '',
    'virtual_instructions' => '',
    'booking_for_other' => 0,
    'addon_ids' => '',
    'cancelled_by' => '',
    'cancel_reason' => '',
    'refund_amount' => 0.0,
    'refund_status' => '',
    'review_invite_sent' => null,
  ];

  public static function init() {
    // REST is cleaner than admin-ajax; use REST so mobile/app can hit it later.
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() {
    register_rest_route('koopo/v1', '/bookings', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'create_booking'],
      'permission_callback' => function() {
        return is_user_logged_in();
      }
    ]);
  }

  /**
   * Statuses that should block a time slot (used by both booking creation and availability).
   * Filter: koopo_appt_blocking_statuses
   */
  public static function get_blocking_statuses(int $listing_id): array {
    $statuses = apply_filters('koopo_appt_blocking_statuses', ['pending_invitation','pending_payment','confirmed'], $listing_id);
    // normalize: unique, non-empty strings
    $statuses = array_values(array_unique(array_filter(array_map('strval', (array)$statuses))));
    return $statuses ?: ['pending_invitation','pending_payment','confirmed'];
  }

  private static function supported_listing_post_types(): array {
    $types = apply_filters('koopo_appt_listing_post_types', ['gd_place']);
    if (!is_array($types) || empty($types)) {
      return ['gd_place'];
    }

    return array_values(array_unique(array_map('strval', $types)));
  }

  private static function resolve_listing_timezone(array $settings): \DateTimeZone {
    $tz_name = !empty($settings['timezone']) ? (string) $settings['timezone'] : '';
    if ($tz_name === '') {
      $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string');
    }
    if ($tz_name === '') {
      $tz_name = 'UTC';
    }

    try {
      return new \DateTimeZone($tz_name);
    } catch (\Exception $e) {
      return new \DateTimeZone('UTC');
    }
  }

  private static function vacation_ranges_for_date(array $days_off, string $date): array {
    $ranges = [];
    $all_day = false;

    foreach ($days_off as $item) {
      if (is_string($item)) {
        if ($item === $date) {
          $all_day = true;
          break;
        }
        continue;
      }

      if (!is_array($item)) {
        continue;
      }

      $item_date = isset($item['date']) ? (string) $item['date'] : '';
      if ($item_date !== $date) {
        continue;
      }

      $start = isset($item['start']) ? (string) $item['start'] : '';
      $end = isset($item['end']) ? (string) $item['end'] : '';

      if ($start === '' && $end === '') {
        $all_day = true;
        break;
      }

      if ($start !== '' && $end !== '') {
        $ranges[] = [$start, $end];
      }
    }

    return ['all_day' => $all_day, 'ranges' => $ranges];
  }

  private static function get_service_duration_minutes(int $service_id): int {
    $duration = get_post_meta($service_id, Services_API::META_DURATION, true);
    if ($duration === '' || $duration === null) {
      $duration = get_post_meta($service_id, '_koopo_duration_minutes', true);
    }

    return max(0, (int) $duration);
  }

  private static function get_service_status(int $service_id): string {
    $status = (string) get_post_meta($service_id, Services_API::META_STATUS, true);
    return $status !== '' ? $status : 'active';
  }

  private static function is_service_active(int $service_id): bool {
    return self::get_service_status($service_id) !== 'inactive';
  }

  private static function assert_primary_service_is_bookable(int $listing_id, int $provider_id, int $resource_id, int $service_id): array {
    $service = get_post($service_id);
    if (!$service || $service->post_type !== Services_CPT::POST_TYPE || $service->post_status !== 'publish') {
      throw new \Exception('Service not found.');
    }

    $service_listing_id = (int) get_post_meta($service_id, Services_API::META_LISTING_ID, true);
    $service_provider_id = (int) get_post_meta($service_id, Services_API::META_PROVIDER_ID, true);
    $resource = Resources::for_service($service_id);
    if (!$resource) throw new \Exception('Service booking calendar not found.');

    if ($listing_id && ($service_listing_id !== $listing_id || (string) $resource->subject_type !== 'listing')) {
      throw new \Exception('This service does not belong to the selected listing.');
    }
    if ($provider_id && ($service_provider_id !== $provider_id || (string) $resource->subject_type !== 'provider')) {
      throw new \Exception('This service does not belong to the selected professional.');
    }
    if ($resource_id && (int) $resource->id !== $resource_id) {
      throw new \Exception('This service does not belong to the selected booking calendar.');
    }
    if (!$listing_id && !$provider_id && !$resource_id) {
      throw new \Exception('A booking subject is required.');
    }
    if ((int) $service->post_author !== (int) $resource->payee_user_id) {
      throw new \Exception('Service payment ownership mismatch.');
    }

    if (get_post_meta($service_id, Services_API::META_ADDON, true) === '1') {
      throw new \Exception('Add-ons cannot be booked as standalone services.');
    }

    if (!self::is_service_active($service_id)) {
      throw new \Exception('This service is currently unavailable.');
    }

    return [
      'listing_id' => $service_listing_id,
      'provider_id' => $service_provider_id,
      'resource_id' => (int) $resource->id,
      'location_id' => $service_listing_id,
      'payee_user_id' => (int) $resource->payee_user_id,
      'settings_post_id' => (int) $resource->subject_id,
    ];
  }

  private static function assert_addons_are_bookable(int $resource_id, array $submitted_addon_ids): array {
    $submitted_addon_ids = array_values(array_unique(array_filter(array_map('absint', $submitted_addon_ids))));
    if (empty($submitted_addon_ids)) {
      return [];
    }

    $normalized = self::normalize_addon_ids($resource_id, $submitted_addon_ids);
    if (count($normalized) !== count($submitted_addon_ids)) {
      throw new \Exception('One or more selected add-ons are unavailable for this booking calendar.');
    }

    return $normalized;
  }

  private static function fulfillment_context(array $context, array $data): array {
    $provider_id = (int) ($context['provider_id'] ?? 0);
    if (!$provider_id) return ['fulfillment_mode'=>'at_location','service_area_id'=>0,'travel_buffer_minutes'=>0];
    $modes = (array) get_post_meta($provider_id, Provider_Profiles::META_SERVICE_MODES, true);
    $mode = sanitize_key((string) ($data['fulfillment_mode'] ?? ''));
    if ($mode === '') $mode = in_array('at_location', $modes, true) ? 'at_location' : (in_array('mobile', $modes, true) ? 'mobile' : 'virtual');
    if (!in_array($mode, ['at_location','mobile','virtual'], true) || !in_array($mode, $modes, true)) throw new \Exception('This delivery option is not available for the selected professional.');
    $out = ['fulfillment_mode'=>$mode,'service_area_id'=>0,'travel_buffer_minutes'=>0];
    if ($mode === 'at_location') {
      $direct_location = Provider_Profiles::direct_location($provider_id, true);
      $affiliated_locations = Provider_Affiliations::approved_locations($provider_id);
      if (!$direct_location && !$affiliated_locations) {
        throw new \Exception('This professional has not configured an appointment location.');
      }
    } elseif ($mode === 'mobile') {
      $address = Service_Areas::sanitize_address((array) ($data['service_address'] ?? $data));
      $coverage = Service_Areas::check_destination($provider_id, $address);
      if (is_wp_error($coverage)) throw new \Exception($coverage->get_error_message());
      if (empty($coverage['eligible'])) throw new \Exception('This address is outside the provider’s mobile service area.');
      $out = array_merge($out, [
        'service_area_id'=>(int)$coverage['service_area_id'],
        'travel_buffer_minutes'=>(int)$coverage['travel_buffer_minutes'],
        'service_address_1'=>$address['address_1'],
        'service_address_2'=>$address['address_2'],
        'service_city'=>$address['city'],
        'service_region'=>$address['region'],
        'service_postal_code'=>$address['postal_code'],
        'service_country'=>$address['country'],
        'service_latitude'=>(float)$coverage['coordinates']['latitude'],
        'service_longitude'=>(float)$coverage['coordinates']['longitude'],
      ]);
    } elseif ($mode === 'virtual') {
      $virtual = Provider_Profiles::virtual_delivery($provider_id, true);
      $join_url = in_array((string)$virtual['method'], ['custom_link','zoom','google_meet'], true) ? esc_url_raw((string)($virtual['join_url'] ?? '')) : '';
      if (in_array((string) $virtual['method'], ['custom_link','zoom','google_meet'], true) && !$join_url) {
        throw new \Exception('This professional has not configured a virtual meeting link.');
      }
      $out = array_merge($out, [
        'virtual_provider'=>sanitize_key((string)($virtual['method'] ?? 'provider_sends')),
        'virtual_join_url'=>$join_url,
        'virtual_instructions'=>sanitize_textarea_field((string)($virtual['instructions'] ?? '')),
      ]);
    }
    return $out;
  }

  private static function minutes_from_time_string(string $hhmm): int {
    [$hours, $minutes] = array_map('intval', explode(':', $hhmm));
    return ($hours * 60) + $minutes;
  }

  private static function ranges_overlap_minutes(int $start_a, int $end_a, int $start_b, int $end_b): bool {
    return $start_a < $end_b && $end_a > $start_b;
  }

  private static function slot_matches_hours(array $hours, int $slot_interval, int $start_minutes, int $end_minutes): bool {
    foreach ($hours as $range) {
      if (!is_array($range) || count($range) < 2) {
        continue;
      }

      $from = preg_replace('/[^0-9:]/', '', (string) $range[0]);
      $to = preg_replace('/[^0-9:]/', '', (string) $range[1]);
      if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
        continue;
      }

      $from_minutes = self::minutes_from_time_string($from);
      $to_minutes = self::minutes_from_time_string($to);

      if ($start_minutes < $from_minutes || $end_minutes > $to_minutes) {
        continue;
      }

      if ($slot_interval > 0 && (($start_minutes - $from_minutes) % $slot_interval) !== 0) {
        continue;
      }

      return true;
    }

    return false;
  }

  private static function assert_slot_matches_schedule(
    int $resource_id,
    string $start,
    string $end,
    int $expected_duration_minutes,
    bool $require_enabled = true
  ): array {
    $settings_post_id = Resources::settings_post_id($resource_id);
    if (!$settings_post_id) throw new \Exception('Booking calendar not found.');
    $settings = Settings_API::read_settings($settings_post_id);
    if ($require_enabled && empty($settings['enabled'])) {
      throw new \Exception('Appointments are unavailable for this listing.');
    }

    $timezone = self::resolve_listing_timezone($settings);

    try {
      $start_dt = new \DateTimeImmutable($start, $timezone);
      $end_dt = new \DateTimeImmutable($end, $timezone);
    } catch (\Exception $e) {
      throw new \Exception('Invalid booking date/time.');
    }

    if ($end_dt <= $start_dt) {
      throw new \Exception('End time must be after start time.');
    }

    if ($start_dt->format('Y-m-d') !== $end_dt->format('Y-m-d')) {
      throw new \Exception('Appointments must begin and end on the same day.');
    }

    $now = new \DateTimeImmutable('now', $timezone);
    if ($start_dt <= $now) {
      throw new \Exception('Appointments must be booked in the future.');
    }

    $actual_duration = (int) round(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60);
    if ($expected_duration_minutes > 0 && $actual_duration !== $expected_duration_minutes) {
      throw new \Exception('Selected time does not match the service duration.');
    }

    $date = $start_dt->format('Y-m-d');
    $day_map = ['1' => 'mon', '2' => 'tue', '3' => 'wed', '4' => 'thu', '5' => 'fri', '6' => 'sat', '7' => 'sun'];
    $day_key = $day_map[$start_dt->format('N')] ?? 'mon';
    $hours = isset($settings['hours'][$day_key]) && is_array($settings['hours'][$day_key]) ? $settings['hours'][$day_key] : [];
    $breaks = isset($settings['breaks'][$day_key]) && is_array($settings['breaks'][$day_key]) ? $settings['breaks'][$day_key] : [];

    $vacation_ranges = self::vacation_ranges_for_date($settings['days_off'] ?? [], $date);
    if (!empty($vacation_ranges['all_day'])) {
      throw new \Exception('The selected date is unavailable.');
    }
    if (!empty($vacation_ranges['ranges'])) {
      $breaks = array_merge($breaks, $vacation_ranges['ranges']);
    }

    $slot_interval = (int) ($settings['slot_interval'] ?? 0);
    if ($slot_interval <= 0) {
      $slot_interval = $expected_duration_minutes;
    }
    if ($expected_duration_minutes > 0 && $slot_interval > $expected_duration_minutes) {
      $slot_interval = $expected_duration_minutes;
    }

    $start_minutes = ((int) $start_dt->format('H') * 60) + (int) $start_dt->format('i');
    $end_minutes = ((int) $end_dt->format('H') * 60) + (int) $end_dt->format('i');

    if (!self::slot_matches_hours($hours, $slot_interval, $start_minutes, $end_minutes)) {
      throw new \Exception('The selected time is outside business hours.');
    }

    foreach ($breaks as $range) {
      if (!is_array($range) || count($range) < 2) {
        continue;
      }

      $break_start = preg_replace('/[^0-9:]/', '', (string) $range[0]);
      $break_end = preg_replace('/[^0-9:]/', '', (string) $range[1]);
      if (!preg_match('/^\d{2}:\d{2}$/', $break_start) || !preg_match('/^\d{2}:\d{2}$/', $break_end)) {
        continue;
      }

      if (self::ranges_overlap_minutes(
        $start_minutes,
        $end_minutes,
        self::minutes_from_time_string($break_start),
        self::minutes_from_time_string($break_end)
      )) {
        throw new \Exception('The selected time falls within an unavailable period.');
      }
    }

    return $settings;
  }

  private static function find_conflict_id(
    int $resource_id,
    string $start,
    string $end,
    array $settings = [],
    int $exclude_booking_id = 0
  ): int {
    global $wpdb;

    $table = DB::table();
    $statuses = self::get_blocking_statuses($resource_id);
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $settings = !empty($settings) ? $settings : Settings_API::read_settings(Resources::settings_post_id($resource_id));
    $timezone = self::resolve_listing_timezone($settings);

    try {
      $start_dt = new \DateTimeImmutable($start, $timezone);
      $end_dt = new \DateTimeImmutable($end, $timezone);
    } catch (\Exception $e) {
      return 0;
    }

    $buffer_before = max(0, (int) ($settings['buffer_before'] ?? 0));
    $buffer_after = max(0, (int) ($settings['buffer_after'] ?? 0));
    $candidate_start = $start_dt->modify(sprintf('-%d minutes', $buffer_before));
    $candidate_end = $end_dt->modify(sprintf('+%d minutes', $buffer_after));
    $query_start = $candidate_start->format('Y-m-d H:i:s');
    $query_end = $candidate_end->format('Y-m-d H:i:s');

    if (class_exists(Calendar_Busy::class) && Calendar_Busy::has_conflict($resource_id, $query_start, $query_end, $timezone->getName())) {
      return -1;
    }

    $where_exclude = $exclude_booking_id > 0 ? ' AND id <> %d' : '';
    $params = array_merge([$resource_id], $statuses);
    if ($exclude_booking_id > 0) {
      $params[] = $exclude_booking_id;
    }
    // Mobile bookings persist their own travel buffer. Widen the SQL window so
    // those rows are candidates, then compare their exact effective ranges in
    // PHP. The stored value is capped at 240 minutes by Service_Areas.
    $maximum_existing_travel = 240;
    $params[] = $candidate_end->modify(sprintf('+%d minutes', $maximum_existing_travel))->format('Y-m-d H:i:s');
    $params[] = $candidate_start->modify(sprintf('-%d minutes', $maximum_existing_travel))->format('Y-m-d H:i:s');

    $sql = $wpdb->prepare(
      "SELECT id, start_datetime, end_datetime, travel_buffer_minutes
       FROM {$table}
       WHERE resource_id = %d
         AND status IN ({$placeholders}){$where_exclude}
         AND start_datetime < %s
         AND end_datetime > %s
       ORDER BY start_datetime ASC",
      $params
    );

    $rows = $wpdb->get_results($sql) ?: [];
    foreach ($rows as $row) {
      try {
        $travel = min($maximum_existing_travel, max(0, (int) ($row->travel_buffer_minutes ?? 0)));
        $existing_start = (new \DateTimeImmutable((string) $row->start_datetime, $timezone))->modify(sprintf('-%d minutes', $travel));
        $existing_end = (new \DateTimeImmutable((string) $row->end_datetime, $timezone))->modify(sprintf('+%d minutes', $travel));
      } catch (\Throwable $error) {
        // Fail closed if an existing blocking row contains malformed time data.
        return (int) $row->id;
      }
      if (self::effective_ranges_overlap($candidate_start, $candidate_end, $existing_start, $existing_end)) {
        return (int) $row->id;
      }
    }

    return 0;
  }

  private static function effective_ranges_overlap(\DateTimeImmutable $start_a, \DateTimeImmutable $end_a, \DateTimeImmutable $start_b, \DateTimeImmutable $end_b): bool {
    return $start_a < $end_b && $end_a > $start_b;
  }

  private static function maybe_notify_confirmed_booking(int $booking_id): void {
    $booking = self::get_booking($booking_id);
    if ($booking && (string) $booking->status === 'confirmed') {
      do_action('koopo_booking_confirmed_safe', $booking_id, $booking);
    }
  }

private static function acquire_lock(int $resource_id, int $timeout_seconds = 2): bool {
  global $wpdb;
  $key = 'koopo_appt_resource_' . $resource_id;
  $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $key, $timeout_seconds));
  return (string)$got === '1';
}

private static function release_lock(int $resource_id): void {
  global $wpdb;
  $key = 'koopo_appt_resource_' . $resource_id;
  $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $key));
}

  /**
   * REST: Create a pending booking record.
   * Expects (JSON): listing_id, service_id, start_datetime, end_datetime
   */
  public static function create_booking(\WP_REST_Request $req) {
    // Prefer JSON body, but allow form-encoded for flexibility.
    $data = (array) $req->get_json_params();
    if (empty($data)) {
      $data = (array) $req->get_params();
    }

    $listing_id = absint($data['listing_id'] ?? 0);
    $provider_id = absint($data['provider_id'] ?? 0);
    $resource_id = absint($data['resource_id'] ?? 0);
    $service_id = absint($data['service_id'] ?? 0);
    $start      = sanitize_text_field((string)($data['start_datetime'] ?? ''));
    $end        = sanitize_text_field((string)($data['end_datetime'] ?? ''));

    if ((!$listing_id && !$provider_id && !$resource_id) || !$service_id || !$start || !$end) {
      return new \WP_REST_Response([
        'error' => 'A listing, professional, or resource plus service_id, start_datetime, and end_datetime is required.'
      ], 400);
    }

    $customer_id = get_current_user_id();
    if (!$customer_id) {
      return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
    }
    $customer = get_userdata($customer_id);
    if (!$customer) {
      return new \WP_REST_Response(['error' => 'Customer account not found'], 404);
    }

    // Defaults: keep booking rows deterministic even if caller omits optional fields.
    $timezone = sanitize_text_field((string)($data['timezone'] ?? ''));
    if ($timezone === '') {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string');
    }
    if ($timezone === '') {
      $timezone = 'UTC';
    }

    $currency = sanitize_text_field((string)($data['currency'] ?? ''));
    if ($currency === '') {
      $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD';
    }

    // Fill through to the internal row creator.
    $addon_ids = isset($data['addon_ids']) && is_array($data['addon_ids']) ? array_map('absint', $data['addon_ids']) : [];
    $service_resource = Resources::for_service($service_id);
    $addon_ids = self::normalize_addon_ids($service_resource ? (int) $service_resource->id : $resource_id, $addon_ids);
    $price_total = self::calculate_price_total($service_id, $addon_ids);
    $status = $price_total <= 0 ? 'confirmed' : 'pending_payment';

    $payload = [
      'listing_id'      => $listing_id,
      'provider_id'     => $provider_id,
      'resource_id'     => $resource_id,
      'service_id'      => $service_id,
      'customer_id'     => $customer_id,
      'start_datetime'  => $start,
      'end_datetime'    => $end,
      'timezone'        => $timezone,
      'currency'        => $currency,
      'price'           => null,
      'addon_ids'       => $addon_ids,
      'status'          => $status,
      // Customer-facing bookings always belong to the authenticated account.
      // Provider-created guest appointments use the separate vendor route.
      'customer_name'   => sanitize_text_field((string) $customer->display_name),
      'customer_email'  => sanitize_email((string) $customer->user_email),
      'customer_phone'  => sanitize_text_field((string) ($data['customer_phone'] ?? get_user_meta($customer_id, 'billing_phone', true))),
      'customer_notes'  => isset($data['customer_notes']) ? sanitize_textarea_field($data['customer_notes']) : '',
      'booking_for_other' => false,
      'fulfillment_mode' => sanitize_key((string) ($data['fulfillment_mode'] ?? '')),
      'service_address' => isset($data['service_address']) && is_array($data['service_address']) ? $data['service_address'] : [],
    ];

    try {
      $booking_id = self::create_booking_row($payload);
      $response = ['booking_id' => $booking_id];

      if ($price_total <= 0) {
        $order_result = self::create_free_order_for_booking($booking_id);
        if (is_wp_error($order_result)) {
          self::delete_booking_data_by_id($booking_id);
          throw new \Exception($order_result->get_error_message());
        }
        $response['order_id'] = $order_result['order_id'];
        $response['order_received_url'] = $order_result['order_received_url'];
        $response['free_booking'] = true;
      }

      return new \WP_REST_Response($response, 201);
    } catch (\Throwable $e) {
      return new \WP_REST_Response(['error' => $e->getMessage()], 400);
    }
  }

  /**
   * Internal: Create a pending booking row.
   * Expects: listing_id, service_id, customer_id, start_datetime, end_datetime
   */
  private static function create_booking_row(array $data): int {
    global $wpdb;
    $table = DB::table();

    $listing_id  = (int) ($data['listing_id'] ?? 0);
    $provider_id = (int) ($data['provider_id'] ?? 0);
    $resource_id = (int) ($data['resource_id'] ?? 0);
    $service_id  = (int) $data['service_id'];
    $customer_id = (int) $data['customer_id'];

    $context = self::assert_primary_service_is_bookable($listing_id, $provider_id, $resource_id, $service_id);
    $listing_id = (int) $context['listing_id'];
    $provider_id = (int) $context['provider_id'];
    $resource_id = (int) $context['resource_id'];
    $listing_author_id = (int) $context['payee_user_id'];
    $fulfillment = self::fulfillment_context($context, $data);

    $start = sanitize_text_field($data['start_datetime']); // 'YYYY-MM-DD HH:MM:SS'
    $end   = sanitize_text_field($data['end_datetime']);

    // timezone / currency were defaulted at REST boundary; keep them as-is for DB insert.
    $timezone = sanitize_text_field((string)($data['timezone'] ?? 'UTC'));
    $currency = sanitize_text_field((string)($data['currency'] ?? (function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD')));

    // If price not provided, derive from service meta.
    $price = $data['price'] ?? null;
    if ($price === null) {
      $meta_price = get_post_meta($service_id, Services_API::META_PRICE, true);
      if ($meta_price === '' || $meta_price === null) {
        $meta_price = get_post_meta($service_id, '_koopo_price', true);
      }
      $price = is_numeric($meta_price) ? (float) $meta_price : 0.0;
    }

    $submitted_addon_ids = isset($data['addon_ids']) && is_array($data['addon_ids']) ? array_map('absint', $data['addon_ids']) : [];
    $addon_ids = self::assert_addons_are_bookable($resource_id, $submitted_addon_ids);
    if (!empty($addon_ids)) {
      foreach ($addon_ids as $addon_id) {
        $price += self::get_service_price($addon_id);
      }
    }

    $expected_duration = self::get_service_duration_minutes($service_id);
    foreach ($addon_ids as $addon_id) {
      $expected_duration += self::get_service_duration_minutes($addon_id);
    }
    $settings = self::assert_slot_matches_schedule($resource_id, $start, $end, $expected_duration, true);
    if (!empty($fulfillment['travel_buffer_minutes'])) {
      $settings['buffer_before'] = max((int) ($settings['buffer_before'] ?? 0), (int) $fulfillment['travel_buffer_minutes']);
      $settings['buffer_after'] = max((int) ($settings['buffer_after'] ?? 0), (int) $fulfillment['travel_buffer_minutes']);
    }

    // Only these statuses should block time
    // Acquire per-listing lock (short, efficient)
    if (!self::acquire_lock($resource_id, 2)) {
      // If someone else is booking same listing right now, avoid thrashing
      throw new \Exception('This time is being booked right now. Please try again.');
    }

    try {
      $conflict = self::find_conflict_id($resource_id, $start, $end, $settings);

      if ($conflict) {
        throw new \Exception('That time was just booked. Please choose another slot.');
      }

    $status = isset($data['status']) ? sanitize_text_field((string) $data['status']) : 'pending_payment';
    $allowed_statuses = ['pending_invitation', 'pending_payment', 'confirmed'];
    if (!in_array($status, $allowed_statuses, true)) {
      $status = 'pending_payment';
    }

    // Insert booking
    $inserted = $wpdb->insert($table, [
      'listing_id'         => $listing_id,
      'listing_author_id'  => $listing_author_id,
      'provider_id'        => $provider_id ?: null,
      'resource_id'        => $resource_id,
      'location_id'        => (int) $context['location_id'] ?: null,
      'service_area_id'    => (int) ($fulfillment['service_area_id'] ?? 0) ?: null,
      'payee_user_id'      => $listing_author_id,
      'service_id'         => (string) $service_id,
      'customer_id'        => $customer_id,
      'customer_name'      => sanitize_text_field((string) ($data['customer_name'] ?? '')),
      'customer_email'     => sanitize_email((string) ($data['customer_email'] ?? '')),
      'customer_phone'     => sanitize_text_field((string) ($data['customer_phone'] ?? '')),
      'customer_notes'     => sanitize_textarea_field((string) ($data['customer_notes'] ?? '')),
      'fulfillment_mode'   => (string) $fulfillment['fulfillment_mode'],
      'service_address_1'  => (string) ($fulfillment['service_address_1'] ?? ''),
      'service_address_2'  => (string) ($fulfillment['service_address_2'] ?? ''),
      'service_city'       => (string) ($fulfillment['service_city'] ?? ''),
      'service_region'     => (string) ($fulfillment['service_region'] ?? ''),
      'service_postal_code'=> (string) ($fulfillment['service_postal_code'] ?? ''),
      'service_country'    => (string) ($fulfillment['service_country'] ?? ''),
      'service_latitude'   => $fulfillment['service_latitude'] ?? null,
      'service_longitude'  => $fulfillment['service_longitude'] ?? null,
      'travel_buffer_minutes' => (int) ($fulfillment['travel_buffer_minutes'] ?? 0),
      'virtual_provider'   => (string) ($fulfillment['virtual_provider'] ?? ''),
      'virtual_join_url'   => (string) ($fulfillment['virtual_join_url'] ?? ''),
      'virtual_instructions' => (string) ($fulfillment['virtual_instructions'] ?? ''),
      'booking_for_other'  => !empty($data['booking_for_other']) ? 1 : 0,
      'addon_ids'          => !empty($addon_ids) ? wp_json_encode($addon_ids) : '',
      'start_datetime'     => $start,
      'end_datetime'       => $end,
      'timezone'           => $timezone,
      'price'              => (float) $price,
      'currency'           => $currency,
      'status'             => $status,
      'created_at'         => current_time('mysql'),
      'updated_at'         => current_time('mysql'),
    ], [
      '%d','%d','%d','%d','%d','%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%d','%s','%s','%s','%d','%s','%s','%s','%s','%f','%s','%s','%s','%s'
    ]);

      if (!$inserted) {
        throw new \Exception('Failed to create booking.');
      }

      $booking_id = (int) $wpdb->insert_id;
      if ($status === 'pending_payment') {
        $hold_minutes = max(1, (int) apply_filters('koopo_appt_pending_expire_minutes', 10));
        self::set_hold_expires_at($booking_id, gmdate('Y-m-d H:i:s', time() + ($hold_minutes * MINUTE_IN_SECONDS)));
      }
      if ($status === 'pending_payment') {
        $booking = self::get_booking($booking_id);
        if ($booking) {
          do_action('koopo_booking_pending_payment', $booking_id, $booking);
        }
      }

      return $booking_id;

    } finally {
      self::release_lock($resource_id);
    }
  }

  private static function normalize_addon_ids(int $resource_id, array $addon_ids): array {
    if (empty($addon_ids)) {
      return [];
    }
    $addon_ids = array_map('absint', $addon_ids);
    return array_values(array_filter($addon_ids, function($addon_id) use ($resource_id) {
      if (!$addon_id) return false;
      $addon = get_post($addon_id);
      if (!$addon || $addon->post_type !== Services_CPT::POST_TYPE || $addon->post_status !== 'publish') {
        return false;
      }
      $is_addon = get_post_meta($addon_id, Services_API::META_ADDON, true) === '1';
      $addon_resource = Resources::for_service($addon_id);
      return $is_addon && $addon_resource && (int) $addon_resource->id === $resource_id && self::is_service_active($addon_id);
    }));
  }

  private static function get_service_price(int $service_id): float {
    $meta_price = get_post_meta($service_id, Services_API::META_PRICE, true);
    if ($meta_price === '' || $meta_price === null) {
      $meta_price = get_post_meta($service_id, '_koopo_price', true);
    }
    return is_numeric($meta_price) ? (float) $meta_price : 0.0;
  }

  private static function calculate_price_total(int $service_id, array $addon_ids): float {
    $price = self::get_service_price($service_id);
    if (!empty($addon_ids)) {
      foreach ($addon_ids as $addon_id) {
        $price += self::get_service_price($addon_id);
      }
    }
    return (float) $price;
  }

  public static function maybe_sync_dokan_order($order): void {
    if (!function_exists('dokan_sync_insert_order')) {
      return;
    }

    if (!$order instanceof \WC_Order) {
      $order = wc_get_order($order);
    }

    if (!$order) {
      return;
    }

    if (function_exists('dokan') && isset(dokan()->order) && is_callable([dokan()->order, 'maybe_split_orders'])) {
      dokan()->order->maybe_split_orders($order->get_id());
    }

    dokan_sync_insert_order($order);

    if (class_exists('\\WeDevs\\Dokan\\Analytics\\Reports\\Orders\\Stats\\DataStore')) {
      \WeDevs\Dokan\Analytics\Reports\Orders\Stats\DataStore::sync_order($order->get_id());
    }
  }

  public static function create_free_order_for_booking(int $booking_id) {
    $booking = self::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('koopo_booking_not_found', 'Booking not found');
    }

    $existing_order_id = (int) ($booking->wc_order_id ?? 0);
    if ($existing_order_id) {
      $existing_order = wc_get_order($existing_order_id);
      if ($existing_order) {
        return [
          'order_id' => $existing_order_id,
          'order_received_url' => $existing_order->get_checkout_order_received_url(),
        ];
      }
    }

    $service_id = absint($booking->service_id);
    if (!$service_id) {
      return new \WP_Error('koopo_service_missing', 'Booking missing service');
    }

    $product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);
    if (!$product_id) {
      $product_id = (int) WC_Service_Product::create_or_update_for_service($service_id);
    }
    if (!$product_id) {
      return new \WP_Error('koopo_service_product_missing', 'Service product not found');
    }

    $product = wc_get_product($product_id);
    if (!$product) {
      return new \WP_Error('koopo_service_product_invalid', 'Service product invalid');
    }

    $order = wc_create_order(['customer_id' => (int) $booking->customer_id]);
    if (is_wp_error($order)) {
      return $order;
    }
    if (!$order instanceof \WC_Order) {
      $order = wc_get_order($order);
    }
    if (!$order) {
      return new \WP_Error('koopo_order_failed', 'Failed to create order');
    }

    $item_id = $order->add_product($product, 1, [
      'subtotal' => 0,
      'total' => 0,
    ]);
    if (!$item_id) {
      return new \WP_Error('koopo_order_item_failed', 'Failed to add order item');
    }

    $item = $order->get_item($item_id);
    if ($item) {
      $item->add_meta_data('_koopo_booking_id', (int) $booking_id, true);
      $item->add_meta_data('_koopo_listing_id', (int) $booking->listing_id, true);
      $item->add_meta_data('_koopo_provider_id', (int) ($booking->provider_id ?? 0), true);
      $item->add_meta_data('_koopo_resource_id', (int) ($booking->resource_id ?? 0), true);
      $item->add_meta_data('_koopo_payee_user_id', (int) ($booking->payee_user_id ?: $booking->listing_author_id), true);
      $item->add_meta_data('_koopo_listing_author_id', (int) $booking->listing_author_id, true);
      $item->add_meta_data('_koopo_service_id', (string) $booking->service_id, true);
      $item->add_meta_data('_koopo_start_datetime', (string) $booking->start_datetime, true);
      $item->add_meta_data('_koopo_end_datetime', (string) $booking->end_datetime, true);
      $item->add_meta_data('_koopo_price', (string) $booking->price, true);
      $item->add_meta_data('_koopo_currency', (string) $booking->currency, true);
      if (!empty($booking->timezone)) {
        $item->add_meta_data('_koopo_timezone', (string) $booking->timezone, true);
      }
      $item->save();
    }

    $order->update_meta_data('_koopo_booking_ids', [(int) $booking_id]);
    if (!empty($booking->currency)) {
      $order->set_currency((string) $booking->currency);
    }

    $customer_name = (string) self::extra_from_record($booking, 'customer_name', '');
    $customer_email = (string) self::extra_from_record($booking, 'customer_email', '');
    $customer_phone = (string) self::extra_from_record($booking, 'customer_phone', '');
    $user = $booking->customer_id ? get_userdata((int) $booking->customer_id) : null;
    $first_name = '';
    $last_name = '';
    if ($customer_name) {
      $parts = preg_split('/\s+/', trim($customer_name));
      $first_name = $parts[0] ?? '';
      $last_name = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
    } elseif ($user) {
      $first_name = $user->first_name ?? '';
      $last_name = $user->last_name ?? '';
      $customer_email = $customer_email ?: ($user->user_email ?? '');
    }

    if ($first_name) $order->set_billing_first_name($first_name);
    if ($last_name) $order->set_billing_last_name($last_name);
    if ($customer_email) $order->set_billing_email($customer_email);
    if ($customer_phone) $order->set_billing_phone($customer_phone);

    if (!empty($booking->listing_author_id)) {
      $order->update_meta_data('_dokan_vendor_id', (int) $booking->listing_author_id);
    }

    $order->set_total(0);
    $order->save();

    self::set_order_id($booking_id, $order->get_id());
    if ((string) $booking->status !== 'confirmed') {
      self::set_status($booking_id, 'confirmed');
    }

    $order->update_status('completed', 'Koopo free booking auto-confirmed.', true);
    self::maybe_notify_confirmed_booking($booking_id);

    return [
      'order_id' => (int) $order->get_id(),
      'order_received_url' => $order->get_checkout_order_received_url(),
    ];
  }

  /**
   * Create a booking initiated by a vendor (manual booking).
   */
  public static function create_manual_booking(array $data): int {
    $data['status'] = $data['status'] ?? 'confirmed';
    return self::create_booking_row($data);
  }

public static function confirm_booking_safely(int $booking_id): array {
  global $wpdb;
  $table = DB::table();

  $booking = self::get_booking($booking_id);
  if (!$booking) {
    return ['ok' => false, 'reason' => 'not_found'];
  }

  // If already confirmed (idempotent)
  if ($booking->status === 'confirmed') {
    return ['ok' => true, 'reason' => 'already_confirmed'];
  }

  // Only confirm from pending_payment (or allow other statuses via filter)
  $allowed_from = apply_filters('koopo_appt_confirm_allowed_statuses', ['pending_payment'], (int)$booking->listing_id);
  if (!in_array($booking->status, $allowed_from, true)) {
    return ['ok' => false, 'reason' => 'bad_status:' . $booking->status];
  }

  $listing_id = (int)$booking->listing_id;
  $resource_id = Resources::booking_resource_id($booking);
  if (!$resource_id) return ['ok' => false, 'reason' => 'resource_not_found'];

  if (!self::acquire_lock($resource_id, 2)) {
    return ['ok' => false, 'reason' => 'lock_timeout'];
  }

  try {
    // Refresh booking inside lock (avoid stale reads)
    $booking = self::get_booking($booking_id);
    if (!$booking) return ['ok' => false, 'reason' => 'not_found'];

    // Another payment/status callback may have confirmed this booking while
    // this request waited for the listing lock.
    if ($booking->status === 'confirmed') {
      return ['ok' => true, 'reason' => 'already_confirmed'];
    }

    // If expired, do not confirm
    if ($booking->status === 'expired') {
      return ['ok' => false, 'reason' => 'expired'];
    }

    if (!in_array($booking->status, $allowed_from, true)) {
      return ['ok' => false, 'reason' => 'bad_status:' . $booking->status];
    }

    $start = $booking->start_datetime;
    $end   = $booking->end_datetime;

    $settings = Settings_API::read_settings(Resources::settings_post_id($resource_id));
    $travel_buffer = min(240, max(0, (int) self::extra_from_record($booking, 'travel_buffer_minutes', 0)));
    if ($travel_buffer > 0) {
      $settings['buffer_before'] = max((int) ($settings['buffer_before'] ?? 0), $travel_buffer);
      $settings['buffer_after'] = max((int) ($settings['buffer_after'] ?? 0), $travel_buffer);
    }
    $conflict_id = self::find_conflict_id($resource_id, $start, $end, $settings, $booking_id);

    if ($conflict_id !== 0) {
      // Mark conflict (paid but cannot be honored)
      $wpdb->update(
        $table,
        ['status' => 'conflict', 'updated_at' => current_time('mysql')],
        ['id' => $booking_id],
        ['%s','%s'],
        ['%d']
      );

      do_action('koopo_booking_conflict', $booking_id, $conflict_id, $booking);

      return ['ok' => false, 'reason' => 'conflict', 'conflict_id' => $conflict_id];
    }

    // Confirm safely
    $wpdb->update(
      $table,
      ['status' => 'confirmed', 'updated_at' => current_time('mysql')],
      ['id' => $booking_id],
      ['%s','%s'],
      ['%d']
    );

    do_action('koopo_booking_confirmed_safe', $booking_id, $booking);

    return ['ok' => true, 'reason' => 'confirmed'];

  } finally {
    self::release_lock($resource_id);
  }
}

/**
 * Safely move a booking into a terminal non-blocking status (cancelled/refunded).
 * This releases the slot because availability/creation blocking statuses exclude these.
 */

/**
 * Customer cancellation policy.
 * - pending_payment: always cancellable by the customer (no payment captured yet)
 * - confirmed: cancellable up to a cutoff window before start time
 */
public static function customer_can_cancel($booking): bool {
  if (!$booking) return false;

  $status = is_array($booking) ? ($booking['status'] ?? '') : ($booking->status ?? '');
  $status = (string) $status;

  if ($status === 'pending_payment') {
    return true;
  }

  if ($status !== 'confirmed') {
    return false;
  }

  $start = is_array($booking) ? ($booking['start_datetime'] ?? '') : ($booking->start_datetime ?? '');
  if (!$start) return false;

  $tz = is_array($booking) ? ($booking['timezone'] ?? '') : ($booking->timezone ?? '');
  $tz = $tz ? (string) $tz : wp_timezone_string();
  try {
    $zone = new \DateTimeZone($tz ?: 'UTC');
  } catch (\Exception $e) {
    $zone = new \DateTimeZone('UTC');
  }

  try {
    $start_dt = new \DateTimeImmutable((string) $start, $zone);
  } catch (\Exception $e) {
    return false;
  }

  $cutoff_hours = (int) apply_filters('koopo_appt_customer_cancel_cutoff_hours', 24, $booking);
  if ($cutoff_hours < 0) $cutoff_hours = 0;

  $now = new \DateTimeImmutable('now', $zone);
  $latest_cancel = $start_dt->modify('-' . $cutoff_hours . ' hours');

  return $now < $latest_cancel;
}

public static function cancel_booking_safely(int $booking_id, string $new_status = 'cancelled'): array {
  global $wpdb;
  $table = DB::table();

  $booking = self::get_booking($booking_id);
  if (!$booking) {
    return ['ok' => false, 'reason' => 'not_found'];
  }

  $new_status = sanitize_key($new_status);
  if (!in_array($new_status, ['cancelled', 'refunded'], true)) {
    $new_status = 'cancelled';
  }

  // Idempotent
  if ($booking->status === $new_status) {
    return ['ok' => true, 'reason' => 'already_' . $new_status];
  }

  // Don't override expired bookings.
  if ($booking->status === 'expired') {
    return ['ok' => true, 'reason' => 'already_expired'];
  }

  $resource_id = Resources::booking_resource_id($booking);
  if (!$resource_id || !self::acquire_lock($resource_id, 2)) {
    return ['ok' => false, 'reason' => 'lock_timeout'];
  }

  try {
    // Refresh inside lock
    $booking = self::get_booking($booking_id);
    if (!$booking) return ['ok' => false, 'reason' => 'not_found'];

    // Allow an explicit cancelled -> refunded transition (e.g. refund posted after cancellation).
    $is_cancelled_to_refunded = ($booking->status === 'cancelled' && $new_status === 'refunded');

    // Otherwise if already terminal, keep idempotent behavior.
    if (!$is_cancelled_to_refunded && in_array($booking->status, ['cancelled', 'refunded', 'expired'], true)) {
      return ['ok' => true, 'reason' => 'already_' . $booking->status];
    }

    $wpdb->update(
      $table,
      ['status' => $new_status, 'updated_at' => current_time('mysql')],
      ['id' => (int) $booking_id],
      ['%s','%s'],
      ['%d']
    );

    $updated_booking = self::get_booking($booking_id);
    do_action('koopo_booking_cancelled_safe', $booking_id, $new_status, $updated_booking ?: $booking);
    if ($new_status === 'refunded') {
      do_action('koopo_booking_refunded_safe', $booking_id, $updated_booking ?: $booking);
    }

    return ['ok' => true, 'reason' => $new_status];
  } finally {
    self::release_lock($resource_id);
  }
}



  /**
   * Reschedule a booking safely with overlap protection.
   * Vendor/admin use-case.
   */
  public static function reschedule_booking_safely(int $booking_id, string $new_start, string $new_end, ?string $timezone = null): bool {
    global $wpdb;
    $table = DB::table();

    $booking = self::get_booking(booking_id: $booking_id);
    if (!$booking) {
      return false;
    }
    
    $resource_id = Resources::booking_resource_id($booking);
    if (!$resource_id) return false;

    // Basic validation
    $new_start = sanitize_text_field($new_start);
    $new_end   = sanitize_text_field($new_end);

    if (!$new_start || !$new_end || strtotime($new_end) <= strtotime($new_start)) {
      return false;
    }
    $expected_duration = (int) round((strtotime((string) $booking->end_datetime) - strtotime((string) $booking->start_datetime)) / 60);
    if ($expected_duration < 1) {
      return false;
    }

    try {
      $settings = self::assert_slot_matches_schedule($resource_id, $new_start, $new_end, $expected_duration, false);
      $travel_buffer = min(240, max(0, (int) self::extra_from_record($booking, 'travel_buffer_minutes', 0)));
      if ($travel_buffer > 0) {
        $settings['buffer_before'] = max((int) ($settings['buffer_before'] ?? 0), $travel_buffer);
        $settings['buffer_after'] = max((int) ($settings['buffer_after'] ?? 0), $travel_buffer);
      }
    } catch (\Throwable $e) {
      return false;
    }

    if (!self::acquire_lock($resource_id, 2)) {
      return false;
    }

    try {
      $conflict = self::find_conflict_id($resource_id, $new_start, $new_end, $settings, $booking_id);
      if ($conflict) {
        return false;
      }

      $data = [
        'start_datetime' => $new_start,
        'end_datetime'   => $new_end,
        'updated_at'     => current_time('mysql'),
      ];
      $format = ['%s','%s','%s'];

      if ($timezone !== null && $timezone !== '') {
        $data['timezone'] = sanitize_text_field($timezone);
        $format[] = '%s';
      }

      $updated = $wpdb->update($table, $data, ['id' => $booking_id], $format, ['%d']);
      return $updated !== false;

    } finally {
      self::release_lock($resource_id);
    }
  }

public static function init_cleanup_cron() {
  add_action('koopo_appt_cleanup_pending', [__CLASS__, 'cleanup_pending']);

  // Custom schedule: every 5 minutes (for short booking holds)
  add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['koopo_appt_five_minutes'])) {
      $schedules['koopo_appt_five_minutes'] = [
        'interval' => 5 * 60,
        'display'  => did_action('init') ? __('Every 5 Minutes (Koopo Appointments)', 'koopo-appointments') : 'Every 5 Minutes (Koopo Appointments)',
      ];
    }
    return $schedules;
  });

  if (!wp_next_scheduled('koopo_appt_cleanup_pending')) {
    wp_schedule_event(time() + 60, 'koopo_appt_five_minutes', 'koopo_appt_cleanup_pending');
  }
}

  public static function cleanup_pending() {
  global $wpdb;
  $table = DB::table();

  $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);

  // Only expire holds that never created an order (abandoned checkout).
    // Only expire holds that never created an order (abandoned checkout).
  $ids = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$table}
     WHERE status = 'pending_payment'
       AND (wc_order_id IS NULL OR wc_order_id = 0)
       AND ((hold_expires_at IS NOT NULL AND hold_expires_at <= UTC_TIMESTAMP())
         OR (hold_expires_at IS NULL AND created_at < (NOW() - INTERVAL %d MINUTE)))
     LIMIT 200",
    $minutes
  ));

  foreach ($ids as $id) {
    $id = (int) $id;
    $booking = self::get_booking($id);
    if (!$booking) {
      continue;
    }
    self::set_status($id, 'expired');
    self::archive_booking($id, 'abandoned_hold');
    if (apply_filters('koopo_appt_delete_expired_booking', false, $id, $booking)) {
      self::delete_booking_data($id);
    }
  }

  self::cleanup_cancelled_past();
}

  private static function cleanup_cancelled_past(): void {
    global $wpdb;
    $table = DB::table();

    $ids = $wpdb->get_col(
      "SELECT id FROM {$table}
       WHERE status = 'cancelled'
         AND end_datetime < UTC_TIMESTAMP()
         AND archived_at IS NULL
       LIMIT 200"
    );

    foreach ($ids as $id) {
      $id = (int) $id;
      $booking = self::get_booking($id);
      if (!$booking) {
        continue;
      }
      $archived = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET archived_at = UTC_TIMESTAMP(), retention_class = 'business_record', updated_at = UTC_TIMESTAMP()
         WHERE id = %d AND archived_at IS NULL",
        $id
      ));
      if ($archived === 1) self::record_cancelled_archive($booking);
    }
  }

  /** Archive a booking without destroying its financial, consent, or client history. */
  public static function archive_booking(int $booking_id, string $retention_class = 'business_record'): bool {
    global $wpdb;
    $retention_class = sanitize_key($retention_class);
    if (!in_array($retention_class, ['business_record', 'abandoned_hold'], true)) {
      $retention_class = 'business_record';
    }
    return false !== $wpdb->query($wpdb->prepare(
      'UPDATE ' . DB::table() . '
       SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()), retention_class = %s, updated_at = UTC_TIMESTAMP()
       WHERE id = %d',
      $retention_class,
      $booking_id
    ));
  }

  private static function record_cancelled_archive(object $booking): void {
    $listing_id = isset($booking->listing_id) ? (int) $booking->listing_id : 0;
    if ($listing_id < 1) {
      return;
    }
    $total_key = 'koopo_appt_cancelled_archive_total_' . $listing_id;
    $daily_key = 'koopo_appt_cancelled_archive_daily_' . $listing_id;

    $total = (int) get_option($total_key, 0);
    update_option($total_key, $total + 1);

    $daily = get_option($daily_key, []);
    if (!is_array($daily)) {
      $daily = [];
    }
    $day = date('Y-m-d');
    $daily[$day] = isset($daily[$day]) ? ((int) $daily[$day] + 1) : 1;
    update_option($daily_key, $daily);
  }

  public static function delete_booking_data_by_id(int $booking_id): void {
    self::delete_booking_data($booking_id);
  }

  private static function delete_booking_data(int $booking_id): void {
    global $wpdb;
    $table = DB::table();
    $booking_id = (int) $booking_id;
    $wpdb->delete(DB::notification_deliveries_table(), ['booking_id' => $booking_id], ['%d']);
    $wpdb->delete(DB::booking_invites_table(), ['booking_id' => $booking_id], ['%d']);
    $wpdb->delete($table, ['id' => $booking_id], ['%d']);
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
      $wpdb->esc_like("koopo_booking_{$booking_id}_") . '%'
    ));
  }

  private static function normalize_extra_update(array $data): array {
    $out = [];
    foreach ($data as $key => $value) {
      if (!array_key_exists($key, self::EXTRA_FIELD_DEFAULTS)) continue;
      switch ($key) {
        case 'customer_email':
          $out[$key] = sanitize_email((string) $value);
          break;
        case 'customer_notes':
        case 'cancel_reason':
          $out[$key] = sanitize_textarea_field((string) $value);
          break;
        case 'booking_for_other':
          $out[$key] = !empty($value) ? 1 : 0;
          break;
        case 'refund_amount':
          $out[$key] = is_numeric($value) ? (float) $value : 0.0;
          break;
        case 'addon_ids':
          if (is_array($value)) {
            $value = array_values(array_filter(array_map('absint', $value)));
            $out[$key] = wp_json_encode($value);
          } else {
            $out[$key] = sanitize_text_field((string) $value);
          }
          break;
        case 'review_invite_sent':
          $value = sanitize_text_field((string) $value);
          $out[$key] = $value !== '' ? $value : null;
          break;
        default:
          $out[$key] = sanitize_text_field((string) $value);
          break;
      }
    }
    return $out;
  }

  private static function extra_update_formats(array $data): array {
    $formats = [];
    foreach ($data as $key => $value) {
      if ($key === 'booking_for_other') {
        $formats[] = '%d';
      } elseif ($key === 'refund_amount') {
        $formats[] = '%f';
      } else {
        $formats[] = '%s';
      }
    }
    return $formats;
  }

  public static function update_booking_extras(int $booking_id, array $data): bool {
    global $wpdb;
    $table = DB::table();
    $booking_id = (int) $booking_id;
    if ($booking_id < 1) return false;

    $update = self::normalize_extra_update($data);
    if (!$update) return false;

    $result = $wpdb->update(
      $table,
      $update,
      ['id' => $booking_id],
      self::extra_update_formats($update),
      ['%d']
    );

    return $result !== false;
  }

  private static function cast_extra_value(string $key, $value) {
    if ($value === null) return null;
    switch ($key) {
      case 'booking_for_other':
        return !empty($value) ? 1 : 0;
      case 'refund_amount':
        return is_numeric($value) ? (float) $value : 0.0;
      default:
        return is_string($value) ? $value : (string) $value;
    }
  }

  public static function get_booking_extra(int $booking_id, string $key, $default = '') {
    if ($booking_id < 1 || !array_key_exists($key, self::EXTRA_FIELD_DEFAULTS)) {
      return $default;
    }
    $booking = self::get_booking($booking_id);
    if ($booking && property_exists($booking, $key)) {
      $value = $booking->{$key};
      if ($value !== null && $value !== '') {
        return self::cast_extra_value($key, $value);
      }
    }

    $legacy = get_option("koopo_booking_{$booking_id}_{$key}", null);
    if ($legacy !== null && $legacy !== false && $legacy !== '') {
      return self::cast_extra_value($key, $legacy);
    }

    return $default;
  }

  public static function extra_from_record($booking, string $key, $default = '') {
    if (!array_key_exists($key, self::EXTRA_FIELD_DEFAULTS)) {
      return $default;
    }

    if (is_array($booking) && array_key_exists($key, $booking)) {
      $value = $booking[$key];
      if ($value !== null && $value !== '') {
        return self::cast_extra_value($key, $value);
      }
    } elseif (is_object($booking) && property_exists($booking, $key)) {
      $value = $booking->{$key};
      if ($value !== null && $value !== '') {
        return self::cast_extra_value($key, $value);
      }
    }

    $booking_id = 0;
    if (is_array($booking) && !empty($booking['id'])) {
      $booking_id = (int) $booking['id'];
    } elseif (is_object($booking) && !empty($booking->id)) {
      $booking_id = (int) $booking->id;
    }
    if ($booking_id > 0) {
      return self::get_booking_extra($booking_id, $key, $default);
    }

    return $default;
  }


  public static function get_booking($booking_id) {
    global $wpdb;
    $table = DB::table();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $booking_id));
  }

  public static function set_order_id($booking_id, $order_id) {
    global $wpdb;
    $table = DB::table();
    $wpdb->update($table, ['wc_order_id' => (int)$order_id], ['id' => (int)$booking_id], ['%d'], ['%d']);
  }

  /**
   * Assign an order without overwriting an order created by another request.
   *
   * @return int The order currently assigned to the booking, or zero when the
   *             booking no longer exists.
   */
  public static function assign_order_id_if_empty(int $booking_id, int $order_id): int {
    global $wpdb;
    if ($booking_id < 1 || $order_id < 1) return 0;

    $table = DB::table();
    $wpdb->query($wpdb->prepare(
      "UPDATE {$table}
       SET wc_order_id = %d, updated_at = UTC_TIMESTAMP()
       WHERE id = %d AND (wc_order_id IS NULL OR wc_order_id = 0)",
      $order_id,
      $booking_id
    ));

    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT wc_order_id FROM {$table} WHERE id = %d",
      $booking_id
    ));
  }

  public static function set_hold_expires_at(int $booking_id, ?string $expires_at): bool {
    global $wpdb;
    return false !== $wpdb->update(
      DB::table(),
      ['hold_expires_at' => $expires_at ?: null, 'updated_at' => current_time('mysql')],
      ['id' => $booking_id],
      ['%s', '%s'],
      ['%d']
    );
  }

  public static function set_inbox_thread_id(int $booking_id, int $thread_id): bool {
    global $wpdb;
    return false !== $wpdb->update(
      DB::table(),
      ['inbox_thread_id' => $thread_id ?: null, 'updated_at' => current_time('mysql')],
      ['id' => $booking_id],
      ['%d', '%s'],
      ['%d']
    );
  }

  public static function set_status($booking_id, $status) {
    global $wpdb;
    $table = DB::table();

    $booking_id = (int) $booking_id;
    $old = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $booking_id));

    $wpdb->update($table, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $booking_id], ['%s','%s'], ['%d']);

    $booking = self::get_booking($booking_id);
    do_action('koopo_booking_status_changed', $booking_id, $old, $status, $booking);

    if ($status === 'expired') {
      do_action('koopo_booking_expired_safe', $booking_id, $booking);
    }
  }

  public static function get_bookings_for_customer(int $customer_id, int $limit = 50, int $offset = 0) {
    global $wpdb;
    $table = DB::table();
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE customer_id = %d ORDER BY start_datetime DESC LIMIT %d OFFSET %d",
        $customer_id,
        $limit,
        $offset
      )
    );
  }

}
