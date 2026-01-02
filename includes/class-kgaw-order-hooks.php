<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Order_Hooks {

  public static function init() {
    // Payment completed successfully.
    add_action('woocommerce_payment_complete', [__CLASS__, 'maybe_confirm_booking'], 10, 1);

    // Backup hooks (some gateways go straight to processing/completed)
    add_action('woocommerce_order_status_processing', [__CLASS__, 'maybe_confirm_booking_from_order'], 10, 1);
    add_action('woocommerce_order_status_completed',  [__CLASS__, 'maybe_confirm_booking_from_order'], 10, 1);

    // If an order is cancelled/failed/refunded, release the slot.
    add_action('woocommerce_order_status_cancelled', [__CLASS__, 'maybe_cancel_bookings_from_order'], 10, 1);
    add_action('woocommerce_order_status_failed',    [__CLASS__, 'maybe_cancel_bookings_from_order'], 10, 1);
    add_action('woocommerce_order_status_refunded',  [__CLASS__, 'maybe_refund_bookings_from_order'], 10, 1);
    add_action('woocommerce_order_fully_refunded',   [__CLASS__, 'maybe_refund_bookings_from_order'], 10, 1);

  }

  private static function get_booking_ids_from_order(\WC_Order $order): array {
    $booking_ids = [];
    foreach ($order->get_items() as $item) {
      $bid = $item->get_meta('_koopo_booking_id', true);
      if ($bid) { $booking_ids[] = (int) $bid; }
    }
    return array_values(array_unique(array_filter($booking_ids)));
  }

  private static function get_meta_id_list(\WC_Order $order, string $key): array {
    $val = $order->get_meta($key, true);
    if (!is_array($val)) { $val = []; }
    return array_values(array_unique(array_map('intval', $val)));
  }

  private static function set_meta_id_list(\WC_Order $order, string $key, array $ids): void {
    $order->update_meta_data($key, array_values(array_unique(array_map('intval', $ids))));
  }


  public static function maybe_confirm_booking($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    self::confirm_from_order($order);
  }

  public static function maybe_confirm_booking_from_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    self::confirm_from_order($order);
  }

  private static function confirm_from_order(\WC_Order $order) {

  $booking_ids = self::get_booking_ids_from_order($order);

    if (!$booking_ids) return;

    $confirmed = self::get_meta_id_list($order, '_koopo_bookings_confirmed');

  foreach ($booking_ids as $booking_id) {
    $booking_id = (int) $booking_id;
    if (in_array($booking_id, $confirmed, true)) { continue; }
    $result = Bookings::confirm_booking_safely($booking_id);

    if (!empty($result['ok'])) {
      $confirmed[] = $booking_id;
      self::set_meta_id_list($order, '_koopo_bookings_confirmed', $confirmed);
      // Confirmed (or already confirmed)
      $order->add_order_note(sprintf('Koopo booking #%d confirmed (%s).', $booking_id, $result['reason']));
      continue;
    }

    // Handle conflict / other failure
    $reason = $result['reason'] ?? 'unknown';

    if ($reason === 'conflict') {
      $conflict_id = (int)($result['conflict_id'] ?? 0);

      $order->add_order_note(
        sprintf(
          'Koopo booking #%d NOT confirmed due to time conflict with booking #%d. Marked as conflict. Manual action required (refund or reschedule).',
          $booking_id,
          $conflict_id
        )
      );

      // Optionally place order on-hold so admin sees it immediately
      if ($order->get_status() !== 'on-hold') {
        $order->update_status('on-hold', 'Booking conflict detected; requires manual resolution.');
      }

      // Add a meta marker
      $order->update_meta_data('_koopo_booking_conflict', '1');
      $order->save();

      do_action('koopo_booking_conflict_order', $booking_id, $order->get_id(), $conflict_id);
      continue;
    }

    // Non-conflict failure: lock timeout, expired, bad_status, etc.
    $order->add_order_note(sprintf('Koopo booking #%d could not be confirmed: %s.', $booking_id, $reason));
  }
}

  public static function maybe_cancel_bookings_from_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    self::cancel_from_order($order, 'cancelled');
  }

  public static function maybe_refund_bookings_from_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    self::cancel_from_order($order, 'refunded');
  }

  private static function cancel_from_order(\WC_Order $order, string $new_status) {
    $booking_ids = self::get_booking_ids_from_order($order);

    if (!$booking_ids) return;

    $meta_key = ($new_status === 'refunded') ? '_koopo_bookings_refunded' : '_koopo_bookings_cancelled';
    $processed = self::get_meta_id_list($order, $meta_key);

    foreach ($booking_ids as $booking_id) {
    $booking_id = (int) $booking_id;
    if (in_array($booking_id, $processed, true)) { continue; }
      $result = Bookings::cancel_booking_safely($booking_id, $new_status);
      if (!empty($result['ok'])) {
      $processed[] = $booking_id;
      self::set_meta_id_list($order, $meta_key, $processed);
        $order->add_order_note(sprintf('Koopo booking #%d marked %s (%s).', $booking_id, $new_status, $result['reason']));
      } else {
        $order->add_order_note(sprintf('Koopo booking #%d could not be marked %s: %s.', $booking_id, $new_status, $result['reason'] ?? 'unknown'));
      }
    }
  }


}
