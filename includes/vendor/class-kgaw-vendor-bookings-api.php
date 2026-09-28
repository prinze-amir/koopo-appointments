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
  private static array $service_meta_cache = [];

  private static function booking_has_ended($booking): bool {
    $end = is_array($booking) ? (string) ($booking['end_datetime'] ?? '') : (string) ($booking->end_datetime ?? '');
    $timezone = is_array($booking) ? (string) ($booking['timezone'] ?? '') : (string) ($booking->timezone ?? '');
    if ($end === '') return false;
    try {
      $zone = new \DateTimeZone($timezone ?: 'UTC');
      return new \DateTimeImmutable($end, $zone) <= new \DateTimeImmutable('now', $zone);
    } catch (\Throwable $e) {
      return strtotime($end . ' UTC') <= time();
    }
  }

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route('koopo/v1', '/vendor/bookings', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'list_bookings'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'resource_id' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'status'     => ['type' => 'string',  'required' => false, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_key'],
        'search'     => ['type' => 'string',  'required' => false, 'maxLength' => 100, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_text_field'],
        'month'      => ['type' => 'string',  'required' => false],
        'year'       => ['type' => 'string',  'required' => false],
        'range_start' => ['type' => 'string', 'required' => false],
        'range_end'   => ['type' => 'string', 'required' => false],
        'page'       => ['type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1, 'maximum' => 10000, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'per_page'   => ['type' => 'integer', 'required' => false, 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
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
        'resource_id' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
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
        'refund_type' => ['type' => 'string', 'required' => false], // standard|fraud
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/create', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'create_booking'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'listing_id' => ['type' => 'integer', 'required' => false],
        'provider_id' => ['type' => 'integer', 'required' => false],
        'resource_id' => ['type' => 'integer', 'required' => false],
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
        'invite_channels' => ['type' => 'array', 'required' => false],
        'invite_hold_minutes' => ['type' => 'integer', 'required' => false],
        'sms_consent' => ['type' => 'boolean', 'required' => false],
        'sms_consent_method' => ['type' => 'string', 'required' => false],
        'sms_consent_version' => ['type' => 'string', 'required' => false],
      ],
    ]);

    // NEW: Get refund info for a booking
    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/refund-info', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_refund_info'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);
  }

  private static function analytics_cache_key(int $vendor_id, int $scope_id): string {
    return sprintf('koopo_vendor_analytics_%d_%d', $vendor_id, $scope_id);
  }

  private static function invalidate_analytics_cache_for_booking($booking): void {
    if (!$booking || empty($booking->listing_author_id)) return;
    $vendor_id = (int) $booking->listing_author_id;
    $listing_id = isset($booking->listing_id) ? (int) $booking->listing_id : 0;
    $resource_id = isset($booking->resource_id) ? (int) $booking->resource_id : 0;
    delete_transient(self::analytics_cache_key($vendor_id, 0));
    if ($listing_id) {
      delete_transient(self::analytics_cache_key($vendor_id, $listing_id));
    }
    if ($resource_id) {
      delete_transient(self::analytics_cache_key($vendor_id, -$resource_id));
    }
  }

  private static function listing_title(int $listing_id): string {
    if (!$listing_id) return '';
    if (!array_key_exists($listing_id, self::$listing_title_cache)) {
      self::$listing_title_cache[$listing_id] = (string) get_the_title($listing_id);
    }
    return self::$listing_title_cache[$listing_id];
  }

  private static function customer_message_url(int $customer_id): string {
    if ($customer_id <= 0 || $customer_id === get_current_user_id()) return '';
    if (!function_exists('bp_loggedin_user_domain') || !function_exists('bp_get_messages_slug') || !function_exists('bp_members_get_user_nicename')) return '';
    if (function_exists('bp_is_active') && !bp_is_active('messages')) return '';

    $recipient = (string) bp_members_get_user_nicename($customer_id);
    $sender_domain = (string) bp_loggedin_user_domain();
    $messages_slug = (string) bp_get_messages_slug();
    if ($recipient === '' || $sender_domain === '' || $messages_slug === '') return '';

    return esc_url_raw(add_query_arg(
      'r',
      $recipient,
      trailingslashit($sender_domain) . trailingslashit($messages_slug) . 'compose/'
    ));
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

  private static function normalize_refund_type($raw): string {
    $type = strtolower(sanitize_key((string) $raw));
    return $type === 'fraud' ? 'fraud' : 'standard';
  }

  private static function append_refund_type_token(string $reason, string $refund_type): string {
    $clean = trim((string) preg_replace('/\[#koopo_refund_type:(?:standard|fraud)\]\s*/i', '', $reason));
    if ($clean === '') {
      return sprintf('[#koopo_refund_type:%s]', $refund_type);
    }
    return sprintf('[#koopo_refund_type:%s] %s', $refund_type, $clean);
  }

  private static function csv_safe_value($value): string {
    $value = is_scalar($value) ? (string) $value : '';
    if (preg_match('/^[\s]*[=+\-@]/', $value)) {
      return "'" . $value;
    }
    return $value;
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
    $resource_id = absint($req->get_param('resource_id'));
    $status = sanitize_text_field((string) $req->get_param('status'));
    $search = sanitize_text_field((string) $req->get_param('search'));
    $month = sanitize_text_field((string) $req->get_param('month'));
    $year = sanitize_text_field((string) $req->get_param('year'));
    $page = max(1, absint($req->get_param('page')));
    $max_per_page = (int) apply_filters('koopo_appt_vendor_per_page_max', 100, $vendor_id, $listing_id);
    if ($max_per_page < 1) $max_per_page = 100;
    $max_per_page = min(500, $max_per_page);
    $per_page = min($max_per_page, max(1, absint($req->get_param('per_page'))));
    $range_start = sanitize_text_field((string) $req->get_param('range_start'));
    $range_end = sanitize_text_field((string) $req->get_param('range_end'));

    $where = 'WHERE COALESCE(payee_user_id, listing_author_id) = %d';
    $params = [$vendor_id];

    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }
    if ($resource_id) {
      $where .= ' AND resource_id = %d';
      $params[] = $resource_id;
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
      if ($month && is_numeric($month) && $year && is_numeric($year)) {
        $month_start = sprintf('%04d-%02d-01 00:00:00', (int) $year, (int) $month);
        $month_end = date('Y-m-d H:i:s', strtotime($month_start . ' +1 month'));
        $where .= ' AND start_datetime >= %s AND start_datetime < %s';
        $params[] = $month_start;
        $params[] = $month_end;
      } elseif ($year && is_numeric($year)) {
        $year_start = sprintf('%04d-01-01 00:00:00', (int) $year);
        $year_end = sprintf('%04d-01-01 00:00:00', ((int) $year) + 1);
        $where .= ' AND start_datetime >= %s AND start_datetime < %s';
        $params[] = $year_start;
        $params[] = $year_end;
      }
    }

    // Range filter (overrides month/year when both provided)
    if ($range_start && $range_end) {
      $where .= ' AND start_datetime BETWEEN %s AND %s';
      $params[] = $range_start;
      $params[] = $range_end;
    }

    if ($search) {
      $search_term = '%' . $wpdb->esc_like($search) . '%';
      if (ctype_digit($search)) {
        $where .= ' AND (id = %d OR customer_id = %d OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
        $params[] = (int) $search;
        $params[] = (int) $search;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
      } else {
        $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
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

    $service_ids = [];
    $listing_ids = [];
    $provider_ids = [];
    $customer_ids = [];
    $booking_ids = [];
    foreach ($rows as $r) {
      $booking_ids[] = (int) $r['id'];
      if (!empty($r['service_id'])) $service_ids[] = (int) $r['service_id'];
      if (!empty($r['listing_id'])) $listing_ids[] = (int) $r['listing_id'];
      if (!empty($r['provider_id'])) $provider_ids[] = (int) $r['provider_id'];
      if (!empty($r['customer_id'])) $customer_ids[] = (int) $r['customer_id'];
    }
    $service_ids = array_values(array_unique($service_ids));
    $listing_ids = array_values(array_unique($listing_ids));
    $provider_ids = array_values(array_unique($provider_ids));
    $subject_ids = array_values(array_unique(array_merge($listing_ids, $provider_ids)));
    $customer_ids = array_values(array_unique($customer_ids));
    $invites_by_booking = [];
    if ($booking_ids) {
      $placeholders = implode(',', array_fill(0, count($booking_ids), '%d'));
      $invite_rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . DB::booking_invites_table() . " WHERE booking_id IN ({$placeholders})",
        $booking_ids
      ));
      foreach ($invite_rows ?: [] as $invite_row) $invites_by_booking[(int) $invite_row->booking_id] = $invite_row;
    }

    if (function_exists('_prime_post_caches')) {
      if ($subject_ids) _prime_post_caches($subject_ids, false, false);
      if ($service_ids) _prime_post_caches($service_ids, false, false);
    }
    if ($service_ids) {
      update_postmeta_cache($service_ids);
    }
    if ($customer_ids && function_exists('cache_users')) {
      cache_users($customer_ids);
    }
    foreach ($rows as $r) {
      $provider_id = (int) ($r['provider_id'] ?? 0);
      $subject_id = !empty($r['listing_id']) ? (int) $r['listing_id'] : $provider_id;
      $listing_title = $subject_id ? self::listing_title($subject_id) : '';
      $service_id = (int) $r['service_id'];
      $service_title = $service_id ? self::service_title($service_id) : '';
      $service_meta = $service_id ? self::get_service_meta($service_id) : ['price' => 0.0, 'duration' => 0, 'color' => ''];
      $service_color = $service_meta['color'];

      $customer_name = '';
      $customer_email = '';
      $customer_phone = '';
      $customer_avatar = '';
      $customer_profile = '';
      $customer_message_url = '';
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
        $customer_message_url = self::customer_message_url((int) $r['customer_id']);
      }
      $booking_id = (int) $r['id'];
      if (!$customer_name) {
        $customer_name = (string) Bookings::extra_from_record($r, 'customer_name', '');
      }
      if (!$customer_email) {
        $customer_email = (string) Bookings::extra_from_record($r, 'customer_email', '');
      }
      $customer_phone = (string) Bookings::extra_from_record($r, 'customer_phone', '');
      $booking_for_other = (int) Bookings::extra_from_record($r, 'booking_for_other', 0) === 1;
      $cancelled_by = (string) Bookings::extra_from_record($r, 'cancelled_by', '');
      $refund_amount_meta = (float) Bookings::extra_from_record($r, 'refund_amount', 0.0);
      $refund_status = (string) Bookings::extra_from_record($r, 'refund_status', '');
      $fulfillment_mode = (string) Bookings::extra_from_record($r, 'fulfillment_mode', 'at_location');
      $service_address = array_values(array_filter([
        (string) Bookings::extra_from_record($r, 'service_address_1', ''),
        (string) Bookings::extra_from_record($r, 'service_address_2', ''),
        (string) Bookings::extra_from_record($r, 'service_city', ''),
        (string) Bookings::extra_from_record($r, 'service_region', ''),
        (string) Bookings::extra_from_record($r, 'service_postal_code', ''),
        (string) Bookings::extra_from_record($r, 'service_country', ''),
      ]));
      $addon_summary = self::get_addons_summary($r);
      $payment_status = 'not_linked';
      if (!empty($r['wc_order_id'])) {
        $payment_order = wc_get_order((int) $r['wc_order_id']);
        $payment_status = $payment_order && Order_Hooks::payment_is_verified($payment_order) ? 'verified' : 'not_received';
      }

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
        'provider_id' => $provider_id,
        'resource_id' => (int) ($r['resource_id'] ?? 0),
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
        'customer_message_url' => $customer_message_url,
        'customer_is_guest' => empty($r['customer_id']),
        'booking_for_other' => $booking_for_other,
        'start_datetime' => $r['start_datetime'],
        'end_datetime' => $r['end_datetime'],
        'start_datetime_formatted' => $start_formatted,
        'end_datetime_formatted' => $end_formatted,
        'duration_formatted' => $duration_formatted,
        'status' => $r['status'],
        'has_ended' => self::booking_has_ended($r),
        'price' => isset($r['price']) ? (float) $r['price'] : 0.0,
        'currency' => $r['currency'] ?? '',
        'wc_order_id' => isset($r['wc_order_id']) ? (int) $r['wc_order_id'] : 0,
        'payment_status' => $payment_status,
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
        'fulfillment_mode' => $fulfillment_mode,
        'service_address' => $fulfillment_mode === 'mobile' ? implode(', ', $service_address) : '',
        'virtual_provider' => $fulfillment_mode === 'virtual' ? (string) Bookings::extra_from_record($r, 'virtual_provider', '') : '',
        'virtual_join_url' => $fulfillment_mode === 'virtual' ? esc_url_raw((string) Bookings::extra_from_record($r, 'virtual_join_url', '')) : '',
        'virtual_instructions' => $fulfillment_mode === 'virtual' ? (string) Bookings::extra_from_record($r, 'virtual_instructions', '') : '',
        'sms_consent_evidence' => isset($invites_by_booking[$booking_id]) ? Booking_Invitations::consent_evidence($invites_by_booking[$booking_id]) : null,
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
      'koopo_refund_policy' => [
        'active' => class_exists('\Koopo\RefundPolicy\Plugin'),
        'supports_fraud_type' => true,
        'default_type' => 'standard',
      ],
    ]);
  }

  public static function booking_action(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $action = sanitize_key((string) $request->get_param('action'));
    $note = (string) $request->get_param('note');
    $refund_type = self::normalize_refund_type($request->get_param('refund_type'));

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
    $has_ended = self::booking_has_ended($booking);

    if ($has_ended && in_array($action, ['cancel', 'reschedule', 'confirm'], true)) {
      return new \WP_Error(
        'koopo_appointment_ended',
        'This appointment has already ended. It can no longer be cancelled, rescheduled, or manually confirmed.',
        ['status' => 409]
      );
    }

    // === CANCEL ACTION ===
    if ($action === 'cancel') {
      $result = Bookings::cancel_booking_safely($booking_id, 'cancelled');
      
      if (!$result['ok']) {
        return new \WP_Error('koopo_cancel_failed', $result['reason'] ?? 'Cancel failed', ['status' => 409]);
      }
      Bookings::update_booking_extras($booking_id, [
        'cancelled_by' => 'vendor',
        'refund_amount' => 0,
        'refund_status' => 'none',
      ]);

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
      $refund_note = self::append_refund_type_token($note, $refund_type);
      $refund_result = Refund_Processor::process_refund(
        $order->get_id(),
        $refund_amount,
        $refund_note,
        $booking_id
      );

      if (!$refund_result['success']) {
        return new \WP_Error('koopo_refund_failed', $refund_result['message'], ['status' => 500]);
      }
      if (isset($refund_result['amount']) && is_numeric($refund_result['amount'])) {
        $refund_amount = (float) $refund_result['amount'];
      }

      // Step 5: Mark booking as refunded
      $result = Bookings::cancel_booking_safely($booking_id, 'refunded');
      
      if (!$result['ok']) {
        Logger::error('refund_booking_status_update_failed', [
          'refund_id' => (int) $refund_result['refund_id'],
          'booking_id' => $booking_id,
          'reason' => sanitize_key((string) ($result['reason'] ?? 'unknown')),
        ]);
      }

      // Step 6: Trigger notification hook
      do_action('koopo_vendor_refund_processed', $booking_id, $order->get_id(), $refund_amount, $refund_result);

      Bookings::update_booking_extras($booking_id, [
        'cancelled_by' => 'vendor',
        'refund_amount' => $refund_amount,
        'refund_status' => 'refunded',
      ]);

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
    $provider_id = absint($request->get_param('provider_id'));
    $resource_id = absint($request->get_param('resource_id'));
    $service_id = absint($request->get_param('service_id'));
    $start = sanitize_text_field((string) $request->get_param('start_datetime'));
    $end = sanitize_text_field((string) $request->get_param('end_datetime'));

    if ((!$listing_id && !$provider_id && !$resource_id) || !$service_id || !$start || !$end) {
      return new \WP_REST_Response(['error' => 'A booking profile, service, start, and end are required'], 400);
    }

    if (!$resource_id) $resource_id = $provider_id ? Resources::ensure_for_provider($provider_id) : Resources::ensure_for_listing($listing_id);
    if (!Resources::can_manage($resource_id)) return new \WP_REST_Response(['error' => 'Invalid booking profile ownership'], 403);
    $resource = Resources::get($resource_id);
    if ($resource && $resource->subject_type === 'provider') $provider_id = (int) $resource->subject_id;
    if ($resource && $resource->subject_type === 'listing') $listing_id = (int) $resource->subject_id;

    $customer_id = absint($request->get_param('customer_id'));
    $customer_email = sanitize_email((string) $request->get_param('customer_email'));
    $customer_name = sanitize_text_field((string) $request->get_param('customer_name'));
    $customer_phone = sanitize_text_field((string) $request->get_param('customer_phone'));
    $customer_notes = sanitize_textarea_field((string) $request->get_param('customer_notes'));
    $addon_ids = $request->get_param('addon_ids');
    $addon_ids = is_array($addon_ids) ? array_map('absint', $addon_ids) : [];

    if (!$customer_id) $customer_id = Booking_Invitations::resolve_user($customer_email, $customer_phone);
    if ($customer_id) {
      $customer = get_userdata($customer_id);
      if (!$customer) return new \WP_REST_Response(['error' => 'The selected Koopo member is unavailable.'], 404);
      $customer_name = (string) $customer->display_name;
      $customer_email = (string) $customer->user_email;
      $customer_phone = (string) (get_user_meta($customer_id, 'billing_phone', true) ?: $customer_phone);
    }

    $is_guest = !$customer_id;
    if ($is_guest && (!$customer_name || (!$customer_email && !$customer_phone))) {
      return new \WP_REST_Response(['error' => 'Provide the guest name and an email address or phone number.'], 400);
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
    if ($is_guest) $status = Booking_Invitations::STATUS;

    $payload = [
      'listing_id' => $listing_id,
      'provider_id' => $provider_id,
      'resource_id' => $resource_id,
      'service_id' => $service_id,
      'customer_id' => $customer_id,
      'start_datetime' => $start,
      'end_datetime' => $end,
      'timezone' => $timezone,
      'status' => $status,
      'fulfillment_mode' => sanitize_key((string) $request->get_param('fulfillment_mode')),
      'service_address' => is_array($request->get_param('service_address')) ? (array) $request->get_param('service_address') : [],
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_notes' => $customer_notes,
      'booking_for_other' => $is_guest,
      'addon_ids' => $addon_ids,
    ];

    try {
      $booking_id = Bookings::create_manual_booking($payload);
      $booking = Bookings::get_booking($booking_id);
      $invitation = null;
      if ($is_guest) {
        $channels = $request->get_param('invite_channels');
        $channels = is_array($channels) ? $channels : ($customer_email ? ['email'] : []);
        $invitation = Booking_Invitations::create(
          $booking_id,
          get_current_user_id(),
          $channels,
          absint($request->get_param('invite_hold_minutes')) ?: Booking_Invitations::DEFAULT_HOLD_MINUTES,
          (bool) $request->get_param('sms_consent'),
          [
            'method' => sanitize_key((string) $request->get_param('sms_consent_method')),
            'version' => sanitize_key((string) $request->get_param('sms_consent_version')),
          ]
        );
        if (is_wp_error($invitation)) {
          Bookings::delete_booking_data_by_id($booking_id);
          return new \WP_REST_Response(['error'=>$invitation->get_error_message(),'code'=>$invitation->get_error_code()], 422);
        }
      }
      if ($booking && (string) $booking->status === 'confirmed') {
        do_action('koopo_booking_confirmed_safe', $booking_id, $booking);
      }
      self::invalidate_analytics_cache_for_booking($booking);
      return new \WP_REST_Response(['booking_id' => $booking_id, 'customer_is_guest'=>$is_guest, 'invitation'=>$invitation], 201);
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
    $resource_id = absint($req->get_param('resource_id'));
    $status = sanitize_text_field((string) $req->get_param('status'));
    $search = sanitize_text_field((string) $req->get_param('search'));
    $month = sanitize_text_field((string) $req->get_param('month'));
    $year = sanitize_text_field((string) $req->get_param('year'));

    $where = 'WHERE COALESCE(payee_user_id, listing_author_id) = %d';
    $params = [$vendor_id];

    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }
    if ($resource_id) {
      $where .= ' AND resource_id = %d';
      $params[] = $resource_id;
    }
    if ($status && $status !== 'all') {
      $where .= ' AND status = %s';
      $params[] = $status;
    }

    if ($month && is_numeric($month) && $year && is_numeric($year)) {
      $month_start = sprintf('%04d-%02d-01 00:00:00', (int) $year, (int) $month);
      $month_end = date('Y-m-d H:i:s', strtotime($month_start . ' +1 month'));
      $where .= ' AND start_datetime >= %s AND start_datetime < %s';
      $params[] = $month_start;
      $params[] = $month_end;
    } elseif ($year && is_numeric($year)) {
      $year_start = sprintf('%04d-01-01 00:00:00', (int) $year);
      $year_end = sprintf('%04d-01-01 00:00:00', ((int) $year) + 1);
      $where .= ' AND start_datetime >= %s AND start_datetime < %s';
      $params[] = $year_start;
      $params[] = $year_end;
    }

    if ($search) {
      $search_term = '%' . $wpdb->esc_like($search) . '%';
      if (ctype_digit($search)) {
        $where .= ' AND (id = %d OR customer_id = %d OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
        $params[] = (int) $search;
        $params[] = (int) $search;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
      } else {
        $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
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
      'Professional or Business',
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
      $subject_id = (int) ($r['listing_id'] ?? 0) ?: (int) ($r['provider_id'] ?? 0);
      $listing_title = $subject_id ? self::listing_title($subject_id) : '';
      $service_title = $r['service_id'] ? self::service_title((int)$r['service_id']) : '';

      $customer_name = (string) Bookings::extra_from_record($r, 'customer_name', '');
      $customer_email = (string) Bookings::extra_from_record($r, 'customer_email', '');
      $customer_phone = (string) Bookings::extra_from_record($r, 'customer_phone', '');

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
        self::csv_safe_value($customer_name),
        self::csv_safe_value($customer_email),
        self::csv_safe_value($customer_phone),
        self::csv_safe_value($service_title),
        self::csv_safe_value($listing_title),
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
    $resource_id = absint($req->get_param('resource_id'));

    $cache_key = self::analytics_cache_key($vendor_id, $resource_id ? -$resource_id : $listing_id);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      return rest_ensure_response($cached);
    }

    $where = 'WHERE COALESCE(payee_user_id, listing_author_id) = %d AND status != %s';
    $params = [$vendor_id, 'expired'];
    if ($listing_id) {
      $where .= ' AND listing_id = %d';
      $params[] = $listing_id;
    }
    if ($resource_id) {
      $where .= ' AND resource_id = %d';
      $params[] = $resource_id;
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

  private static function get_addons_summary($booking): array {
    $raw = Bookings::extra_from_record($booking, 'addon_ids', '');
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
