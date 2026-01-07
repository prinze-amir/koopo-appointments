<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 20: Enhanced Vendor Bookings API with Refund Tooling
 * Location: includes/class-kgaw-vendor-bookings-api.php
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
        'search'     => ['type' => 'string',  'required' => false],
        'month'      => ['type' => 'string',  'required' => false],
        'year'       => ['type' => 'string',  'required' => false],
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

    // NEW: Get refund info for a booking
    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/refund-info', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_refund_info'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);
  }

  public static function can_access(): bool {
    if (!is_user_logged_in()) return false;
    if (function_exists('dokan_is_user_seller')) {
      return dokan_is_user_seller(get_current_user_id());
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
        'id' => (int) $r['id'],
        'listing_id' => (int) $r['listing_id'],
        'listing_title' => $listing_title ?: '',
        'service_id' => (int) $r['service_id'],
        'service_title' => $service_title ?: '',
        'customer_id' => (int) $r['customer_id'],
        'customer_name' => $customer_name ?: '',
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

      // Step 7: Return detailed success response
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
      $listing_title = $r['listing_id'] ? get_the_title((int)$r['listing_id']) : '';
      $service_title = $r['service_id'] ? get_the_title((int)$r['service_id']) : '';

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
}
