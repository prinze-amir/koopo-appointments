<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 20: Enhanced Vendor Bookings API with Refund Tooling
 * Location: includes/vendor/class-kgaw-vendor-bookings-api.php
 */
class Vendor_Bookings_API {
  private static array $listing_title_cache = [];
  private static array $service_title_cache = [];
  private static array $booking_option_cache = [];
  private static array $service_meta_cache = [];

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route('koopo/v1', '/vendor/bookings', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'list_bookings'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => false],
        'status'     => ['type' => 'string',  'required' => false],
        'search'     => ['type' => 'string',  'required' => false],
        'month'      => ['type' => 'string',  'required' => false],
        'year'       => ['type' => 'string',  'required' => false],
        'range_start' => ['type' => 'string', 'required' => false],
        'range_end'   => ['type' => 'string', 'required' => false],
        'page'       => ['type' => 'integer', 'required' => false, 'default' => 1],
        'per_page'   => ['type' => 'integer', 'required' => false, 'default' => 20],
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/export', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'export_bookings_csv'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => false],
        'status'     => ['type' => 'string',  'required' => false],
        'search'     => ['type' => 'string',  'required' => false],
        'month'      => ['type' => 'string',  'required' => false],
        'year'       => ['type' => 'string',  'required' => false],
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/analytics', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'analytics'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => false],
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/action', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'booking_action'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'action' => ['type' => 'string', 'required' => true],
        'note'   => ['type' => 'string', 'required' => false],
        'amount' => ['type' => 'number', 'required' => false], // NEW: for partial refunds
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/create', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'create_booking'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => true],
        'service_id' => ['type' => 'integer', 'required' => true],
        'start_datetime' => ['type' => 'string', 'required' => true],
        'end_datetime' => ['type' => 'string', 'required' => true],
        'timezone' => ['type' => 'string', 'required' => false],
        'status' => ['type' => 'string', 'required' => false],
        'customer_id' => ['type' => 'integer', 'required' => false],
        'customer_email' => ['type' => 'string', 'required' => false],
        'customer_name' => ['type' => 'string', 'required' => false],
        'customer_phone' => ['type' => 'string', 'required' => false],
        'customer_notes' => ['type' => 'string', 'required' => false],
        'addon_ids' => ['type' => 'array', 'required' => false],
      ],
    ]);

    // NEW: Get refund info for a booking
    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/refund-info', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_refund_info'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);
  }

  private static function analytics_cache_key(int $vendor_id, int $listing_id): string {
    return sprintf('koopo_vendor_analytics_%d_%d', $vendor_id, $listing_id);
  }

  private static function invalidate_analytics_cache_for_booking($booking): void {
    if (!$booking || empty($booking->listing_author_id)) return;
    $vendor_id = (int) $booking->listing_author_id;
    $listing_id = isset($booking->listing_id) ? (int) $booking->listing_id : 0;
    delete_transient(self::analytics_cache_key($vendor_id, 0));
    if ($listing_id) {
      delete_transient(self::analytics_cache_key($vendor_id, $listing_id));
    }
  }

  private static function listing_title(int $listing_id): string {
    if (!$listing_id) return '';
    if (!array_key_exists($listing_id, self::$listing_title_cache)) {
      self::$listing_title_cache[$listing_id] = (string) get_the_title($listing_id);
    }
    return self::$listing_title_cache[$listing_id];
  }

  private static function service_title(int $service_id): string {
    if (!$service_id) return '';
    if (!array_key_exists($service_id, self::$service_title_cache)) {
      self::$service_title_cache[$service_id] = (string) get_the_title($service_id);
    }
    return self::$service_title_cache[$service_id];
  }

  private static function get_service_meta(int $service_id): array {
    if (!$service_id) {
      return ['price' => 0.0, 'duration' => 0, 'color' => ''];
    }
    if (!array_key_exists($service_id, self::$service_meta_cache)) {
      $price = get_post_meta($service_id, Services_API::META_PRICE, true);
      if ($price === '' || $price === null) {
        $price = get_post_meta($service_id, '_koopo_price', true);
      }
      $duration = get_post_meta($service_id, Services_API::META_DURATION, true);
      if ($duration === '' || $duration === null) {
        $duration = get_post_meta($service_id, '_koopo_duration_minutes', true);
      }
      $color = get_post_meta($service_id, Services_API::META_COLOR, true);
      self::$service_meta_cache[$service_id] = [
        'price' => is_numeric($price) ? (float) $price : 0.0,
        'duration' => (int) $duration,
        'color' => is_string($color) ? $color : '',
      ];
    }
    return self::$service_meta_cache[$service_id];
  }

  private static function prime_booking_options(array $booking_ids): void {
    global $wpdb;
    $booking_ids = array_values(array_filter(array_map('absint', $booking_ids)));
    if (!$booking_ids) return;

    $fields = [
      'customer_name',
      'customer_email',
      'customer_phone',
      'booking_for_other',
      'cancelled_by',
      'refund_amount',
      'refund_status',
    ];

    $option_names = [];
    foreach ($booking_ids as $id) {
      foreach ($fields as $field) {
        $option_names[] = "koopo_booking_{$id}_{$field}";
      }
    }
    if (!$option_names) return;

    $placeholders = implode(',', array_fill(0, count($option_names), '%s'));
    $sql = $wpdb->prepare(
      "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
      $option_names
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    foreach ($rows as $row) {
      if (!isset($row['option_name'])) continue;
      if (!preg_match('/^koopo_booking_(\d+)_(.+)$/', $row['option_name'], $m)) continue;
      $booking_id = (int) $m[1];
      $key = $m[2];
      if (!isset(self::$booking_option_cache[$booking_id])) {
        self::$booking_option_cache[$booking_id] = [];
      }
      self::$booking_option_cache[$booking_id][$key] = $row['option_value'];
    }
  }

  private static function get_booking_option(int $booking_id, string $key, string $default = ''): string {
    if (isset(self::$booking_option_cache[$booking_id]) && array_key_exists($key, self::$booking_option_cache[$booking_id])) {
      return (string) self::$booking_option_cache[$booking_id][$key];
    }
    return $default;
  }

  public static function can_access(): bool {
    if (!is_user_logged_in()) return false;
    if (function_exists('dokan_is_user_seller')) {
      $vendor_id = get_current_user_id();
      if (!dokan_is_user_seller($vendor_id)) return false;
      return Access::vendor_has_feature($vendor_id, 'appointments');
    }
    return current_user_can('manage_options');
  }

  public static function list_bookings(\WP_REST_Request $req) {
    global $wpdb;

    $vendor_id = get_current_user_id();
    $table = DB::table();

    $listing_id = absint($req->get_param('listing_id'));
    $status = sanitize_text_field((string) $req->get_param('status'));
    $search = sanitize_text_field((string) $req->get_param('search'));
    $month = sanitize_text_field((string) $req->get_param('month'));
    $year = sanitize_text_field((string) $req->get_param('year'));
    $page = max(1, absint($req->get_param('page')));
    $max_per_page = (int) apply_filters('koopo_appt_vendor_per_page_max', 100, $vendor_id, $listing_id);
    if ($max_per_page < 1) $max_per_page = 100;
    $per_page = min($max_per_page, max(1, absint($req->get_param('per_page'))));
    $range_start = sanitize_text_field((string) $req->get_param('range_start'));
    $range_end = sanitize_text_field((string) $req->get_param('range_end'));

    $where = 'WHERE listing_author_id = %d';
    $params = [$vendor_id];

    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }
    if ($status && $status !== 'all') {
      $where .= ' AND status = %s';
      $params[] = $status;
    }

    $hold_minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($hold_minutes < 1) {
      $hold_minutes = 10;
    }
    $where .= $wpdb->prepare(
      " AND NOT (status = 'pending_payment' AND (wc_order_id IS NULL OR wc_order_id = 0) AND created_at < (NOW() - INTERVAL %d MINUTE))",
      $hold_minutes
    );

    if (!$range_start || !$range_end) {
      if (!$month && !$year && apply_filters('koopo_appt_vendor_default_month_filter', true, $vendor_id, $listing_id)) {
        $month = (string) current_time('n');
        $year = (string) current_time('Y');
      }
      // Month filter
      if ($month && is_numeric($month)) {
        $where .= ' AND MONTH(start_datetime) = %d';
        $params[] = (int) $month;
      }

      // Year filter
      if ($year && is_numeric($year)) {
        $where .= ' AND YEAR(start_datetime) = %d';
        $params[] = (int) $year;
      }
    }

    // Range filter (overrides month/year when both provided)
    if ($range_start && $range_end) {
      $where .= ' AND start_datetime BETWEEN %s AND %s';
      $params[] = $range_start;
      $params[] = $range_end;
    }

    // Search filter (customer name, email, phone from booking meta)
    $search_booking_ids = [];
    if ($search) {
      $search_term = '%' . $wpdb->esc_like($search) . '%';

      // Search in options table for customer details
      $search_sql = $wpdb->prepare(
        "SELECT DISTINCT REPLACE(option_name, 'koopo_booking_', '') as booking_id
         FROM {$wpdb->options}
         WHERE (option_name LIKE 'koopo_booking_%%_customer_name'
                OR option_name LIKE 'koopo_booking_%%_customer_email'
                OR option_name LIKE 'koopo_booking_%%_customer_phone')
         AND option_value LIKE %s",
        $search_term
      );

      $search_results = $wpdb->get_col($search_sql);
      if ($search_results) {
        foreach ($search_results as $result) {
          // Extract booking ID from option_name
          $booking_id = (int) preg_replace('/[^0-9]/', '', $result);
          if ($booking_id > 0) {
            $search_booking_ids[] = $booking_id;
          }
        }
      }

      if (!empty($search_booking_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($search_booking_ids), '%d'));
        $where .= " AND id IN ($id_placeholders)";
        $params = array_merge($params, $search_booking_ids);
      } else {
        // If search term provided but no matches, return empty result
        $where .= ' AND 1=0';
      }
    }

    $sql_count = $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $params);
    $total = (int) $wpdb->get_var($sql_count);

    $offset = ($page - 1) * $per_page;

    $sql_items = $wpdb->prepare(
      "SELECT * FROM {$table} {$where} ORDER BY start_datetime DESC LIMIT %d OFFSET %d",
      array_merge($params, [$per_page, $offset])
    );

    $rows = $wpdb->get_results($sql_items, ARRAY_A) ?: [];
    $items = [];
    if (!$rows) {
      return rest_ensure_response([
        'items' => [],
        'pagination' => [
          'page' => $page,
          'per_page' => $per_page,
          'total' => $total,
          'total_pages' => (int) ceil($total / max(1, $per_page)),
        ],
      ]);
    }

    $booking_ids = [];
    $service_ids = [];
    $listing_ids = [];
    $customer_ids = [];
    foreach ($rows as $r) {
      $booking_ids[] = (int) $r['id'];
      if (!empty($r['service_id'])) $service_ids[] = (int) $r['service_id'];
      if (!empty($r['listing_id'])) $listing_ids[] = (int) $r['listing_id'];
      if (!empty($r['customer_id'])) $customer_ids[] = (int) $r['customer_id'];
    }
    $service_ids = array_values(array_unique($service_ids));
    $listing_ids = array_values(array_unique($listing_ids));
    $customer_ids = array_values(array_unique($customer_ids));

    if (function_exists('_prime_post_caches')) {
      if ($listing_ids) _prime_post_caches($listing_ids, false, false);
      if ($service_ids) _prime_post_caches($service_ids, false, false);
    }
    if ($service_ids) {
      update_postmeta_cache($service_ids);
    }
    if ($customer_ids && function_exists('cache_users')) {
      cache_users($customer_ids);
    }
    self::prime_booking_options($booking_ids);

    foreach ($rows as $r) {
      $listing_title = $r['listing_id'] ? self::listing_title((int)$r['listing_id']) : '';
      $service_id = (int) $r['service_id'];
      $service_title = $service_id ? self::service_title($service_id) : '';
      $service_meta = $service_id ? self::get_service_meta($service_id) : ['price' => 0.0, 'duration' => 0, 'color' => ''];
      $service_color = $service_meta['color'];

      $customer_name = '';
      $customer_email = '';
      $customer_phone = '';
      $customer_avatar = '';
      $customer_profile = '';
      if (!empty($r['customer_id'])) {
        $user = get_userdata((int)$r['customer_id']);
        $customer_name = $user->display_name ?? '';
        $customer_email = $user->user_email ?? '';
        $customer_avatar = get_avatar_url((int)$r['customer_id'], ['size' => 64]) ?: '';
        if (function_exists('bp_core_get_user_domain')) {
          $customer_profile = bp_core_get_user_domain((int)$r['customer_id']);
        } else {
          $customer_profile = get_author_posts_url((int)$r['customer_id']);
        }
      }
      $booking_id = (int) $r['id'];
      if (!$customer_name) {
        $customer_name = self::get_booking_option($booking_id, 'customer_name', '');
      }
      if (!$customer_email) {
        $customer_email = self::get_booking_option($booking_id, 'customer_email', '');
      }
      $customer_phone = self::get_booking_option($booking_id, 'customer_phone', '');
      $booking_for_other = self::get_booking_option($booking_id, 'booking_for_other', '') === '1';
      $cancelled_by = self::get_booking_option($booking_id, 'cancelled_by', '');
      $refund_amount_meta = self::get_booking_option($booking_id, 'refund_amount', '');
      $refund_amount_meta = is_numeric($refund_amount_meta) ? (float) $refund_amount_meta : 0.0;
      $refund_status = (string) self::get_booking_option($booking_id, 'refund_status', '');
      $addon_summary = self::get_addons_summary($booking_id);

      $service_price = $service_meta['price'];
      $service_duration = $service_meta['duration'];

      $tz = !empty($r['timezone']) ? (string)$r['timezone'] : '';

      $start_formatted = Date_Formatter::format($r['start_datetime'], $tz, 'short');
      $end_formatted = Date_Formatter::format($r['end_datetime'], $tz, 'time');

      $start_ts = strtotime($r['start_datetime']);
      $end_ts = strtotime($r['end_datetime']);
      $duration_mins = ($end_ts - $start_ts) / 60;
      $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

      // Format created_at for display
      $created_at_formatted = '';
      if (!empty($r['created_at'])) {
        $created_at_formatted = Date_Formatter::format($r['created_at'], $tz, 'short');
      }

      $items[] = [
        'id' => $booking_id,
        'listing_id' => (int) $r['listing_id'],
        'listing_title' => $listing_title ?: '',
        'service_id' => (int) $r['service_id'],
        'service_title' => $service_title ?: '',
        'service_color' => $service_color ?: '',
        'customer_id' => (int) $r['customer_id'],
        'customer_name' => $customer_name ?: '',
        'customer_email' => $customer_email ?: '',
        'customer_phone' => $customer_phone ?: '',
        'customer_avatar' => $customer_avatar ?: '',
        'customer_profile' => $customer_profile ?: '',
        'customer_is_guest' => empty($r['customer_id']),
        'booking_for_other' => $booking_for_other,
        'start_datetime' => $r['start_datetime'],
        'end_datetime' => $r['end_datetime'],
        'start_datetime_formatted' => $start_formatted,
        'end_datetime_formatted' => $end_formatted,
        'duration_formatted' => $duration_formatted,
        'status' => $r['status'],
        'price' => isset($r['price']) ? (float) $r['price'] : 0.0,
        'currency' => $r['currency'] ?? '',
        'wc_order_id' => isset($r['wc_order_id']) ? (int) $r['wc_order_id'] : 0,
        'created_at' => $r['created_at'] ?? '',
        'created_at_formatted' => $created_at_formatted,
        'timezone' => $tz,
        'service_price' => $service_price,
        'service_duration' => $service_duration,
        'addon_ids' => $addon_summary['ids'],
        'addon_titles' => $addon_summary['titles'],
        'addon_total_price' => $addon_summary['total_price'],
        'addon_total_duration' => $addon_summary['total_duration'],
        'cancelled_by' => $cancelled_by,
        'refund_amount' => $refund_amount_meta,
        'refund_status' => $refund_status,
      ];
    }

    return rest_ensure_response([
      'items' => $items,
      'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => (int) ceil($total / max(1, $per_page)),
      ],
    ]);
  }

  /**
   * NEW: Get refund information for a booking
   * Returns policy, calculated amounts, and gateway capabilities
   */
  public static function get_refund_info(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    
    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('koopo_booking_not_found', 'Booking not found', ['status' => 404]);
    }

    $current = get_current_user_id();
    if ((int) $booking->listing_author_id !== (int) $current && !current_user_can('manage_options')) {
      return new \WP_Error('koopo_forbidden', 'Forbidden', ['status' => 403]);
    }

    // Get refund policy summary
    $policy_summary = Refund_Policy::get_booking_refund_summary($booking);

    // Get WooCommerce refund capabilities
    $order_id = (int) ($booking->wc_order_id ?? 0);
    $wc_info = $order_id ? Refund_Processor::get_refund_info($order_id) : [
      'can_refund' => false,
      'automatic' => false,
      'gateway' => 'None',
      'instructions' => 'No order associated with this booking',
      'available_amount' => 0,
      'already_refunded' => 0,
    ];

    return rest_ensure_response([
      'booking_id' => $booking_id,
      'booking_price' => (float) $booking->price,
      'policy' => $policy_summary,
      'woocommerce' => $wc_info,
    ]);
  }

  public static function booking_action(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $action = sanitize_key((string) $request->get_param('action'));
    $note = (string) $request->get_param('note');

    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('koopo_booking_not_found', 'Booking not found', ['status' => 404]);
    }

    $current = get_current_user_id();
    if ((int) $booking->listing_author_id !== (int) $current && !current_user_can('manage_options')) {
      return new \WP_Error('koopo_forbidden', 'You do not have permission to modify this booking', ['status' => 403]);
    }

    $order_id = (int) ($booking->wc_order_id ?? 0);
    $order = $order_id ? wc_get_order($order_id) : null;

    // === CANCEL ACTION ===
    if ($action === 'cancel') {
      $result = Bookings::cancel_booking_safely($booking_id, 'cancelled');
      
      if (!$result['ok']) {
        return new \WP_Error('koopo_cancel_failed', $result['reason'] ?? 'Cancel failed', ['status' => 409]);
      }
      update_option("koopo_booking_{$booking_id}_cancelled_by", 'vendor');
      update_option("koopo_booking_{$booking_id}_refund_amount", '0');
      update_option("koopo_booking_{$booking_id}_refund_status", 'none');

      if ($order) {
        $order_note = 'Koopo: Vendor cancelled booking #'.$booking_id;
        if ($note) $order_note .= ' — ' . wp_strip_all_tags($note);
        $order->add_order_note($order_note);

        $st = $order->get_status();
        if (in_array($st, ['processing','completed'], true)) {
          $order->update_status('on-hold', 'Koopo: Booking cancelled by vendor; refund may be required.');
        } elseif (in_array($st, ['pending','failed'], true)) {
          $order->update_status('cancelled', 'Koopo: Booking cancelled by vendor before payment.');
        }
      }

      self::invalidate_analytics_cache_for_booking($booking);
      return rest_ensure_response([
        'ok' => true,
        'action' => 'cancelled',
        'message' => 'Booking cancelled successfully',
        'booking' => Bookings::get_booking($booking_id),
      ]);
    }

    // === CONFIRM ACTION ===
    if ($action === 'confirm') {
      $result = Bookings::confirm_booking_safely($booking_id);
      
      if (!$result['ok']) {
        $msg = $result['reason'] ?? 'Confirmation failed';
        if ($msg === 'conflict') {
          $conflict_id = $result['conflict_id'] ?? 0;
          $msg = "Cannot confirm: conflicts with booking #{$conflict_id}";
        }
        return new \WP_Error('koopo_confirm_failed', $msg, ['status' => 409]);
      }

      if ($order) {
        $order_note = 'Koopo: Vendor manually confirmed booking #'.$booking_id;
        if ($note) $order_note .= ' — ' . wp_strip_all_tags($note);
        $order->add_order_note($order_note);
      }

      self::invalidate_analytics_cache_for_booking($booking);
      return rest_ensure_response([
        'ok' => true,
        'action' => 'confirmed',
        'message' => 'Booking confirmed successfully',
        'booking' => Bookings::get_booking($booking_id),
      ]);
    }

    // === RESCHEDULE ACTION ===
    if ($action === 'reschedule') {
      $new_start = sanitize_text_field((string) $request->get_param('start_datetime'));
      $new_end   = sanitize_text_field((string) $request->get_param('end_datetime'));
      $tz        = $request->get_param('timezone');
      $tz        = $tz !== null ? sanitize_text_field((string) $tz) : null;

      if (!$new_start || !$new_end) {
        return new \WP_Error('koopo_dates_required', 'start_datetime and end_datetime are required', ['status' => 400]);
      }

      if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $new_start) ||
          !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $new_end)) {
        return new \WP_Error('koopo_invalid_format', 'Dates must be in YYYY-MM-DD HH:MM:SS format', ['status' => 400]);
      }

      if (strtotime($new_end) <= strtotime($new_start)) {
        return new \WP_Error('koopo_invalid_range', 'End time must be after start time', ['status' => 400]);
      }

      $ok = Bookings::reschedule_booking_safely($booking_id, $new_start, $new_end, $tz);
      
      if (!$ok) {
        return new \WP_Error(
          'koopo_reschedule_failed', 
          'Unable to reschedule: the selected time conflicts with another booking or is invalid',
          ['status' => 409]
        );
      }

      if ($order) {
        $tz_display = $tz ? ' ('.$tz.')' : '';
        $order->add_order_note('Koopo: Vendor rescheduled booking #'.$booking_id.' to '.$new_start.' - '.$new_end.$tz_display);
      }

      do_action('koopo_booking_rescheduled', $booking_id, $new_start, $new_end, $booking);

      self::invalidate_analytics_cache_for_booking($booking);
      return rest_ensure_response([
        'ok' => true,
        'action' => 'rescheduled',
        'message' => 'Booking rescheduled successfully. Customer will be notified.',
        'booking' => Bookings::get_booking($booking_id),
      ]);
    }

    // === NOTE ACTION ===
    if ($action === 'note') {
      if (!$order) {
        return new \WP_Error('koopo_no_order', 'No WooCommerce order is associated with this booking', ['status' => 409]);
      }
      
      $clean = wp_strip_all_tags($note);
      if (!$clean) {
        return new \WP_Error('koopo_note_required', 'Note is required', ['status' => 400]);
      }
      
      $order->add_order_note('Koopo (vendor note) for booking #'.$booking_id.': '.$clean);

      return rest_ensure_response([
        'ok' => true,
        'action' => 'note_added',
        'message' => 'Note added to order',
      ]);
    }

    // === ENHANCED REFUND ACTION (Commit 20) ===
    if ($action === 'refund') {
      if (!$order) {
        return new \WP_Error('koopo_no_order', 'No order found to refund', ['status' => 409]);
      }

      // Step 1: Check refund policy eligibility
      $policy_check = Refund_Policy::is_refundable($booking);
      
      if (!$policy_check['allowed']) {
        return new \WP_Error(
          'koopo_refund_not_allowed',
          $policy_check['reason'],
          ['status' => 409]
        );
      }

      // Step 2: Determine refund amount (can be custom or policy-based)
      $custom_amount = $request->get_param('amount');
      $refund_amount = null;

      if ($custom_amount !== null) {
        // Vendor specified custom amount
        $refund_amount = (float) $custom_amount;
      } else {
        // Use policy-calculated amount
        $calc = Refund_Policy::calculate_refund_amount((float)$booking->price, $booking);
        $refund_amount = $calc['amount'];
      }

      // Step 3: Validate refund amount
      $validation = Refund_Processor::validate_refund($order->get_id(), $refund_amount);
      if (!$validation['valid']) {
        return new \WP_Error('koopo_invalid_refund', $validation['error'], ['status' => 400]);
      }

      // Step 4: Process WooCommerce refund
      $refund_result = Refund_Processor::process_refund(
        $order->get_id(),
        $refund_amount,
        $note,
        $booking_id
      );

      if (!$refund_result['success']) {
        return new \WP_Error('koopo_refund_failed', $refund_result['message'], ['status' => 500]);
      }

      // Step 5: Mark booking as refunded
      $result = Bookings::cancel_booking_safely($booking_id, 'refunded');
      
      if (!$result['ok']) {
        // Refund was created but booking status didn't update - log this
        error_log(sprintf(
          'Koopo: WC refund #%d created for booking #%d, but booking status update failed: %s',
          $refund_result['refund_id'],
          $booking_id,
          $result['reason'] ?? 'unknown'
        ));
      }

      // Step 6: Trigger notification hook
      do_action('koopo_vendor_refund_processed', $booking_id, $order->get_id(), $refund_amount, $refund_result);

      update_option("koopo_booking_{$booking_id}_cancelled_by", 'vendor');
      update_option("koopo_booking_{$booking_id}_refund_amount", (string) $refund_amount);
      update_option("koopo_booking_{$booking_id}_refund_status", 'refunded');

      // Step 7: Return detailed success response
      self::invalidate_analytics_cache_for_booking($booking);
      return rest_ensure_response([
        'ok' => true,
        'action' => 'refunded',
        'refund_id' => $refund_result['refund_id'],
        'amount' => $refund_amount,
        'automatic' => $refund_result['automatic'],
        'message' => $refund_result['automatic']
          ? sprintf('Refund of $%.2f processed automatically via payment gateway.', $refund_amount)
          : sprintf('Refund of $%.2f created. Please process manually in your payment gateway: %s', 
                    $refund_amount, 
                    Refund_Processor::get_gateway_name($order)),
        'booking' => Bookings::get_booking($booking_id),
      ]);
    }

    return new \WP_Error('koopo_bad_action', 'Unknown action: ' . $action, ['status' => 400]);
  }

  /**
   * Manual booking creation by vendor.
   */
  public static function create_booking(\WP_REST_Request $request) {
    $listing_id = absint($request->get_param('listing_id'));
    $service_id = absint($request->get_param('service_id'));
    $start = sanitize_text_field((string) $request->get_param('start_datetime'));
    $end = sanitize_text_field((string) $request->get_param('end_datetime'));

    if (!$listing_id || !$service_id || !$start || !$end) {
      return new \WP_REST_Response(['error' => 'listing_id, service_id, start_datetime, end_datetime are required'], 400);
    }

    $listing = get_post($listing_id);
    if (!$listing || (int) $listing->post_author !== (int) get_current_user_id()) {
      return new \WP_REST_Response(['error' => 'Invalid listing ownership'], 403);
    }

    $customer_id = absint($request->get_param('customer_id'));
    $customer_email = sanitize_email((string) $request->get_param('customer_email'));
    $customer_name = sanitize_text_field((string) $request->get_param('customer_name'));
    $customer_phone = sanitize_text_field((string) $request->get_param('customer_phone'));
    $customer_notes = sanitize_textarea_field((string) $request->get_param('customer_notes'));
    $addon_ids = $request->get_param('addon_ids');
    $addon_ids = is_array($addon_ids) ? array_map('absint', $addon_ids) : [];

    if (!$customer_id && $customer_email) {
      $user = get_user_by('email', $customer_email);
      if ($user) {
        $customer_id = (int) $user->ID;
      }
    }

    if (!$customer_id && !$customer_name && !$customer_email) {
      return new \WP_REST_Response(['error' => 'Provide a customer (existing user email/ID) or guest name/email'], 400);
    }

    $timezone = sanitize_text_field((string) $request->get_param('timezone'));
    if ($timezone === '') {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string');
    }
    if ($timezone === '') {
      $timezone = 'UTC';
    }

    $status = sanitize_text_field((string) $request->get_param('status'));
    if (!in_array($status, ['confirmed', 'pending_payment'], true)) {
      $status = 'confirmed';
    }

    $payload = [
      'listing_id' => $listing_id,
      'service_id' => $service_id,
      'customer_id' => $customer_id,
      'start_datetime' => $start,
      'end_datetime' => $end,
      'timezone' => $timezone,
      'status' => $status,
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_notes' => $customer_notes,
      'addon_ids' => $addon_ids,
    ];

    try {
      $booking_id = Bookings::create_manual_booking($payload);
      $booking = Bookings::get_booking($booking_id);
      self::invalidate_analytics_cache_for_booking($booking);
      return new \WP_REST_Response(['booking_id' => $booking_id], 201);
    } catch (\Throwable $e) {
      return new \WP_REST_Response(['error' => $e->getMessage()], 400);
    }
  }

  /**
   * Export bookings to CSV
   */
  public static function export_bookings_csv(\WP_REST_Request $req) {
    global $wpdb;

    $vendor_id = get_current_user_id();
    $table = DB::table();

    $listing_id = absint($req->get_param('listing_id'));
    $status = sanitize_text_field((string) $req->get_param('status'));
    $search = sanitize_text_field((string) $req->get_param('search'));
    $month = sanitize_text_field((string) $req->get_param('month'));
    $year = sanitize_text_field((string) $req->get_param('year'));

    $where = 'WHERE listing_author_id = %d';
    $params = [$vendor_id];

    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }
    if ($status && $status !== 'all') {
      $where .= ' AND status = %s';
      $params[] = $status;
    }

    // Month filter
    if ($month && is_numeric($month)) {
      $where .= ' AND MONTH(start_datetime) = %d';
      $params[] = (int) $month;
    }

    // Year filter
    if ($year && is_numeric($year)) {
      $where .= ' AND YEAR(start_datetime) = %d';
      $params[] = (int) $year;
    }

    // Search filter (customer name, email, phone from booking meta)
    $search_booking_ids = [];
    if ($search) {
      $search_term = '%' . $wpdb->esc_like($search) . '%';

      // Search in options table for customer details
      $search_sql = $wpdb->prepare(
        "SELECT DISTINCT REPLACE(option_name, 'koopo_booking_', '') as booking_id
         FROM {$wpdb->options}
         WHERE (option_name LIKE 'koopo_booking_%%_customer_name'
                OR option_name LIKE 'koopo_booking_%%_customer_email'
                OR option_name LIKE 'koopo_booking_%%_customer_phone')
         AND option_value LIKE %s",
        $search_term
      );

      $search_results = $wpdb->get_col($search_sql);
      if ($search_results) {
        foreach ($search_results as $result) {
          // Extract booking ID from option_name
          $booking_id = (int) preg_replace('/[^0-9]/', '', $result);
          if ($booking_id > 0) {
            $search_booking_ids[] = $booking_id;
          }
        }
      }

      if (!empty($search_booking_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($search_booking_ids), '%d'));
        $where .= " AND id IN ($id_placeholders)";
        $params = array_merge($params, $search_booking_ids);
      } else {
        // If search term provided but no matches, return empty result
        $where .= ' AND 1=0';
      }
    }

    // Get all bookings (no pagination for export)
    $sql_items = $wpdb->prepare(
      "SELECT * FROM {$table} {$where} ORDER BY start_datetime DESC",
      $params
    );

    $rows = $wpdb->get_results($sql_items, ARRAY_A) ?: [];

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointments-export-' . date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, [
      'Booking ID',
      'Customer Name',
      'Customer Email',
      'Customer Phone',
      'Service',
      'Listing',
      'Date',
      'Time',
      'Duration',
      'Status',
      'Price',
      'Currency',
      'Order ID',
      'Booked On',
      'Timezone',
    ]);

    // CSV Rows
    foreach ($rows as $r) {
      $listing_title = $r['listing_id'] ? self::listing_title((int)$r['listing_id']) : '';
      $service_title = $r['service_id'] ? self::service_title((int)$r['service_id']) : '';

      // Get customer details from booking meta
      $customer_name = get_option("koopo_booking_{$r['id']}_customer_name", '');
      $customer_email = get_option("koopo_booking_{$r['id']}_customer_email", '');
      $customer_phone = get_option("koopo_booking_{$r['id']}_customer_phone", '');

      $tz = !empty($r['timezone']) ? (string)$r['timezone'] : '';

      $start_formatted = Date_Formatter::format($r['start_datetime'], $tz, 'full');
      $end_formatted = Date_Formatter::format($r['end_datetime'], $tz, 'time');

      $start_ts = strtotime($r['start_datetime']);
      $end_ts = strtotime($r['end_datetime']);
      $duration_mins = ($end_ts - $start_ts) / 60;
      $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

      $created_at_formatted = '';
      if (!empty($r['created_at'])) {
        $created_at_formatted = Date_Formatter::format($r['created_at'], $tz, 'full');
      }

      fputcsv($output, [
        $r['id'],
        $customer_name,
        $customer_email,
        $customer_phone,
        $service_title,
        $listing_title,
        date('Y-m-d', $start_ts),
        date('H:i', $start_ts) . ' - ' . date('H:i', $end_ts),
        $duration_formatted,
        ucfirst(str_replace('_', ' ', $r['status'])),
        isset($r['price']) ? number_format((float) $r['price'], 2, '.', '') : '0.00',
        $r['currency'] ?? '',
        isset($r['wc_order_id']) ? $r['wc_order_id'] : '',
        $created_at_formatted,
        $tz,
      ]);
    }

    fclose($output);
    exit;
  }

  public static function analytics(\WP_REST_Request $req) {
    global $wpdb;
    $table = DB::table();

    $vendor_id = get_current_user_id();
    $listing_id = absint($req->get_param('listing_id'));

    $cache_key = self::analytics_cache_key($vendor_id, $listing_id);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      return rest_ensure_response($cached);
    }

    $where = 'WHERE listing_author_id = %d AND status != %s';
    $params = [$vendor_id, 'expired'];
    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }

    $total_bookings = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} {$where}",
      $params
    ));

    $total_cancelled = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} {$where} AND status IN ('cancelled', 'refunded')",
      $params
    ));

    $total_earnings = (float) $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(price) FROM {$table} {$where} AND status = 'confirmed'",
      $params
    ));

    $services = $wpdb->get_results($wpdb->prepare(
      "SELECT service_id, COUNT(*) as total
       FROM {$table}
       {$where}
       GROUP BY service_id
       ORDER BY total DESC",
      $params
    ), ARRAY_A);

    $service_rows = [];
    foreach ($services as $row) {
      $service_id = (int) ($row['service_id'] ?? 0);
      if (!$service_id) {
        continue;
      }
      $service_rows[] = [
        'service_id' => $service_id,
        'service_title' => self::service_title($service_id) ?: '',
        'count' => (int) ($row['total'] ?? 0),
      ];
    }

    $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
    $currency_symbol = html_entity_decode((string) $currency_symbol, ENT_QUOTES);

    $payload = [
      'totals' => [
        'total_bookings' => $total_bookings,
        'total_cancelled' => $total_cancelled,
        'total_earnings' => $total_earnings,
        'currency_symbol' => $currency_symbol,
      ],
      'services' => $service_rows,
    ];

    $ttl = (int) apply_filters('koopo_appt_vendor_analytics_ttl', 60, $vendor_id, $listing_id);
    if ($ttl > 0) {
      set_transient($cache_key, $payload, $ttl);
    }

    return rest_ensure_response($payload);
  }

  private static function get_addons_summary(int $booking_id): array {
    $raw = get_option("koopo_booking_{$booking_id}_addon_ids", '');
    $ids = [];

    if (is_array($raw)) {
      $ids = array_map('absint', $raw);
    } elseif (is_string($raw) && $raw !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $ids = array_map('absint', $decoded);
      }
    }

    $ids = array_values(array_filter($ids));
    $titles = [];
    $total_price = 0.0;
    $total_duration = 0;

    foreach ($ids as $id) {
      $title = self::listing_title($id);
      if ($title) {
        $titles[] = $title;
      }

      $price = get_post_meta($id, Services_API::META_PRICE, true);
      if ($price === '' || $price === null) {
        $price = get_post_meta($id, '_koopo_price', true);
      }
      if (is_numeric($price)) {
        $total_price += (float) $price;
      }

      $duration = get_post_meta($id, Services_API::META_DURATION, true);
      if ($duration === '' || $duration === null) {
        $duration = get_post_meta($id, '_koopo_duration_minutes', true);
      }
      if (is_numeric($duration)) {
        $total_duration += (int) $duration;
      }
    }

    return [
      'ids' => $ids,
      'titles' => $titles,
      'total_price' => $total_price,
      'total_duration' => $total_duration,
    ];
  }
}
