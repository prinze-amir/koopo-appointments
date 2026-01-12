<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 23: Customer Bookings API
 * REST API endpoints for customers to view and manage their own bookings
 */
class Customer_Bookings_API {

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
    $page = max(1, absint($request->get_param('page')));
    $per_page = min(50, max(1, absint($request->get_param('per_page'))));

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
          
          // Mark booking as refunded if full refund
          if ($refund_amount >= (float) $booking->price) {
            Bookings::cancel_booking_safely($booking_id, 'refunded');
          }
        }
      }
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
    $service_title = get_the_title((int) $booking->service_id);

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
    
    $service_title = get_the_title((int) $booking->service_id);
    $listing_title = get_the_title((int) $booking->listing_id);
    
    $tz = !empty($booking->timezone) ? (string) $booking->timezone : '';
    
    $start_formatted = Date_Formatter::format($booking->start_datetime, $tz, 'full');
    $relative = Date_Formatter::relative($booking->start_datetime, $tz);
    
    $start_ts = strtotime($booking->start_datetime);
    $end_ts = strtotime($booking->end_datetime);
    $duration_mins = ($end_ts - $start_ts) / 60;
    $duration_formatted = Date_Formatter::format_duration((int) $duration_mins);

    $addon_summary = self::get_addons_summary((int) $booking->id);

    $service_price = get_post_meta((int) $booking->service_id, Services_API::META_PRICE, true);
    if ($service_price === '' || $service_price === null) {
      $service_price = get_post_meta((int) $booking->service_id, '_koopo_price', true);
    }
    $service_price = is_numeric($service_price) ? (float) $service_price : 0.0;

    $service_duration = get_post_meta((int) $booking->service_id, Services_API::META_DURATION, true);
    if ($service_duration === '' || $service_duration === null) {
      $service_duration = get_post_meta((int) $booking->service_id, '_koopo_duration_minutes', true);
    }
    $service_duration = (int) $service_duration;

    // Determine what actions customer can take
    $status = (string) $booking->status;
    $is_future = strtotime($booking->start_datetime) > time();
    $can_cancel = $is_future && in_array($status, ['pending_payment', 'confirmed'], true);
    $can_reschedule = $is_future && $status === 'confirmed' && self::is_reschedule_allowed($booking);
    $cutoff_value = 0;
    $cutoff_unit = 'hours';
    if (!empty($booking->listing_id)) {
      $settings = Settings_API::read_settings((int) $booking->listing_id);
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
      'service_price' => $service_price,
      'service_duration' => $service_duration,
      'addon_ids' => $addon_summary['ids'],
      'addon_titles' => $addon_summary['titles'],
      'addon_total_price' => $addon_summary['total_price'],
      'addon_total_duration' => $addon_summary['total_duration'],
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
      $title = get_the_title($id);
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
    $settings = Settings_API::read_settings($listing_id);
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
    $cutoff_minutes = self::get_reschedule_cutoff_minutes($listing_id);
    if ($cutoff_minutes <= 0) return true;
    $start_ts = strtotime($booking->start_datetime);
    if (!$start_ts) return false;
    $minutes_until = ($start_ts - time()) / 60;
    return $minutes_until >= $cutoff_minutes;
  }
}
