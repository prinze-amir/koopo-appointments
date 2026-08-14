<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Checkout {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/bookings/(?P<id>\d+)/checkout', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'create_checkout'],
      'permission_callback' => function() {
        return is_user_logged_in();
      }
    ]);
  }

  /**
   * Prepare a standard WooCommerce cart/checkout flow for a booking.
   *
   * Why cart-based?
   * - Keeps Dokan's normal checkout pipeline intact (commission + suborders)
   * - Lets Dokan Stripe Connect/Express split payouts normally
   */
  public static function create_checkout(\WP_REST_Request $req) {
    $booking_id = absint($req['id']);
    $result = Checkout_Cart::prepare_cart_for_booking($booking_id, true);
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
