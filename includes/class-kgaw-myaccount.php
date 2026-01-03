<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class MyAccount {

  public static function init() {
    add_action('init', [__CLASS__, 'add_endpoint']);
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
    add_action('woocommerce_account_koopo-appointments_endpoint', [__CLASS__, 'render']);
    add_action('template_redirect', [__CLASS__, 'maybe_handle_pay_now']);
    add_action('template_redirect', [__CLASS__, 'maybe_handle_cancel_booking']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
  }

  public static function add_endpoint() {
    add_rewrite_endpoint('koopo-appointments', EP_ROOT | EP_PAGES);
  }

  public static function menu_item($items) {
        // Put it near subscriptions/orders
    $new = [];
    foreach ($items as $k => $v) {
      $new[$k] = $v;
      if ($k === 'subscriptions') {
        $new['koopo-appointments'] = __('Appointments', 'koopo');
      }
    }
    if (!isset($new['koopo-appointments'])) {
      $new['koopo-appointments'] = __('Appointments', 'koopo');
    }
    return $new;
  }

  public static function render() {
    if (!is_user_logged_in()) return;

    $user_id = get_current_user_id();
    $rows = Bookings::get_bookings_for_customer($user_id, 50);

    echo '<h3>Your Appointments</h3>';

    if (!$rows) {
      echo '<p>No appointments yet.</p>';
      return;
    }

    echo '<table class="shop_table shop_table_responsive koopo-appts-table">';
    echo '<thead><tr>';
    echo '<th>Date & Time</th>';
    echo '<th>Business</th>';
    echo '<th>Service</th>';
    echo '<th>Duration</th>';
    echo '<th>Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $b) {
      $listing_title = get_the_title((int)$b->listing_id);
      $service_title = get_the_title((int)$b->service_id);

      // Format datetime with timezone
      $tz = !empty($b->timezone) ? (string)$b->timezone : '';
      $datetime_display = Date_Formatter::format((string)$b->start_datetime, $tz, 'relative');
      
      // Calculate duration
      $start_ts = strtotime((string)$b->start_datetime);
      $end_ts = strtotime((string)$b->end_datetime);
      $duration_mins = ($end_ts - $start_ts) / 60;
      $duration_display = Date_Formatter::format_duration((int)$duration_mins);

      // Status badge
      $status = (string)$b->status;
      $status_class = 'koopo-badge--' . sanitize_html_class($status);
      $status_label = ucfirst(str_replace('_', ' ', $status));
      $status_badge = '<span class="koopo-badge ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';

      // Actions
      $actions = '';
      if ($status === 'pending_payment') {
        $pay_url = self::pay_now_url((int)$b->id);
        $actions = '<a class="button" href="' . esc_url($pay_url) . '">' . esc_html__('Pay now', 'koopo') . '</a>';
      }

      if (Bookings::customer_can_cancel($b)) {
        $cancel_url = self::cancel_booking_url((int)$b->id);
        $cancel_btn = '<a class="button" href="' . esc_url($cancel_url) . '" onclick="return confirm(\'Cancel this booking?\');">' . esc_html__('Cancel', 'koopo') . '</a>';
        $actions = $actions ? ($actions . ' ' . $cancel_btn) : $cancel_btn;
      }

      if (!$actions) {
        $actions = '—';
      }

      echo '<tr>';
      echo '<td data-title="Date & Time">' . esc_html($datetime_display) . '</td>';
      echo '<td data-title="Business">' . esc_html($listing_title) . '</td>';
      echo '<td data-title="Service">' . esc_html($service_title) . '</td>';
      echo '<td data-title="Duration">' . esc_html($duration_display) . '</td>';
      echo '<td data-title="Status">' . $status_badge . '</td>';
      echo '<td data-title="Actions">' . $actions . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    if (Features::wc_subscriptions_active()) {
      echo '<p><a href="' . esc_url(wc_get_account_endpoint_url('subscriptions')) . '">Manage your subscriptions</a></p>';
    }
  }

  /**
   * Builds a signed “Pay now” link that prepares the cart and redirects to checkout.
   */
  public static function pay_now_url(int $booking_id): string {
    $base = function_exists('wc_get_account_endpoint_url')
      ? wc_get_account_endpoint_url('koopo-appointments')
      : home_url('/my-account/koopo-appointments/');

    return add_query_arg([
      'koopo_pay_booking' => $booking_id,
      '_wpnonce'          => wp_create_nonce('koopo_pay_booking_' . $booking_id),
    ], $base);
  }

/**
 * Builds a signed “Cancel” link for a booking (customer-side).
 */
  public static function cancel_booking_url(int $booking_id): string {
    $base = function_exists('wc_get_account_endpoint_url')
      ? wc_get_account_endpoint_url('koopo-appointments')
      : home_url('/my-account/koopo-appointments/');

    return add_query_arg([
      'koopo_cancel_booking' => $booking_id,
      '_wpnonce'             => wp_create_nonce('koopo_cancel_booking_' . $booking_id),
    ], $base);
  }

/**
   * Handles the “Pay now” flow from the My Account page.
   * Prepares cart using the standard Dokan/Woo checkout pipeline.
   */
    public static function maybe_handle_pay_now() {
    if (!is_user_logged_in()) return;
    if (!function_exists('is_account_page') || !is_account_page()) return;

    $booking_id = isset($_GET['koopo_pay_booking']) ? absint($_GET['koopo_pay_booking']) : 0;
    if (!$booking_id) return;

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'koopo_pay_booking_' . $booking_id)) {
      wp_die(__('Invalid request.', 'koopo'));
    }

    $result = Checkout_Cart::prepare_cart_for_booking($booking_id, true);
    if (is_wp_error($result)) {
      $msg = $result->get_error_message();
      wc_add_notice($msg, 'error');
      wp_safe_redirect(wc_get_account_endpoint_url('koopo-appointments'));
      exit;
    }

    wp_safe_redirect($result['checkout_url']);
    exit;
  }

  public static function maybe_handle_cancel_booking() {
    if (!is_user_logged_in()) return;
    if (empty($_GET['koopo_cancel_booking'])) return;

    $booking_id = absint($_GET['koopo_cancel_booking']);
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';

    if (!$booking_id || !wp_verify_nonce($nonce, 'koopo_cancel_booking_' . $booking_id)) {
      wp_die(__('Invalid request.', 'koopo-geo-appointments'));
    }

    $booking = Bookings::get_booking($booking_id);
    if (!$booking) {
      wp_die(__('Booking not found.', 'koopo-geo-appointments'));
    }

    $user_id = get_current_user_id();
    if ((int)$booking->customer_id !== (int)$user_id && !current_user_can('manage_options')) {
      wp_die(__('Not allowed.', 'koopo-geo-appointments'));
    }

    if (!Bookings::customer_can_cancel($booking)) {
      wp_die(__('This booking cannot be cancelled at this time.', 'koopo-geo-appointments'));
    }

    // Cancel booking and adjust related order safely if present.
    Bookings::cancel_booking_safely((int)$booking->id, 'cancelled');

    if (!empty($booking->wc_order_id)) {
      $order = wc_get_order((int)$booking->wc_order_id);
      if ($order) {
        $order->add_order_note(sprintf('Customer cancelled booking #%d.', (int)$booking->id));
        $status = $order->get_status();
        if (in_array($status, ['processing','completed'], true)) {
          $order->update_status('on-hold', 'Booking cancelled by customer; review refund/reschedule.');
        } else {
          $order->update_status('cancelled', 'Booking cancelled by customer.');
        }
      }
    }

        // Redirect back to appointments page.

    $url = wc_get_account_endpoint_url('koopo-appointments');
    wp_safe_redirect($url);
    exit;
  }

  public static function enqueue_styles() {
    if (!function_exists('is_account_page') || !is_account_page()) return;
    // Only load when our endpoint is being viewed.
    global $wp;
    if (!isset($wp->query_vars['koopo-appointments']) && empty($_GET['koopo_pay_booking']) && empty($_GET['koopo_cancel_booking'])) {
      return;
    }

    wp_enqueue_style(
      'koopo-appt-badges',
      plugins_url('../assets/appointments-badges.css', __FILE__),
      [],
      '0.1.0'
    );
  }
}
