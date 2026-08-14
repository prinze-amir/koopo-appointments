<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Order_Hooks {
  private const CONFIRMATION_COMPLETE_META = '_koopo_booking_confirmation_complete';
  private const GATEWAY_FEE_META = 'dokan_gateway_fee';

  /** @var array<int,bool> */
  private static $confirming_orders = [];

  /** @var array<int,string> */
  private static $gateway_fee_locks = [];

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

    // Booking confirmations replace the generic order lifecycle emails.
    foreach ([
      'new_order',
      'customer_processing_order',
      'customer_completed_order',
      'dokan_vendor_new_order',
      'dokan_vendor_completed_order',
    ] as $email_id) {
      add_filter(
        'woocommerce_email_enabled_' . $email_id,
        [__CLASS__, 'maybe_disable_generic_booking_email'],
        20,
        2
      );
    }

    // Dokan's fee handler is not idempotent. Serialize it and skip a repeated
    // callback once the fee has already been persisted for a booking order.
    add_action('dokan_process_payment_gateway_fee', [__CLASS__, 'lock_booking_gateway_fee'], 1, 3);
    add_filter('dokan_should_process_payment_gateway_fee', [__CLASS__, 'should_process_booking_gateway_fee'], 20, 4);
    add_action('dokan_process_payment_gateway_fee', [__CLASS__, 'unlock_booking_gateway_fee'], PHP_INT_MAX, 3);
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

  private static function acquire_named_lock(string $key, int $timeout_seconds = 5): bool {
    global $wpdb;
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $timeout_seconds));
    return (string) $got === '1';
  }

  private static function release_named_lock(string $key): void {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
  }

  private static function confirmation_lock_key(int $order_id): string {
    return 'koopo_appt_order_' . $order_id;
  }

  private static function gateway_fee_lock_key(int $order_id): string {
    return 'koopo_appt_gateway_fee_' . $order_id;
  }

  private static function is_booking_only_order($order): bool {
    if (is_numeric($order)) {
      $order = wc_get_order((int) $order);
    }
    return $order instanceof \WC_Order && self::order_has_only_booking_items($order);
  }

  public static function maybe_disable_generic_booking_email($enabled, $order) {
    return self::is_booking_only_order($order) ? false : $enabled;
  }

  public static function lock_booking_gateway_fee($processing_fee, $order, $gateway_id): void {
    if (!$order instanceof \WC_Order || !self::is_booking_only_order($order)) {
      return;
    }

    $order_id = (int) $order->get_id();
    if ($order_id < 1 || isset(self::$gateway_fee_locks[$order_id])) {
      return;
    }

    $key = self::gateway_fee_lock_key($order_id);
    if (self::acquire_named_lock($key, 5)) {
      self::$gateway_fee_locks[$order_id] = $key;
    }
  }

  public static function should_process_booking_gateway_fee($should_process, $processing_fee, $order, $gateway_id) {
    if (!$should_process || !$order instanceof \WC_Order || !self::is_booking_only_order($order)) {
      return $should_process;
    }

    $order_id = (int) $order->get_id();
    if (!isset(self::$gateway_fee_locks[$order_id])) {
      return false;
    }

    $fresh_order = wc_get_order($order_id);
    if (!$fresh_order) {
      return false;
    }

    return $fresh_order->get_meta(self::GATEWAY_FEE_META, true) === '';
  }

  public static function unlock_booking_gateway_fee($processing_fee, $order, $gateway_id): void {
    if (!$order instanceof \WC_Order) {
      return;
    }

    $order_id = (int) $order->get_id();
    if (!isset(self::$gateway_fee_locks[$order_id])) {
      return;
    }

    self::release_named_lock(self::$gateway_fee_locks[$order_id]);
    unset(self::$gateway_fee_locks[$order_id]);
  }

  public static function maybe_confirm_booking($order_id) {
    self::confirm_order_once((int) $order_id);
  }

  public static function maybe_confirm_booking_from_order($order_id) {
    self::confirm_order_once((int) $order_id);
  }

  private static function confirm_order_once(int $order_id): void {
    if ($order_id < 1 || isset(self::$confirming_orders[$order_id])) {
      return;
    }

    $lock_key = self::confirmation_lock_key($order_id);
    if (!self::acquire_named_lock($lock_key, 5)) {
      return;
    }

    self::$confirming_orders[$order_id] = true;
    try {
      $order = wc_get_order($order_id);
      if (!$order) {
        return;
      }

      if ($order->get_meta(self::CONFIRMATION_COMPLETE_META, true) === 'yes') {
        self::maybe_auto_complete_booking_order($order);
        return;
      }

      self::confirm_from_order($order);
    } finally {
      unset(self::$confirming_orders[$order_id]);
      self::release_named_lock($lock_key);
    }
  }

  private static function confirm_from_order(\WC_Order $order): void {

    $booking_ids = self::get_booking_ids_from_order($order);

    if (!$booking_ids) return;

    $existing_vendor = (int) $order->get_meta('_dokan_vendor_id', true);
    if (!$existing_vendor) {
      $vendor_ids = [];
      foreach ($booking_ids as $booking_id) {
        $booking = Bookings::get_booking($booking_id);
        if ($booking && !empty($booking->listing_author_id)) {
          $vendor_ids[] = (int) $booking->listing_author_id;
        }
      }
      $vendor_ids = array_values(array_unique(array_filter($vendor_ids)));
      if (count($vendor_ids) === 1) {
        $order->update_meta_data('_dokan_vendor_id', $vendor_ids[0]);
        $order->save();
      }
    }

    $confirmed = self::get_meta_id_list($order, '_koopo_bookings_confirmed');
    $all_confirmed = true;
    $newly_confirmed = [];

    foreach ($booking_ids as $booking_id) {
      $booking_id = (int) $booking_id;
      if (in_array($booking_id, $confirmed, true)) { continue; }
      $result = Bookings::confirm_booking_safely($booking_id);

      if (!empty($result['ok'])) {
        $confirmed[] = $booking_id;
        if (($result['reason'] ?? '') === 'confirmed') {
          $newly_confirmed[] = $booking_id;
        }
        continue;
      }
      $all_confirmed = false;

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

    if ($all_confirmed) {
      self::set_meta_id_list($order, '_koopo_bookings_confirmed', $confirmed);
      $order->save();

      foreach ($newly_confirmed as $booking_id) {
        $order->add_order_note(sprintf('Koopo appointment #%d confirmed.', $booking_id));
      }

      // REST-created appointment orders do not pass through Dokan's normal
      // checkout sync hook, so perform the one required sync here.
      Bookings::maybe_sync_dokan_order($order);

      // Persist the completion guard before changing status. The completed
      // status hook can now re-enter safely and will immediately return.
      $order->update_meta_data(self::CONFIRMATION_COMPLETE_META, 'yes');
      $order->save();

      self::maybe_auto_complete_booking_order($order);
    }
  }

  private static function maybe_auto_complete_booking_order(\WC_Order $order): void {
    if (!self::order_has_only_booking_items($order)) {
      return;
    }

    $status = $order->get_status();
    if (in_array($status, ['processing', 'on-hold', 'pending'], true)) {
      $order->update_status('completed', 'Koopo appointment confirmed; order auto-completed.');
    }
  }

  private static function order_has_only_booking_items(\WC_Order $order): bool {
    $items = $order->get_items();
    if (!$items) return false;
    foreach ($items as $item) {
      $bid = $item->get_meta('_koopo_booking_id', true);
      if (empty($bid)) {
        return false;
      }
    }
    return true;
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

  private static function booking_refund_amount_from_order(\WC_Order $order, int $booking_id): float {
    $amount = 0.0;
    foreach ($order->get_items() as $item) {
      $item_booking_id = (int) $item->get_meta('_koopo_booking_id', true);
      if ($item_booking_id !== $booking_id) {
        continue;
      }
      $amount += (float) $item->get_total();
      if (method_exists($item, 'get_total_tax')) {
        $amount += (float) $item->get_total_tax();
      }
    }

    if ($amount > 0) {
      return round($amount, 2);
    }

    $booking = Bookings::get_booking($booking_id);
    if ($booking && isset($booking->price) && is_numeric($booking->price)) {
      return round((float) $booking->price, 2);
    }

    return 0.0;
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
        if ($new_status === 'refunded') {
          $booking_refund_amount = self::booking_refund_amount_from_order($order, $booking_id);
          Bookings::update_booking_extras($booking_id, [
            'cancelled_by' => 'system',
            'refund_amount' => $booking_refund_amount,
            'refund_status' => 'refunded',
          ]);
        } else {
          Bookings::update_booking_extras($booking_id, [
            'cancelled_by' => 'system',
            'refund_amount' => 0,
            'refund_status' => 'none',
          ]);
        }
      } else {
        $order->add_order_note(sprintf('Koopo booking #%d could not be marked %s: %s.', $booking_id, $new_status, $result['reason'] ?? 'unknown'));
      }
    }
  }


}
