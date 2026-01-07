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

    switch ($status_filter) {
      case 'upcoming':
        $where .= $wpdb->prepare(' AND start_datetime > %s AND status NOT IN (%s, %s, %s)', 
          $now, 'cancelled', 'refunded', 'expired');
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
    }

    // Get total count
    $sql_count = "SELECT COUNT(*) FROM {$table} {$where}";
    $total = (int) $wpdb->get_var($sql_count);

    // Get paginated results
    $offset = ($page - 1) * $per_page;
    $sql_items = $wpdb->prepare(
      "SELECT * FROM {$table} {$where} ORDER BY start_datetime DESC LIMIT %d OFFSET %d",
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

    // Determine what actions customer can take
    $status = (string) $booking->status;
    $is_future = strtotime($booking->start_datetime) > time();
    $can_cancel = $is_future && in_array($status, ['pending_payment', 'confirmed'], true);
    $can_reschedule = $is_future && in_array($status, ['pending_payment', 'confirmed'], true);

    // Get calendar links
    $calendar_links = Date_Formatter::get_calendar_links($booking);

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
    ];
  }
}
