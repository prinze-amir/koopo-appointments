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
        'status' => $r['status'],
        'price' => isset($r['price']) ? (float) $r['price'] : 0.0,
        'currency' => $r['currency'] ?? '',
        'wc_order_id' => isset($r['wc_order_id']) ? (int) $r['wc_order_id'] : 0,
        'created_at' => $r['created_at'] ?? '',
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
   * Actions: cancel, confirm, note, reschedule
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

    if ($action === 'cancel') {
      // Cancel booking; if order exists, leave a note and move order to on-hold to prompt refund handling if already paid.
      Bookings::cancel_booking_safely($booking_id, 'cancelled');
      if ($order) {
        $st = $order->get_status();
        $order->add_order_note('Koopo: Vendor cancelled booking #'.$booking_id . ($note ? ' — '.$note : ''));
        if (in_array($st, ['processing','completed'], true)) {
          $order->update_status('on-hold', 'Koopo: Booking cancelled by vendor; refund/reschedule may be required.');
        } elseif (in_array($st, ['pending','failed'], true)) {
          $order->update_status('cancelled', 'Koopo: Booking cancelled by vendor before payment.');
        }
      }
    } elseif ($action === 'confirm') {
      // Manual confirm (rare). Will no-op if already confirmed or conflicts.
      $res = Bookings::confirm_booking_safely($booking_id);
      if (!$res['ok']) {
        if ($order) $order->add_order_note('Koopo: Vendor attempted to confirm booking #'.$booking_id.' but it failed: '.$res['message']);
        return new \WP_Error('koopo_confirm_failed', $res['message'], ['status' => 409]);
      }
      if ($order) $order->add_order_note('Koopo: Vendor manually confirmed booking #'.$booking_id . ($note ? ' — '.$note : ''));
    } elseif ($action === 'reschedule') {
      $new_start = sanitize_text_field((string) $request->get_param('start_datetime'));
      $new_end   = sanitize_text_field((string) $request->get_param('end_datetime'));
      $tz        = $request->get_param('timezone');
      $tz        = $tz !== null ? sanitize_text_field((string) $tz) : null;

      if (!$new_start || !$new_end) {
        return new \WP_Error('koopo_dates_required', 'start_datetime and end_datetime are required.', ['status' => 400]);
      }

      $ok = Bookings::reschedule_booking_safely($booking_id, $new_start, $new_end, $tz);
      if (!$ok) {
        return new \WP_Error('koopo_reschedule_failed', 'Unable to reschedule (invalid dates or slot conflict).', ['status' => 409]);
      }

      if ($order) {
        $order->add_order_note('Koopo: Vendor rescheduled booking #'.$booking_id.' to '.$new_start.' - '.$new_end . ($tz ? ' ('.$tz.')' : ''));
      }
    } elseif ($action === 'note') {
      if (!$order) {
        return new \WP_Error('koopo_no_order', 'No WooCommerce order is associated with this booking.', ['status' => 409]);
      }
      $clean = wp_strip_all_tags($note);
      if (!$clean) {
        return new \WP_Error('koopo_note_required', 'Note is required.', ['status' => 400]);
      }
      $order->add_order_note('Koopo (vendor note) for booking #'.$booking_id.': '.$clean);
    } else {
      return new \WP_Error('koopo_bad_action', 'Unknown action', ['status' => 400]);
    }

    $updated = Bookings::get_booking($booking_id);
    return rest_ensure_response([
      'ok' => true,
      'booking' => $updated,
    ]);
  }
}
