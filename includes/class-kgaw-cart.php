<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Cart {

  public static function init() {
    // Add booking meta into cart item data (so it follows into the order)
    add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);

    // Display booking details in cart/checkout (optional but helpful)
    add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_data'], 10, 2);

    // Persist booking meta into order line item
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_line_item_meta'], 10, 4);

    // Enforce booking price at runtime (prevents tampering and keeps totals aligned with booking)
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'sync_booking_price_to_cart'], 10, 1);
  }

  /**
   * If a cart item is a Koopo booking, override the product price with the booking's price.
   * This ensures checkout totals match the booking record even if the product price changes.
   */
  public static function sync_booking_price_to_cart($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!$cart || !method_exists($cart, 'get_cart')) return;

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
      if (empty($cart_item['koopo_booking_id'])) continue;

      $booking_id = (int) $cart_item['koopo_booking_id'];
      $booking = Bookings::get_booking($booking_id);
      if (!$booking) continue;

      $price = (float) $booking->price;
      if ($price <= 0) continue;

      if (!empty($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'set_price')) {
        $cart_item['data']->set_price($price);
      }
    }
  }

  /**
   * Attach booking meta when product is added to cart using ?koopo_booking_id=123
   */
  public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    // Prefer explicit cart item data (e.g. from WC()->cart->add_to_cart(..., ['koopo_booking_id'=>..]))
    $booking_id = 0;
    if (!empty($cart_item_data['koopo_booking_id'])) {
      $booking_id = absint($cart_item_data['koopo_booking_id']);
    } elseif (!empty($_REQUEST['koopo_booking_id'])) {
      // Back-compat: allow legacy query-param/request flow
      $booking_id = absint($_REQUEST['koopo_booking_id']);
    }

    if (!$booking_id) return $cart_item_data;if (!$booking_id) return $cart_item_data;

    $booking = Bookings::get_booking($booking_id);
    if (!$booking) return $cart_item_data;

    // Ensure only the booking owner can pay
    if ((int)$booking->customer_id !== get_current_user_id()) return $cart_item_data;

    $cart_item_data['koopo_booking_id'] = $booking_id;
    $cart_item_data['unique_key'] = md5($booking_id . '|' . microtime(true)); // prevents merge

    return $cart_item_data;
  }

  public static function display_cart_item_data($item_data, $cart_item) {
    if (empty($cart_item['koopo_booking_id'])) return $item_data;

    $booking = Bookings::get_booking((int)$cart_item['koopo_booking_id']);
    if (!$booking) return $item_data;

    $item_data[] = [
      'name'  => 'Booking',
      'value' => '#' . (int)$booking->id,
    ];
    $item_data[] = [
      'name'  => 'Date/Time',
      'value' => $booking->start_datetime,
    ];

    return $item_data;
  }

  public static function add_order_line_item_meta($item, $cart_item_key, $values, $order) {
    if (empty($values['koopo_booking_id'])) return;

    $booking_id = (int)$values['koopo_booking_id'];
    $item->add_meta_data('_koopo_booking_id', $booking_id, true);

    // Link booking -> order as early as possible (order is being created at checkout).
    // This is safe/idempotent and helps later reconciliation.
    if ($order && is_object($order) && method_exists($order, 'get_id')) {
      Bookings::set_order_id($booking_id, (int) $order->get_id());
    }

      // Also store a quick order-level index for debugging/reporting.
      if ($order && is_object($order) && method_exists($order, 'get_meta')) {
        $existing = $order->get_meta('_koopo_booking_ids', true);
        if (!is_array($existing)) { $existing = []; }
        if (!in_array($booking_id, $existing, true)) {
          $existing[] = $booking_id;
          $order->update_meta_data('_koopo_booking_ids', array_values(array_unique(array_map('intval', $existing))));
        }
      }

    $booking = Bookings::get_booking($booking_id);
    if ($booking) {
      $item->add_meta_data('_koopo_listing_id', (int) $booking->listing_id, true);
      $item->add_meta_data('_koopo_listing_author_id', (int) $booking->listing_author_id, true);
      $item->add_meta_data('_koopo_service_id', (string) $booking->service_id, true);
      $item->add_meta_data('_koopo_start_datetime', (string) $booking->start_datetime, true);
      $item->add_meta_data('_koopo_end_datetime', (string) $booking->end_datetime, true);
      $item->add_meta_data('_koopo_price', (string) $booking->price, true);
      $item->add_meta_data('_koopo_currency', (string) $booking->currency, true);
      if (!empty($booking->timezone)) {
        $item->add_meta_data('_koopo_timezone', (string) $booking->timezone, true);
      }
    }
  }
}
