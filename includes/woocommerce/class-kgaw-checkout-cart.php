<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Checkout_Cart {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/bookings/(?P<id>\d+)/checkout-cart', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'checkout_cart'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  /**
   * Prepares the WooCommerce cart for a booking and returns checkout URL.
   * Used by both REST and UI “Pay now” links.
   *
   * @param int  $booking_id
   * @param bool $clear_cart
   * @return array{checkout_url:string, product_id:int, booking_id:int}|\WP_Error
   */
  public static function prepare_cart_for_booking(int $booking_id, bool $clear_cart = true) {
    if ( ! function_exists('WC') || ! WC() ) {
      return new \WP_Error('koopo_wc_unavailable', 'WooCommerce is not available');
    }

    // Ensure WooCommerce frontend bits + session + cart are loaded.
    // REST requests do not always bootstrap these automatically.
    if ( method_exists( WC(), 'frontend_includes' ) ) {
      WC()->frontend_includes();
    }

    if ( function_exists('wc_load_cart') ) {
      wc_load_cart();
    }

    if ( method_exists( WC(), 'initialize_session' ) ) {
      WC()->initialize_session();
    }

    if ( method_exists( WC(), 'initialize_cart' ) ) {
      WC()->initialize_cart();
    }

    // Final fallback for older Woo versions.
    if ( null === WC()->session && class_exists('WC_Session_Handler') ) {
      WC()->session = new \WC_Session_Handler();
      WC()->session->init();
    }

    if ( null === WC()->customer && class_exists('WC_Customer') ) {
      WC()->customer = new \WC_Customer( get_current_user_id(), true );
    }

    if ( null === WC()->cart && class_exists('WC_Cart') ) {
      WC()->cart = new \WC_Cart();
    }

    if ( ! WC()->cart ) {
      return new \WP_Error('koopo_wc_unavailable', 'WooCommerce cart is not available');
    }

    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('koopo_booking_not_found', 'Booking not found', ['status' => 404]);
    }

    if ((int)$booking->customer_id !== get_current_user_id()) {
      return new \WP_Error('koopo_forbidden', 'Forbidden', ['status' => 403]);
    }

    if ((string)$booking->status !== 'pending_payment') {
      return new \WP_Error('koopo_not_pending', 'Booking is not pending payment', ['status' => 409]);
    }

    // Enforce hold expiration even if cleanup cron hasn't run yet.
    $hold_minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($hold_minutes < 1) { $hold_minutes = 10; }

    $created_ts = strtotime((string) $booking->created_at);
    $now_ts     = current_time('timestamp');

    // Do not expire holds once an order exists; checkout/payment may take longer than the hold window.
    $has_order  = !empty($booking->wc_order_id) && (int)$booking->wc_order_id > 0;

    if (!$has_order && $created_ts && ($now_ts - $created_ts) > ($hold_minutes * 60)) {
      // Mark expired and stop checkout.
      Bookings::set_status($booking_id, 'expired');
      if (apply_filters('koopo_appt_delete_expired_booking', true, $booking_id, $booking)) {
        Bookings::delete_booking_data_by_id($booking_id);
      }
      return new \WP_Error('koopo_hold_expired', 'Booking hold expired', ['status' => 409]);
    }


    $service_id = absint($booking->service_id);
    if (!$service_id) {
      return new \WP_Error('koopo_missing_service', 'Booking missing service_id', ['status' => 400]);
    }

    $product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);

    // Self-heal: if the service product was deleted manually but the service remains,
    // recreate/update it on-demand so Dokan + Woo checkout flow can proceed.
    if ( !$product_id || get_post_type($product_id) !== 'product' ) {
      $product_id = (int) WC_Service_Product::create_or_update_for_service($service_id);
      if ( !$product_id || get_post_type($product_id) !== 'product' ) {
        return new \WP_Error('koopo_service_unavailable', 'This service is temporarily unavailable (missing product).', ['status' => 409]);
      }
    }
// Ensure hidden/virtual settings are enforced.
    Product_Guard::enforce_hidden($product_id);

    $clear_cart = (bool) apply_filters('koopo_appt_checkout_clear_cart', $clear_cart, $booking_id, $booking);
    if ($clear_cart) {
      WC()->cart->empty_cart();
    } else {
      if (!WC()->cart->is_empty()) {
        return new \WP_Error('koopo_cart_not_empty', 'Cart is not empty', ['status' => 409]);
      }
    }

    $added = WC()->cart->add_to_cart($product_id, 1, 0, [], [
      'koopo_booking_id' => $booking_id,
    ]);

    if (!$added) {
      return new \WP_Error('koopo_add_to_cart_failed', 'Failed to add to cart', ['status' => 500]);
    }

    return [
      'checkout_url' => wc_get_checkout_url(),
      'product_id'   => $product_id,
      'booking_id'   => $booking_id,
    ];
  }

  public static function checkout_cart(\WP_REST_Request $req) {
    $booking_id = absint($req['id']);

    $body = (array) $req->get_json_params();
    $clear_cart = array_key_exists('clear_cart', $body) ? (bool) $body['clear_cart'] : true;

    $result = self::prepare_cart_for_booking($booking_id, $clear_cart);
    if (is_wp_error($result)) {
      $status = (int) ($result->get_error_data()['status'] ?? 400);
      return new \WP_REST_Response([
        'error' => $result->get_error_message(),
        'code'  => $result->get_error_code(),
      ], $status);
    }

    return new \WP_REST_Response($result, 200);
  }
}
