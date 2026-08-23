<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Checkout_Cart {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('koopo_mobile_native_checkout_abandoned', [__CLASS__, 'unlink_abandoned_order'], 10, 2);
  }

  public static function unlink_abandoned_order(int $order_id, array $booking_ids): void {
    foreach ($booking_ids as $booking_id) {
      $booking = Bookings::get_booking((int) $booking_id);
      if ($booking && (int) ($booking->wc_order_id ?? 0) === $order_id && (string) $booking->status === 'pending_payment') {
        Bookings::set_order_id((int) $booking_id, 0);
      }
    }
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/bookings/(?P<id>\d+)/checkout-cart', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'checkout_cart'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);

    register_rest_route('koopo/v1', '/bookings/(?P<id>\d+)/checkout-intent', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'checkout_intent'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  /**
   * Validate a booking for checkout and return the booking row.
   *
   * @param int $booking_id
   * @return object|\WP_Error
   */
  private static function validate_checkout_booking(int $booking_id) {
    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      return new \WP_Error('koopo_booking_not_found', 'Booking not found', ['status' => 404]);
    }

    if ((int) $booking->customer_id !== get_current_user_id()) {
      return new \WP_Error('koopo_forbidden', 'Forbidden', ['status' => 403]);
    }

    if ((string) $booking->status !== 'pending_payment') {
      return new \WP_Error('koopo_not_pending', 'Booking is not pending payment', ['status' => 409]);
    }

    $hold_minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($hold_minutes < 1) {
      $hold_minutes = 10;
    }

    $created_ts = strtotime((string) $booking->created_at);
    $hold_expires_ts = !empty($booking->hold_expires_at)
      ? strtotime((string) $booking->hold_expires_at . ' UTC')
      : 0;
    $now_ts     = current_time('timestamp');
    $has_order  = !empty($booking->wc_order_id) && (int) $booking->wc_order_id > 0;

    $expired = $hold_expires_ts
      ? time() >= $hold_expires_ts
      : ($created_ts && ($now_ts - $created_ts) > ($hold_minutes * 60));
    if (!$has_order && $expired) {
      Bookings::set_status($booking_id, 'expired');
      Bookings::archive_booking($booking_id, 'abandoned_hold');
      if (apply_filters('koopo_appt_delete_expired_booking', false, $booking_id, $booking)) {
        Bookings::delete_booking_data_by_id($booking_id);
      }
      return new \WP_Error('koopo_hold_expired', 'Booking hold expired', ['status' => 409]);
    }

    return $booking;
  }

  /**
   * Resolve the service product used to pay for the booking.
   *
   * @param object $booking
   * @return array{product_id:int,service_id:int}|\WP_Error
   */
  private static function resolve_booking_product($booking) {
    $service_id = absint($booking->service_id ?? 0);
    if (!$service_id) {
      return new \WP_Error('koopo_missing_service', 'Booking missing service_id', ['status' => 400]);
    }

    $product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);
    if (!$product_id || get_post_type($product_id) !== 'product') {
      $product_id = (int) WC_Service_Product::create_or_update_for_service($service_id);
      if (!$product_id || get_post_type($product_id) !== 'product') {
        return new \WP_Error('koopo_service_unavailable', 'This service is temporarily unavailable (missing product).', ['status' => 409]);
      }
    }

    $listing_author_id = (int) ($booking->listing_author_id ?? 0);
    $product_author_id = (int) get_post_field('post_author', $product_id);
    if ($listing_author_id && $product_author_id && $listing_author_id !== $product_author_id) {
      return new \WP_Error('koopo_vendor_mismatch', 'Service product vendor mismatch.', ['status' => 409]);
    }

    Product_Guard::enforce_hidden($product_id);

    return [
      'product_id' => $product_id,
      'service_id' => $service_id,
    ];
  }

  /**
   * Mirror the cart->order metadata so order hooks can reconcile the booking.
   *
   * @param \WC_Order_Item_Product $item
   * @param int                     $booking_id
   * @param object                  $booking
   * @return void
   */
  private static function add_booking_order_item_meta($item, int $booking_id, $booking): void {
    $item->add_meta_data('_koopo_booking_id', $booking_id, true);
    $item->add_meta_data('_koopo_listing_id', (int) ($booking->listing_id ?? 0), true);
    $item->add_meta_data('_koopo_provider_id', (int) ($booking->provider_id ?? 0), true);
    $item->add_meta_data('_koopo_resource_id', (int) ($booking->resource_id ?? 0), true);
    $item->add_meta_data('_koopo_payee_user_id', (int) ($booking->payee_user_id ?? $booking->listing_author_id ?? 0), true);
    $item->add_meta_data('_koopo_listing_author_id', (int) ($booking->listing_author_id ?? 0), true);
    $item->add_meta_data('_koopo_service_id', (string) ($booking->service_id ?? ''), true);
    $item->add_meta_data('_koopo_start_datetime', (string) ($booking->start_datetime ?? ''), true);
    $item->add_meta_data('_koopo_end_datetime', (string) ($booking->end_datetime ?? ''), true);
    $item->add_meta_data('_koopo_price', (string) ($booking->price ?? ''), true);
    $item->add_meta_data('_koopo_currency', (string) ($booking->currency ?? ''), true);
    if (!empty($booking->timezone)) {
      $item->add_meta_data('_koopo_timezone', (string) $booking->timezone, true);
    }
  }

  /**
   * Prefill order billing details from the booking record and current user.
   *
   * @param \WC_Order $order
   * @param object     $booking
   * @return void
   */
  private static function populate_order_customer_details(\WC_Order $order, $booking): void {
    $customer_name  = (string) Bookings::extra_from_record($booking, 'customer_name', '');
    $customer_email = (string) Bookings::extra_from_record($booking, 'customer_email', '');
    $customer_phone = (string) Bookings::extra_from_record($booking, 'customer_phone', '');
    $user           = !empty($booking->customer_id) ? get_userdata((int) $booking->customer_id) : null;

    $first_name = '';
    $last_name  = '';
    if ($customer_name) {
      $parts = preg_split('/\s+/', trim($customer_name));
      $first_name = $parts[0] ?? '';
      $last_name = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
    } elseif ($user) {
      $first_name = $user->first_name ?? '';
      $last_name = $user->last_name ?? '';
      $customer_email = $customer_email ?: ($user->user_email ?? '');
    }

    if ($first_name) {
      $order->set_billing_first_name($first_name);
    }
    if ($last_name) {
      $order->set_billing_last_name($last_name);
    }
    if ($customer_email) {
      $order->set_billing_email($customer_email);
    }
    if ($customer_phone) {
      $order->set_billing_phone($customer_phone);
    }
  }

  /**
   * Build the normalized response for web or native checkout callers.
   *
   * @param \WC_Order $order
   * @param int        $product_id
   * @param int        $booking_id
   * @param bool       $resume_order
   * @return array<string,mixed>
   */
  private static function build_order_checkout_payload(\WC_Order $order, int $product_id, int $booking_id, bool $resume_order): array {
    return [
      'checkout_url'       => $order->get_checkout_payment_url(),
      'payment_url'        => $order->get_checkout_payment_url(),
      'order_received_url' => $order->get_checkout_order_received_url(),
      'product_id'         => $product_id,
      'booking_id'         => $booking_id,
      'order_id'           => (int) $order->get_id(),
      'order_key'          => (string) $order->get_order_key(),
      'resume_order'       => $resume_order,
      'is_paid'            => $order->is_paid(),
    ];
  }

  /**
   * Create or resume a Woo order for a booking without relying on the browser cart.
   *
   * @param int $booking_id
   * @return array<string,mixed>|\WP_Error
   */
  public static function prepare_order_for_booking(int $booking_id) {
    if (!self::acquire_checkout_lock($booking_id, 5)) {
      return new \WP_Error('koopo_checkout_busy', 'Checkout is already being prepared. Please try again.', ['status' => 409]);
    }

    try {
      do_action('koopo_appt_checkout_lock_acquired', $booking_id);
      return self::prepare_order_for_booking_locked($booking_id);
    } finally {
      self::release_checkout_lock($booking_id);
    }
  }

  /**
   * The caller must hold the booking-scoped checkout lock.
   *
   * @param int $booking_id
   * @return array<string,mixed>|\WP_Error
   */
  private static function prepare_order_for_booking_locked(int $booking_id) {
    if (!function_exists('wc_create_order') || !function_exists('wc_get_order') || !function_exists('wc_get_product')) {
      return new \WP_Error('koopo_wc_unavailable', 'WooCommerce is not available', ['status' => 500]);
    }

    $booking = self::validate_checkout_booking($booking_id);
    if (is_wp_error($booking)) {
      return $booking;
    }

    $resolved = self::resolve_booking_product($booking);
    if (is_wp_error($resolved)) {
      return $resolved;
    }

    $product_id = (int) $resolved['product_id'];
    $existing_order_id = (int) ($booking->wc_order_id ?? 0);
    if ($existing_order_id > 0) {
      $existing_order = wc_get_order($existing_order_id);
      if ($existing_order) {
        $order_customer_id = (int) $existing_order->get_customer_id();
        if ($order_customer_id && $order_customer_id !== get_current_user_id()) {
          return new \WP_Error('koopo_order_forbidden', 'This order does not belong to the current user.', ['status' => 403]);
        }

        if ($existing_order->is_paid()) {
          $confirmation = Bookings::confirm_booking_safely($booking_id);
          if (!empty($confirmation['ok'])) {
            return self::build_order_checkout_payload($existing_order, $product_id, $booking_id, true);
          }
          return new \WP_Error('koopo_booking_conflict', 'This booking has already been paid and now requires manual review.', ['status' => 409]);
        }

        if (in_array($existing_order->get_status(), ['pending', 'failed', 'on-hold'], true)) {
          return self::build_order_checkout_payload($existing_order, $product_id, $booking_id, true);
        }

        return new \WP_Error('koopo_order_exists', 'An order already exists for this booking.', ['status' => 409]);
      }
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_purchasable()) {
      return new \WP_Error('koopo_service_unavailable', 'This service is temporarily unavailable.', ['status' => 409]);
    }

    $order = wc_create_order([
      'customer_id' => (int) ($booking->customer_id ?? 0),
      'status'      => 'pending',
    ]);
    if (is_wp_error($order)) {
      return new \WP_Error('koopo_order_failed', $order->get_error_message(), ['status' => 500]);
    }
    if (!$order instanceof \WC_Order) {
      $order = wc_get_order($order);
    }
    if (!$order) {
      return new \WP_Error('koopo_order_failed', 'Failed to create checkout order.', ['status' => 500]);
    }

    $item_id = $order->add_product($product, 1);
    if (!$item_id) {
      $order->delete(true);
      return new \WP_Error('koopo_order_item_failed', 'Unable to add the booking service to the order.', ['status' => 500]);
    }

    $item = $order->get_item($item_id);
    if (!$item) {
      $order->delete(true);
      return new \WP_Error('koopo_order_item_failed', 'Unable to load the booking order item.', ['status' => 500]);
    }

    $price = isset($booking->price) && is_numeric($booking->price) ? (float) $booking->price : 0.0;
    if (method_exists($item, 'set_subtotal')) {
      $item->set_subtotal($price);
    }
    if (method_exists($item, 'set_total')) {
      $item->set_total($price);
    }
    self::add_booking_order_item_meta($item, $booking_id, $booking);
    $item->save();

    $order->update_meta_data('_koopo_booking_ids', [(int) $booking_id]);
    if (!empty($booking->listing_author_id)) {
      $order->update_meta_data('_dokan_vendor_id', (int) $booking->listing_author_id);
    }
    if (!empty($booking->currency)) {
      $order->set_currency((string) $booking->currency);
    }

    self::populate_order_customer_details($order, $booking);
    $order->calculate_totals();
    $order->save();

    $current_order_id = (int) $order->get_id();
    $refreshed_booking = Bookings::get_booking($booking_id);
    $assigned_order_id = (int) ($refreshed_booking->wc_order_id ?? 0);
    if ($assigned_order_id && $assigned_order_id !== $current_order_id) {
      $order->delete(true);
      $assigned_order = wc_get_order($assigned_order_id);
      if ($assigned_order) {
        return self::build_order_checkout_payload($assigned_order, $product_id, $booking_id, true);
      }
    }

    $assigned_order_id = Bookings::assign_order_id_if_empty($booking_id, $current_order_id);
    if ($assigned_order_id !== $current_order_id) {
      $order->delete(true);
      if ($assigned_order_id > 0) {
        $assigned_order = wc_get_order($assigned_order_id);
        if ($assigned_order) {
          return self::build_order_checkout_payload($assigned_order, $product_id, $booking_id, true);
        }
      }
      return new \WP_Error('koopo_order_assignment_failed', 'The checkout order could not be assigned to this booking.', ['status' => 409]);
    }

    return self::build_order_checkout_payload($order, $product_id, $booking_id, false);
  }

  private static function acquire_checkout_lock(int $booking_id, int $timeout_seconds): bool {
    global $wpdb;
    if ($booking_id < 1) return false;
    $key = 'koopo_appt_checkout_' . $booking_id;
    $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, max(0, $timeout_seconds)));
    return (string) $result === '1';
  }

  private static function release_checkout_lock(int $booking_id): void {
    global $wpdb;
    if ($booking_id < 1) return;
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'koopo_appt_checkout_' . $booking_id));
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

    $booking = self::validate_checkout_booking($booking_id);
    if (is_wp_error($booking)) {
      return $booking;
    }

    $resolved = self::resolve_booking_product($booking);
    if (is_wp_error($resolved)) {
      return $resolved;
    }

    $product_id = (int) $resolved['product_id'];
    $existing_order_id = (int) ($booking->wc_order_id ?? 0);
    if ($existing_order_id > 0) {
      $existing_order = wc_get_order($existing_order_id);
      if ($existing_order) {
        if ($existing_order->is_paid()) {
          $confirmation = Bookings::confirm_booking_safely($booking_id);
          if (!empty($confirmation['ok'])) {
            return [
              'checkout_url' => $existing_order->get_checkout_order_received_url(),
              'product_id'   => $product_id,
              'booking_id'   => $booking_id,
              'order_id'     => $existing_order_id,
              'resume_order' => true,
            ];
          }

          return new \WP_Error('koopo_booking_conflict', 'This booking has already been paid and now requires manual review.', ['status' => 409]);
        }

        if (in_array($existing_order->get_status(), ['pending', 'failed', 'on-hold'], true)) {
          return [
            'checkout_url' => $existing_order->get_checkout_payment_url(),
            'product_id'   => $product_id,
            'booking_id'   => $booking_id,
            'order_id'     => $existing_order_id,
            'resume_order' => true,
          ];
        }

        return new \WP_Error('koopo_order_exists', 'An order already exists for this booking.', ['status' => 409]);
      }
    }

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

  public static function checkout_intent(\WP_REST_Request $req) {
    $booking_id = absint($req['id']);

    $result = self::prepare_order_for_booking($booking_id);
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
