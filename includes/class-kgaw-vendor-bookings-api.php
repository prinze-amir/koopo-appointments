<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
  * Vendor bookings endpoint for Dokan dashboard.
 * GET /koopo/v1/vendor/bookings
 */
class Vendor_Bookings_API {

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
        'page'       => ['type' => 'integer', 'required' => false, 'default' => 1],
        'per_page'   => ['type' => 'integer', 'required' => false, 'default' => 20],
      ],
    ]);

    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/action', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'booking_action'],
      'permission_callback' => [__CLASS__, 'can_access'],
      'args' => [
        'action' => ['type' => 'string', 'required' => true],
        'note'   => ['type' => 'string', 'required' => false],
      ],
    ]);
  }

  public static function can_access(): bool {
    if (!is_user_logged_in()) return false;
    // If Dokan is installed, require seller
    if (function_exists('dokan_is_user_seller')) {
      return dokan_is_user_seller(get_current_user_id());
    }
    // Fallback: allow admins
    return current_user_can('manage_options');
  }


  public static function list_bookings(\WP_REST_Request $req) {
    global $wpdb;

    $vendor_id = get_current_user_id();
    $table = DB::table();

    $listing_id = absint($req->get_param('listing_id'));
    $status = sanitize_text_field((string) $req->get_param('status'));
    $page = max(1, absint($req->get_param('page')));
    $per_page = min(100, max(1, absint($req->get_param('per_page'))));

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

    // Total count
    $sql_count = $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $params);
    $total = (int) $wpdb->get_var($sql_count);

    $offset = ($page - 1) * $per_page;

    $sql_items = $wpdb->prepare(
      "SELECT * FROM {$table} {$where} ORDER BY start_datetime DESC LIMIT %d OFFSET %d",
      array_merge($params, [$per_page, $offset])
    );

    $rows = $wpdb->get_results($sql_items, ARRAY_A) ?: [];
    $items = [];

    foreach ($rows as $r) {
      $listing_title = $r['listing_id'] ? get_the_title((int)$r['listing_id']) : '';
      $service_title = $r['service_id'] ? get_the_title((int)$r['service_id']) : '';
      $customer_name = $r['customer_id'] ? (get_userdata((int)$r['customer_id'])->display_name ?? '') : '';

      // Get timezone for formatting
      $tz = !empty($r['timezone']) ? (string)$r['timezone'] : '';

      // Format dates for display
      $start_formatted = Date_Formatter::format($r['start_datetime'], $tz, 'short');
      $end_formatted = Date_Formatter::format($r['end_datetime'], $tz, 'time');

      // Calculate duration
      $start_ts = strtotime($r['start_datetime']);
      $end_ts = strtotime($r['end_datetime']);
      $duration_mins = ($end_ts - $start_ts) / 60;
      $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

      $items[] = [
        'id' => (int) $r['id'],
        'listing_id' => (int) $r['listing_id'],
        'listing_title' => $listing_title ?: '',
        'service_id' => (int) $r['service_id'],
        'service_title' => $service_title ?: '',
        'customer_id' => (int) $r['customer_id'],
        'customer_name' => $customer_name ?: '',
        
        // Raw values (for reschedule form pre-fill)
        'start_datetime' => $r['start_datetime'],
        'end_datetime' => $r['end_datetime'],
        
        // Formatted values (for display)
        'start_datetime_formatted' => $start_formatted,
        'end_datetime_formatted' => $end_formatted,
        'duration_formatted' => $duration_formatted,
        
        'status' => $r['status'],
        'price' => isset($r['price']) ? (float) $r['price'] : 0.0,
        'currency' => $r['currency'] ?? '',
        'wc_order_id' => isset($r['wc_order_id']) ? (int) $r['wc_order_id'] : 0,
        'created_at' => $r['created_at'] ?? '',
        'timezone' => $tz,
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
   * POST /koopo/v1/vendor/bookings/{id}/action
   * Actions: cancel, confirm, note, reschedule, refund
   */
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

      // Validate date format
      if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $new_start) ||
          !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $new_end)) {
        return new \WP_Error('koopo_invalid_format', 'Dates must be in YYYY-MM-DD HH:MM:SS format', ['status' => 400]);
      }

      // Ensure end > start
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

      // Trigger notification
      do_action('koopo_booking_rescheduled', $booking_id, $new_start, $new_end, $booking);

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

    // === REFUND ACTION (Commit 19/20) ===
    if ($action === 'refund') {
      if (!$order) {
        return new \WP_Error('koopo_no_order', 'No order found to refund', ['status' => 409]);
      }

      $st = $order->get_status();
      if (!in_array($st, ['processing', 'completed', 'on-hold'], true)) {
        return new \WP_Error(
          'koopo_cannot_refund',
          'Order must be processing, completed, or on-hold to refund. Current status: ' . $st,
          ['status' => 409]
        );
      }

      // Mark booking as refunded
      $result = Bookings::cancel_booking_safely($booking_id, 'refunded');
      if (!$result['ok']) {
        return new \WP_Error('koopo_refund_failed', $result['reason'] ?? 'Failed to mark as refunded', ['status' => 500]);
      }

      // Add order note
      $refund_note = 'Koopo: Vendor initiated refund for booking #'.$booking_id;
      if ($note) $refund_note .= ' — Reason: ' . wp_strip_all_tags($note);
      $order->add_order_note($refund_note);

      // Move order to refunded status
      $order->update_status('refunded', 'Koopo: Booking refunded by vendor');

      // Trigger hook for future auto-refund logic
      do_action('koopo_vendor_refund_initiated', $booking_id, $order_id, $order);

      return rest_ensure_response([
        'ok' => true,
        'action' => 'refunded',
        'message' => 'Booking marked as refunded. Order status updated. Process payment gateway refund in WooCommerce if needed.',
        'booking' => Bookings::get_booking($booking_id),
      ]);
    }

    return new \WP_Error('koopo_bad_action', 'Unknown action: ' . $action, ['status' => 400]);
  }
}