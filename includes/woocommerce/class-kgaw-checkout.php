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
    $booking = Bookings::get_booking($booking_id);

    if (!$booking) return new \WP_REST_Response(['error' => 'Booking not found'], 404);

    // Only customer can pay for their booking
    if ((int)$booking->customer_id !== get_current_user_id()) {
      return new \WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    if ($booking->status !== 'pending_payment') {
      return new \WP_REST_Response(['error' => 'Booking is not pending payment'], 409);
    }

    // booking must know the service_id (we store service_id as the Service CPT post ID)
    $service_id = absint($booking->service_id);
    if (!$service_id) {
      return new \WP_REST_Response(['error' => 'Booking missing service_id'], 400);
    }

    $product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);
    if (!$product_id) {
      return new \WP_REST_Response(['error' => 'Service product not found'], 404);
    }

    // Safety: ensure product is owned by the listing author/vendor (Approach A)
    $listing_author_id = (int) $booking->listing_author_id;
    $product_author_id = (int) get_post_field('post_author', $product_id);
    if ($listing_author_id && $product_author_id && $listing_author_id !== $product_author_id) {
      return new \WP_REST_Response([
        'error' => 'Service product vendor mismatch',
        'details' => [
          'listing_author_id' => $listing_author_id,
          'product_author_id' => $product_author_id,
        ],
      ], 409);
    }

    // Force product hidden (in case it was edited)
    Product_Guard::enforce_hidden($product_id);

    // Clear cart to avoid mixing (keeps payout/fulfillment simple)
    if (function_exists('WC') && WC()->cart) {
      WC()->cart->empty_cart();
    }

    // Add to cart; Cart hooks will attach booking meta & lock the price
    $_REQUEST['koopo_booking_id'] = $booking_id;

    $added = WC()->cart->add_to_cart($product_id, 1);
    if (!$added) {
      return new \WP_REST_Response(['error' => 'Failed to add to cart'], 500);
    }

    return new \WP_REST_Response([
      'checkout_url' => wc_get_checkout_url(),
      'product_id'   => $product_id,
      'booking_id'   => $booking_id,
    ], 200);
  }
}
