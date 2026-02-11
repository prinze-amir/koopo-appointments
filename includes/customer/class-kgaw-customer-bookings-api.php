<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 23: Customer Bookings API
 * REST API endpoints for customers to view and manage their own bookings
 */
class Customer_Bookings_API {
  private static array $listing_title_cache = [];
  private static array $service_title_cache = [];
  private static array $service_meta_cache = [];
  private static array $booking_option_cache = [];
  private static array $listing_settings_cache = [];
  private static array $listing_author_cache = [];

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes(): void {
    
    // List customer's bookings
    register_rest_route('koopo/v1', '/customer/bookings', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'list_bookings'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
      'args' => [
        'status'   => ['type' => 'string', 'required' => false, 'default' => 'upcoming'],
        'page'     => ['type' => 'integer', 'required' => false, 'default' => 1],
        'per_page' => ['type' => 'integer', 'required' => false, 'default' => 10],
      ],
    ]);

    // Get single booking details
    register_rest_route('koopo/v1', '/customer/bookings/(?P<id>\d+)', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_booking'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
    ]);

    // Cancel booking
    register_rest_route('koopo/v1', '/customer/bookings/(?P<id>\d+)/cancel', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'cancel_booking'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
      'args' => [
        'reason' => ['type' => 'string', 'required' => false],
      ],
    ]);

    // Request reschedule
    register_rest_route('koopo/v1', '/customer/bookings/(?P<id>\d+)/reschedule-request', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'request_reschedule'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
      'args' => [
        'preferred_dates' => ['type' => 'array', 'required' => false],
        'note' => ['type' => 'string', 'required' => false],
      ],
    ]);

    // Reschedule booking (customer-initiated)
    register_rest_route('koopo/v1', '/customer/bookings/(?P<id>\d+)/reschedule', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'reschedule_booking'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
      'args' => [
        'start_datetime' => ['type' => 'string', 'required' => true],
        'end_datetime' => ['type' => 'string', 'required' => true],
        'timezone' => ['type' => 'string', 'required' => false],
      ],
    ]);

    // Get current user profile info for pre-filling forms
    register_rest_route('koopo/v1', '/customer/profile', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_profile'],
      'permission_callback' => [__CLASS__, 'is_customer_logged_in'],
    ]);
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
      return ['price' => 0.0, 'duration' => 0];
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
      self::$service_meta_cache[$service_id] = [
        'price' => is_numeric($price) ? (float) $price : 0.0,
        'duration' => (int) $duration,
      ];
    }
    return self::$service_meta_cache[$service_id];
  }

  private static function get_listing_settings_cached(int $listing_id): array {
    if (!$listing_id) return [];
    if (!array_key_exists($listing_id, self::$listing_settings_cache)) {
      self::$listing_settings_cache[$listing_id] = Settings_API::read_settings($listing_id);
    }
    return self::$listing_settings_cache[$listing_id];
  }

  private static function get_listing_author_id(int $listing_id): int {
    if (!$listing_id) return 0;
    if (!array_key_exists($listing_id, self::$listing_author_cache)) {
      self::$listing_author_cache[$listing_id] = (int) get_post_field('post_author', $listing_id);
    }
    return self::$listing_author_cache[$listing_id];
  }

  private static function invalidate_vendor_analytics_cache(int $listing_id): void {
    $vendor_id = self::get_listing_author_id($listing_id);
    if (!$vendor_id) return;
    delete_transient(sprintf('koopo_vendor_analytics_%d_%d', $vendor_id, 0));
    delete_transient(sprintf('koopo_vendor_analytics_%d_%d', $vendor_id, $listing_id));
  }

  private static function prime_booking_options(array $booking_ids): void {
    global $wpdb;
    $booking_ids = array_values(array_filter(array_map('absint', $booking_ids)));
    if (!$booking_ids) return;

    $fields = [
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

  /**
   * Permission check: user must be logged in
   */
  public static function is_customer_logged_in(): bool {
    return is_user_logged_in();
  }

  /**
   * List customer's bookings
   */
  public static function list_bookings(\WP_REST_Request $request) {
    global $wpdb;

    $customer_id = get_current_user_id();
    $table = DB::table();

    $status_filter = sanitize_text_field((string) $request->get_param('status'));
    if ($status_filter === '') {
      $status_filter = 'upcoming';
    }
    $page = max(1, absint($request->get_param('page')));
    $max_per_page = (int) apply_filters('koopo_appt_customer_per_page_max', 20, $customer_id);
    if ($max_per_page < 1) $max_per_page = 20;
    $per_page = min($max_per_page, max(1, absint($request->get_param('per_page'))));

    // Build WHERE clause based on status filter
    $where = $wpdb->prepare('WHERE customer_id = %d', $customer_id);

    $now = current_time('mysql');
    $hold_minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($hold_minutes < 1) {
      $hold_minutes = 10;
    }

    $order_dir = 'DESC';
    switch ($status_filter) {
      case 'upcoming':
        $where .= $wpdb->prepare(' AND start_datetime > %s AND status NOT IN (%s, %s, %s)', 
          $now, 'cancelled', 'refunded', 'expired');
        $order_dir = 'ASC';
        break;
      
      case 'past':
        $where .= $wpdb->prepare(' AND (start_datetime <= %s OR status IN (%s, %s))', 
          $now, 'cancelled', 'completed');
        break;
      
      case 'cancelled':
        $where .= " AND status IN ('cancelled', 'refunded')";
        break;
      
      case 'all':
        // No additional filter
        break;
      
      default:
        // Default to upcoming
        $where .= $wpdb->prepare(' AND start_datetime > %s AND status NOT IN (%s, %s, %s)', 
          $now, 'cancelled', 'refunded', 'expired');
        $order_dir = 'ASC';
    }

    $where .= $wpdb->prepare(
      " AND NOT (status = 'pending_payment' AND (wc_order_id IS NULL OR wc_order_id = 0) AND created_at < (NOW() - INTERVAL %d MINUTE))",
      $hold_minutes
    );

    // Get total count
    $sql_count = "SELECT COUNT(*) FROM {$table} {$where}";
    $total = (int) $wpdb->get_var($sql_count);

    // Get paginated results
    $offset = ($page - 1) * $per_page;
    $sql_items = $wpdb->prepare(
      "SELECT * FROM {$table} {$where} ORDER BY start_datetime {$order_dir} LIMIT %d OFFSET %d",
      $per_page, $offset
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
    foreach ($rows as $r) {
      $booking_ids[] = (int) $r['id'];
      if (!empty($r['service_id'])) $service_ids[] = (int) $r['service_id'];
      if (!empty($r['listing_id'])) $listing_ids[] = (int) $r['listing_id'];
    }
    $service_ids = array_values(array_unique($service_ids));
    $listing_ids = array_values(array_unique($listing_ids));

    if (function_exists('_prime_post_caches')) {
      if ($service_ids) _prime_post_caches($service_ids, false, false);
      if ($listing_ids) _prime_post_caches($listing_ids, false, false);
    }
    if ($service_ids) {
      update_postmeta_cache($service_ids);
    }
    self::prime_booking_options($booking_ids);

    foreach ($rows as $r) {
      $booking = (object) $r;
      
      // Format booking for customer
      $item = self::format_booking_for_customer($booking);
      $items[] = $item;
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
   * Get single booking details
   */
  public static function get_booking(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $customer_id = get_current_user_id();

    $booking = Bookings::get_booking($booking_id);
    
    if (!$booking) {
      return new \WP_Error('not_found', 'Booking not found', ['status' => 404]);
    }

    // Verify ownership
    if ((int) $booking->customer_id !== $customer_id) {
      return new \WP_Error('forbidden', 'You do not have permission to view this booking', ['status' => 403]);
    }

    return rest_ensure_response(self::format_booking_for_customer($booking));
  }

  /**
   * Cancel booking (customer-initiated)
   */
  public static function cancel_booking(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $reason = (string) $request->get_param('reason');
    $customer_id = get_current_user_id();

    $booking = Bookings::get_booking($booking_id);
    
    if (!$booking) {
      return new \WP_Error('not_found', 'Booking not found', ['status' => 404]);
    }

    // Verify ownership
    if ((int) $booking->customer_id !== $customer_id) {
      return new \WP_Error('forbidden', 'You do not have permission to cancel this booking', ['status' => 403]);
    }

    // Check if booking can be cancelled
    $status = (string) $booking->status;
    if (!in_array($status, ['pending_payment', 'confirmed'], true)) {
      return new \WP_Error('invalid_status', 
        'This booking cannot be cancelled (current status: ' . $status . ')', 
        ['status' => 409]);
    }

    // Check refund policy
    $policy_check = Refund_Policy::is_refundable($booking);
    
    if (!$policy_check['allowed']) {
      return new \WP_Error('cancellation_not_allowed', $policy_check['reason'], ['status' => 409]);
    }

    // Calculate refund amount
    $refund_calc = Refund_Policy::calculate_refund_amount((float) $booking->price, $booking);
    $refund_amount = $refund_calc['amount'];
    $refund_message = $refund_calc['reason'];

    // Cancel the booking
    $result = Bookings::cancel_booking_safely($booking_id, 'cancelled');
    
    if (!$result['ok']) {
      return new \WP_Error('cancel_failed', $result['reason'] ?? 'Cancellation failed', ['status' => 500]);
    }
    update_option("koopo_booking_{$booking_id}_cancelled_by", 'customer');

    // Process refund if there's an order
    $order_id = (int) ($booking->wc_order_id ?? 0);
    $refund_processed = false;
    
    if ($order_id && $refund_amount > 0) {
      $order = wc_get_order($order_id);
      
      if ($order) {
        // Add customer cancellation note
        $cancel_note = 'Koopo: Customer cancelled booking #' . $booking_id;
        if ($reason) {
          $cancel_note .= ' — Reason: ' . wp_strip_all_tags($reason);
        }
        $order->add_order_note($cancel_note);

        // Process refund
        $refund_result = Refund_Processor::process_refund(
          $order_id,
          $refund_amount,
          'Customer cancellation: ' . ($reason ?: 'No reason provided'),
          $booking_id
        );

        if ($refund_result['success']) {
          $refund_processed = true;
          if (isset($refund_result['amount']) && is_numeric($refund_result['amount'])) {
            $refund_amount = (float) $refund_result['amount'];
          }
          
          // Mark booking as refunded if full refund
          if ($refund_amount >= (float) $booking->price) {
            Bookings::cancel_booking_safely($booking_id, 'refunded');
          }
        }
      }
    }

    update_option("koopo_booking_{$booking_id}_refund_amount", (string) $refund_amount);
    $refund_status = $refund_amount > 0 ? ($refund_processed ? 'refunded' : 'pending') : 'none';
    update_option("koopo_booking_{$booking_id}_refund_status", $refund_status);
    if ($reason) {
      update_option("koopo_booking_{$booking_id}_cancel_reason", sanitize_textarea_field($reason));
    }

    if (!empty($booking->listing_id)) {
      self::invalidate_vendor_analytics_cache((int) $booking->listing_id);
    }

    // Trigger notification
    do_action('koopo_customer_cancelled_booking', $booking_id, $customer_id, $reason, $refund_amount);

    return rest_ensure_response([
      'ok' => true,
      'refund_amount' => $refund_amount,
      'refund_message' => $refund_message,
      'refund_processed' => $refund_processed,
      'message' => $refund_amount > 0 
        ? sprintf('Appointment cancelled. Refund of $%.2f will be processed.', $refund_amount)
        : 'Appointment cancelled successfully.',
    ]);
  }

  /**
   * Reschedule booking (customer-initiated)
   */
  public static function reschedule_booking(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $customer_id = get_current_user_id();

    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('not_found', 'Booking not found', ['status' => 404]);
    }

    if ((int) $booking->customer_id !== $customer_id) {
      return new \WP_Error('forbidden', 'You do not have permission to reschedule this booking', ['status' => 403]);
    }

    if ((string) $booking->status !== 'confirmed') {
      return new \WP_Error('invalid_status', 'Only confirmed bookings can be rescheduled', ['status' => 409]);
    }
    if (!self::is_reschedule_allowed($booking)) {
      return new \WP_Error('reschedule_window', 'Rescheduling is no longer available for this appointment', ['status' => 409]);
    }

    $new_start = sanitize_text_field((string) $request->get_param('start_datetime'));
    $new_end = sanitize_text_field((string) $request->get_param('end_datetime'));
    $timezone = sanitize_text_field((string) $request->get_param('timezone'));

    if (!$new_start || !$new_end) {
      return new \WP_Error('invalid_params', 'start_datetime and end_datetime are required', ['status' => 400]);
    }

    $ok = Bookings::reschedule_booking_safely($booking_id, $new_start, $new_end, $timezone);
    if (!$ok) {
      return new \WP_Error('reschedule_failed', 'Unable to reschedule: the selected time conflicts or is invalid', ['status' => 409]);
    }

    $order_id = (int) ($booking->wc_order_id ?? 0);
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order) {
        $order->add_order_note('Koopo: Customer rescheduled booking #'.$booking_id.' to '.$new_start.' - '.$new_end);
      }
    }

    $updated = Bookings::get_booking($booking_id);
    do_action('koopo_booking_rescheduled', $booking_id, $new_start, $new_end, $updated);

    if (!empty($updated->listing_id)) {
      self::invalidate_vendor_analytics_cache((int) $updated->listing_id);
    }

    return rest_ensure_response([
      'ok' => true,
      'booking' => self::format_booking_for_customer($updated),
    ]);
  }

  /**
   * Request reschedule (for vendor approval)
   */
  public static function request_reschedule(\WP_REST_Request $request) {
    $booking_id = (int) $request->get_param('id');
    $preferred_dates = $request->get_param('preferred_dates') ?: [];
    $note = (string) $request->get_param('note');
    $customer_id = get_current_user_id();

    $booking = Bookings::get_booking($booking_id);
    
    if (!$booking) {
      return new \WP_Error('not_found', 'Booking not found', ['status' => 404]);
    }

    // Verify ownership
    if ((int) $booking->customer_id !== $customer_id) {
      return new \WP_Error('forbidden', 'You do not have permission to reschedule this booking', ['status' => 403]);
    }

    // Check if booking can be rescheduled
    $status = (string) $booking->status;
    if (!in_array($status, ['pending_payment', 'confirmed'], true)) {
      return new \WP_Error('invalid_status', 
        'This booking cannot be rescheduled (current status: ' . $status . ')', 
        ['status' => 409]);
    }
    if (!self::is_reschedule_allowed($booking)) {
      return new \WP_Error('reschedule_window', 'Rescheduling is no longer available for this appointment', ['status' => 409]);
    }

    // Store reschedule request in booking meta or separate table
    // For now, we'll trigger a notification to the vendor
    
    $vendor_id = (int) $booking->listing_author_id;
    $customer = get_userdata($customer_id);
    $service_title = self::service_title((int) $booking->service_id);

    // Trigger notification
    do_action('koopo_customer_reschedule_request', [
      'booking_id' => $booking_id,
      'customer_id' => $customer_id,
      'customer_name' => $customer->display_name ?? '',
      'vendor_id' => $vendor_id,
      'service_title' => $service_title,
      'current_time' => Date_Formatter::format($booking->start_datetime, $booking->timezone ?? '', 'full'),
      'preferred_dates' => $preferred_dates,
      'note' => $note,
    ]);

    return rest_ensure_response([
      'ok' => true,
      'message' => 'Reschedule request sent to vendor. They will contact you with available times.',
    ]);
  }

  /**
   * Get current user profile information for pre-filling booking forms
   */
  public static function get_profile(\WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);

    if (!$user) {
      return new \WP_Error('user_not_found', 'User not found', ['status' => 404]);
    }

    // Get user billing info from WooCommerce if available
    $billing_name = '';
    $billing_email = '';
    $billing_phone = '';

    if (function_exists('get_user_meta')) {
      $first_name = get_user_meta($user_id, 'first_name', true);
      $last_name = get_user_meta($user_id, 'last_name', true);

      if ($first_name || $last_name) {
        $billing_name = trim($first_name . ' ' . $last_name);
      }

      // Try WooCommerce billing fields
      $billing_first = get_user_meta($user_id, 'billing_first_name', true);
      $billing_last = get_user_meta($user_id, 'billing_last_name', true);
      if ($billing_first || $billing_last) {
        $billing_name = trim($billing_first . ' ' . $billing_last);
      }

      $billing_email = get_user_meta($user_id, 'billing_email', true);
      $billing_phone = get_user_meta($user_id, 'billing_phone', true);
    }

    // Fallback to WordPress defaults
    if (empty($billing_name)) {
      $billing_name = $user->display_name;
    }
    if (empty($billing_email)) {
      $billing_email = $user->user_email;
    }

    return rest_ensure_response([
      'name' => $billing_name,
      'email' => $billing_email,
      'phone' => $billing_phone,
    ]);
  }

  /**
   * Format booking data for customer display
   */
  private static function format_booking_for_customer(object $booking): array {
    
    $service_title = self::service_title((int) $booking->service_id);
    $listing_title = self::listing_title((int) $booking->listing_id);
    $listing_url = $booking->listing_id ? get_permalink((int) $booking->listing_id) : '';
    
    $tz = !empty($booking->timezone) ? (string) $booking->timezone : '';
    
    $start_formatted = Date_Formatter::format($booking->start_datetime, $tz, 'full');
    $relative = Date_Formatter::relative($booking->start_datetime, $tz);
    
    $start_ts = strtotime($booking->start_datetime);
    $end_ts = strtotime($booking->end_datetime);
    $duration_mins = ($end_ts - $start_ts) / 60;
    $duration_formatted = Date_Formatter::format_duration((int) $duration_mins);

    $addon_summary = self::get_addons_summary((int) $booking->id);

    $service_meta = self::get_service_meta((int) $booking->service_id);
    $service_price = $service_meta['price'];
    $service_duration = $service_meta['duration'];

    $cancelled_by = self::get_booking_option((int) $booking->id, 'cancelled_by', '');
    $refund_amount_meta = self::get_booking_option((int) $booking->id, 'refund_amount', '');
    $refund_amount_meta = is_numeric($refund_amount_meta) ? (float) $refund_amount_meta : 0.0;
    $refund_status = self::get_booking_option((int) $booking->id, 'refund_status', '');

    // Determine what actions customer can take
    $status = (string) $booking->status;
    $is_future = strtotime($booking->start_datetime) > time();
    $can_cancel = $is_future && in_array($status, ['pending_payment', 'confirmed'], true);
    $can_reschedule = $is_future && $status === 'confirmed' && self::is_reschedule_allowed($booking);
    $cutoff_value = 0;
    $cutoff_unit = 'hours';
    if (!empty($booking->listing_id)) {
      $settings = self::get_listing_settings_cached((int) $booking->listing_id);
      $cutoff_value = isset($settings['reschedule_cutoff_value']) ? (int) $settings['reschedule_cutoff_value'] : 0;
      $cutoff_unit = isset($settings['reschedule_cutoff_unit']) ? (string) $settings['reschedule_cutoff_unit'] : 'hours';
    }
    $cutoff_minutes = self::get_reschedule_cutoff_minutes((int) $booking->listing_id);

    // Get calendar links
    $calendar_links = Date_Formatter::get_calendar_links($booking);

    $hold_minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($hold_minutes < 1) {
      $hold_minutes = 10;
    }

    $created_ts = !empty($booking->created_at) ? strtotime((string) $booking->created_at) : 0;
    $created_formatted = '';
    if (!empty($booking->created_at)) {
      $created_formatted = Date_Formatter::format($booking->created_at, $tz, 'full');
    }
    $hold_expires_at = $created_ts ? ($created_ts + ($hold_minutes * 60)) : 0;
    $pay_now_url = '';
    if ($status === 'pending_payment' && class_exists('\Koopo_Appointments\MyAccount')) {
      $pay_now_url = MyAccount::pay_now_url((int) $booking->id);
    }

    return [
      'id' => (int) $booking->id,
      'service_id' => (int) $booking->service_id,
      'service_title' => $service_title ?: '',
      'listing_id' => (int) $booking->listing_id,
      'listing_title' => $listing_title ?: '',
      'listing_url' => $listing_url ?: '',
      'start_datetime' => $booking->start_datetime,
      'end_datetime' => $booking->end_datetime,
      'start_datetime_formatted' => $start_formatted,
      'relative_time' => $relative,
      'duration_formatted' => $duration_formatted,
      'status' => $status,
      'price' => isset($booking->price) ? (float) $booking->price : 0.0,
      'currency' => $booking->currency ?? get_woocommerce_currency(),
      'timezone' => $tz,
      'can_cancel' => $can_cancel,
      'can_reschedule' => $can_reschedule,
      'wc_order_id' => isset($booking->wc_order_id) ? (int) $booking->wc_order_id : 0,
      'calendar_links' => $calendar_links,
      'pay_now_url' => $pay_now_url,
      'payment_hold_minutes' => $hold_minutes,
      'payment_hold_expires_at' => $hold_expires_at,
      'booked_on' => $created_formatted,
      'service_price' => $service_price,
      'service_duration' => $service_duration,
      'addon_ids' => $addon_summary['ids'],
      'addon_titles' => $addon_summary['titles'],
      'addon_total_price' => $addon_summary['total_price'],
      'addon_total_duration' => $addon_summary['total_duration'],
      'cancelled_by' => $cancelled_by ?: '',
      'refund_amount' => $refund_amount_meta,
      'refund_status' => $refund_status ?: '',
      'reschedule_cutoff_value' => $cutoff_value,
      'reschedule_cutoff_unit' => $cutoff_unit,
      'reschedule_cutoff_minutes' => $cutoff_minutes,
      'reschedule_allowed' => self::is_reschedule_allowed($booking),
    ];
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

  private static function get_reschedule_cutoff_minutes(int $listing_id): int {
    if (!$listing_id) return 0;
    $settings = self::get_listing_settings_cached($listing_id);
    if (isset($settings['reschedule_enabled']) && !$settings['reschedule_enabled']) {
      return 0;
    }
    if (isset($settings['reschedule_restrict_enabled']) && !$settings['reschedule_restrict_enabled']) {
      return 0;
    }
    $value = isset($settings['reschedule_cutoff_value']) ? (int) $settings['reschedule_cutoff_value'] : 0;
    $unit = isset($settings['reschedule_cutoff_unit']) ? (string) $settings['reschedule_cutoff_unit'] : 'hours';
    if ($value <= 0) return 0;
    switch ($unit) {
      case 'days':
        return $value * 24 * 60;
      case 'minutes':
        return $value;
      case 'hours':
      default:
        return $value * 60;
    }
  }

  private static function is_reschedule_allowed(object $booking): bool {
    $listing_id = isset($booking->listing_id) ? (int) $booking->listing_id : 0;
    if ($listing_id) {
      $settings = self::get_listing_settings_cached($listing_id);
      if (isset($settings['reschedule_enabled']) && !$settings['reschedule_enabled']) {
        return false;
      }
    }
    $cutoff_minutes = self::get_reschedule_cutoff_minutes($listing_id);
    if ($cutoff_minutes <= 0) return true;
    $start_ts = strtotime($booking->start_datetime);
    if (!$start_ts) return false;
    $minutes_until = ($start_ts - time()) / 60;
    return $minutes_until >= $cutoff_minutes;
  }
}
