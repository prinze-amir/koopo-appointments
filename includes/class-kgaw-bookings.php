<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Bookings {

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
    $statuses = apply_filters('koopo_appt_blocking_statuses', ['pending_payment','confirmed'], $listing_id);
    // normalize: unique, non-empty strings
    $statuses = array_values(array_unique(array_filter(array_map('strval', (array)$statuses))));
    return $statuses ?: ['pending_payment','confirmed'];
  }

private static function acquire_lock(int $listing_id, int $timeout_seconds = 2): bool {
  global $wpdb;
  $key = 'koopo_appt_listing_' . $listing_id;
  $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $key, $timeout_seconds));
  return (string)$got === '1';
}

private static function release_lock(int $listing_id): void {
  global $wpdb;
  $key = 'koopo_appt_listing_' . $listing_id;
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
    $service_id = absint($data['service_id'] ?? 0);
    $start      = sanitize_text_field((string)($data['start_datetime'] ?? ''));
    $end        = sanitize_text_field((string)($data['end_datetime'] ?? ''));

    if (!$listing_id || !$service_id || !$start || !$end) {
      return new \WP_REST_Response([
        'error' => 'listing_id, service_id, start_datetime, end_datetime are required'
      ], 400);
    }

    $customer_id = get_current_user_id();
    if (!$customer_id) {
      return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
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
    $payload = [
      'listing_id'      => $listing_id,
      'service_id'      => $service_id,
      'customer_id'     => $customer_id,
      'start_datetime'  => $start,
      'end_datetime'    => $end,
      'timezone'        => $timezone,
      'currency'        => $currency,
      'price'           => isset($data['price']) ? (float) $data['price'] : null,
    ];

    try {
      $booking_id = self::create_booking_row($payload);
      return new \WP_REST_Response(['booking_id' => $booking_id], 201);
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

    $listing_id  = (int) $data['listing_id'];
    $service_id  = (int) $data['service_id'];
    $customer_id = (int) $data['customer_id'];

    // Vendor = listing author (GeoDirectory listing owner)
    $listing = get_post($listing_id);
    if (!$listing) {
      throw new \Exception('Listing not found.');
    }
    $listing_author_id = (int) $listing->post_author;

    $start = sanitize_text_field($data['start_datetime']); // 'YYYY-MM-DD HH:MM:SS'
    $end   = sanitize_text_field($data['end_datetime']);

    // timezone / currency were defaulted at REST boundary; keep them as-is for DB insert.
    $timezone = sanitize_text_field((string)($data['timezone'] ?? 'UTC'));
    $currency = sanitize_text_field((string)($data['currency'] ?? (function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD')));

    // If price not provided, derive from service meta.
    $price = $data['price'];
    if ($price === null) {
      $meta_price = get_post_meta($service_id, Services_API::META_PRICE, true);
      if ($meta_price === '' || $meta_price === null) {
        $meta_price = get_post_meta($service_id, '_koopo_price', true);
      }
      $price = is_numeric($meta_price) ? (float) $meta_price : 0.0;
    }

    // Only these statuses should block time
    $blocking_statuses = self::get_blocking_statuses($listing_id);

    // Acquire per-listing lock (short, efficient)
    if (!self::acquire_lock($listing_id, 2)) {
      // If someone else is booking same listing right now, avoid thrashing
      throw new \Exception('This time is being booked right now. Please try again.');
    }

    try {
      // Overlap check (fast due to index)
      $placeholders = implode(',', array_fill(0, count($blocking_statuses), '%s'));

      $sql = $wpdb->prepare(
        "SELECT id
         FROM {$table}
         WHERE listing_id = %d
           AND status IN ({$placeholders})
           AND start_datetime < %s
           AND end_datetime > %s
         LIMIT 1",
        array_merge([$listing_id], $blocking_statuses, [$end, $start])
      );

      $conflict = $wpdb->get_var($sql);

      if ($conflict) {
        throw new \Exception('That time was just booked. Please choose another slot.');
      }

      // Insert booking as pending_payment
      $inserted = $wpdb->insert($table, [
        'listing_id'         => $listing_id,
        'listing_author_id'  => $listing_author_id,
        'service_id'         => (string) $service_id,
        'customer_id'        => $customer_id,
        'start_datetime'     => $start,
        'end_datetime'       => $end,
        'timezone'           => $timezone,
        'price'              => (float) $price,
        'currency'           => $currency,
        'status'             => 'pending_payment',
        'created_at'         => current_time('mysql'),
        'updated_at'         => current_time('mysql'),
      ], [
        '%d','%d','%s','%d','%s','%s','%s','%f','%s','%s','%s','%s'
      ]);

      if (!$inserted) {
        throw new \Exception('Failed to create booking.');
      }

      return (int) $wpdb->insert_id;

    } finally {
      self::release_lock($listing_id);
    }
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

  if (!self::acquire_lock($listing_id, 2)) {
    return ['ok' => false, 'reason' => 'lock_timeout'];
  }

  try {
    // Refresh booking inside lock (avoid stale reads)
    $booking = self::get_booking($booking_id);
    if (!$booking) return ['ok' => false, 'reason' => 'not_found'];

    // If expired, do not confirm
    if ($booking->status === 'expired') {
      return ['ok' => false, 'reason' => 'expired'];
    }

    $start = $booking->start_datetime;
    $end   = $booking->end_datetime;

    // Only CONFIRMED bookings should block final confirmation
    // (pending_payment already blocked at creation time; this is the final safety check)
    $sql = $wpdb->prepare(
      "SELECT id
       FROM {$table}
       WHERE listing_id = %d
         AND status = 'confirmed'
         AND id <> %d
         AND start_datetime < %s
         AND end_datetime > %s
       LIMIT 1",
      $listing_id,
      $booking_id,
      $end,
      $start
    );

    $conflict_id = (int)$wpdb->get_var($sql);

    if ($conflict_id > 0) {
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
    self::release_lock($listing_id);
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

  $listing_id = (int) $booking->listing_id;
  if (!self::acquire_lock($listing_id, 2)) {
    return ['ok' => false, 'reason' => 'lock_timeout'];
  }

  try {
    // Refresh inside lock
    $booking = self::get_booking($booking_id);
    if (!$booking) return ['ok' => false, 'reason' => 'not_found'];

    // If already terminal, keep idempotent behavior
    if (in_array($booking->status, ['cancelled', 'refunded', 'expired'], true)) {
      return ['ok' => true, 'reason' => 'already_' . $booking->status];
    }

    $wpdb->update(
      $table,
      ['status' => $new_status, 'updated_at' => current_time('mysql')],
      ['id' => (int) $booking_id],
      ['%s','%s'],
      ['%d']
    );

    do_action('koopo_booking_cancelled_safe', $booking_id, $new_status, $booking);

    return ['ok' => true, 'reason' => $new_status];
  } finally {
    self::release_lock($listing_id);
  }
}



  /**
   * Reschedule a booking safely with overlap protection.
   * Vendor/admin use-case.
   */
  public static function reschedule_booking_safely(int $booking_id, string $new_start, string $new_end, ?string $timezone = null): bool {
    global $wpdb;
    $table = DB::table();

    $booking = self::get_booking($booking_id);
    if (!$booking) {
      return false;
    }

    $listing_id = (int) $booking['listing_id'];

    // Basic validation
    $new_start = sanitize_text_field($new_start);
    $new_end   = sanitize_text_field($new_end);

    if (!$new_start || !$new_end || strtotime($new_end) <= strtotime($new_start)) {
      return false;
    }

    $blocking_statuses = self::get_blocking_statuses($listing_id);

    if (!self::acquire_lock($listing_id, 2)) {
      return false;
    }

    try {
      // Overlap check excluding this booking id
      $placeholders = implode(',', array_fill(0, count($blocking_statuses), '%s'));

      $sql = $wpdb->prepare(
        "SELECT id
         FROM {$table}
         WHERE listing_id = %d
           AND id <> %d
           AND status IN ({$placeholders})
           AND start_datetime < %s
           AND end_datetime > %s
         LIMIT 1",
        array_merge([$listing_id, $booking_id], $blocking_statuses, [$new_end, $new_start])
      );

      $conflict = $wpdb->get_var($sql);
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
      self::release_lock($listing_id);
    }
  }

public static function init_cleanup_cron() {
  add_action('koopo_appt_cleanup_pending', [__CLASS__, 'cleanup_pending']);

  // Custom schedule: every 5 minutes (for short booking holds)
  add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['koopo_appt_five_minutes'])) {
      $schedules['koopo_appt_five_minutes'] = [
        'interval' => 5 * 60,
        'display'  => __('Every 5 Minutes (Koopo Appointments)', 'koopo-appointments'),
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
       AND created_at < (NOW() - INTERVAL %d MINUTE)
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
    do_action('koopo_booking_expired_safe', $id, $booking);
  }
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
