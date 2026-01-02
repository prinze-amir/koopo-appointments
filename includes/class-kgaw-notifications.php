<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Notifications {

  public static function init() {
    add_action('koopo_booking_conflict', [__CLASS__, 'email_conflict'], 10, 3);
    add_action('koopo_booking_confirmed_safe', [__CLASS__, 'email_confirmed'], 10, 2);
    add_action('koopo_booking_cancelled_safe', [__CLASS__, 'email_cancelled'], 10, 2);
    add_action('koopo_booking_refunded_safe', [__CLASS__, 'email_refunded'], 10, 2);
    add_action('koopo_booking_expired_safe', [__CLASS__, 'email_expired'], 10, 2);
  }

  private static function admin_email(): string {
    return (string) apply_filters('koopo_appt_admin_email', get_option('admin_email'));
  }

  private static function listing_owner_email(int $listing_id): string {
    $owner_id = (int) get_post_field('post_author', $listing_id);
    $u = $owner_id ? get_user_by('id', $owner_id) : null;
    return ($u && !empty($u->user_email)) ? $u->user_email : self::admin_email();
  }

  private static function customer_email(int $customer_id, ?int $order_id = null): string {
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order && $order->get_billing_email()) return $order->get_billing_email();
    }
    $u = $customer_id ? get_user_by('id', $customer_id) : null;
    return ($u && !empty($u->user_email)) ? $u->user_email : '';
  }

  private static function booking_context(int $booking_id): array {
    $b = Bookings::get_booking($booking_id);
    if (!$b) return [];

    $listing_id = (int) $b->listing_id;
    $service_id = (int) $b->service_id;

    return [
      'booking' => $b,
      'listing_id' => $listing_id,
      'listing_title' => $listing_id ? get_the_title($listing_id) : '',
      'service_title' => $service_id ? get_the_title($service_id) : '',
      'start' => (string) $b->start_datetime,
      'end' => (string) $b->end_datetime,
      'order_id' => (int) ($b->wc_order_id ?? 0),
    ];
  }

  public static function email_conflict(int $booking_id, int $conflict_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);

    $subject = sprintf('[Koopo] Booking conflict – #%d', $booking_id);

    $body_admin = self::render_email([
      'title' => 'Booking Conflict Detected',
      'lines' => [
        "Booking #{$booking_id} could not be confirmed because it conflicts with booking #{$conflict_id}.",
        "Listing: {$ctx['listing_title']} (ID {$ctx['listing_id']})",
        "Service: {$ctx['service_title']} (ID " . (int)$ctx['booking']->service_id . ")",
        "Time: {$ctx['start']} → {$ctx['end']}",
        "Action needed: refund or reschedule.",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'Booking Conflict on Your Listing',
      'lines' => [
        "A customer’s payment completed, but the selected slot became unavailable.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
        "Please resolve this in your Appointments dashboard (refund/reschedule).",
      ],
    ]);

    $body_customer = self::render_email([
      'title' => 'We couldn’t confirm your booking time',
      'lines' => [
        "We received your payment, but the selected time was no longer available.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']}",
        "Next steps: we’ll contact you to reschedule or issue a refund.",
      ],
    ]);

    self::send_mail($admin, $subject, $body_admin);
    self::send_mail($seller, $subject, $body_seller);

    if ($customer) {
      self::send_mail($customer, $subject, $body_customer);
    }
  }

  public static function email_confirmed(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);

    $subject = sprintf('[Koopo] Booking confirmed – #%d', $booking_id);

    $body_admin = self::render_email([
      'title' => 'Booking Confirmed',
      'lines' => [
        "Booking #{$booking_id} confirmed.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'New Confirmed Booking',
      'lines' => [
        "You have a new confirmed booking.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_customer = self::render_email([
      'title' => 'Your booking is confirmed',
      'lines' => [
        "Your booking is confirmed.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    self::send_mail($admin, $subject, $body_admin);
    self::send_mail($seller, $subject, $body_seller);
    if ($customer) self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_cancelled(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);

    $subject = sprintf('[Koopo] Booking cancelled – #%d', $booking_id);

    $body_admin = self::render_email([
      'title' => 'Booking Cancelled',
      'lines' => [
        "Booking #{$booking_id} was cancelled.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'Booking Cancelled',
      'lines' => [
        "A booking on your listing was cancelled.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_customer = self::render_email([
      'title' => 'Your booking was cancelled',
      'lines' => [
        "Your booking has been cancelled.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
        "If you were charged, a refund may be processed depending on the payment method.",
      ],
    ]);

    self::send_mail($admin, $subject, $body_admin);
    self::send_mail($seller, $subject, $body_seller);
    if ($customer) self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_refunded(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);

    $subject = sprintf('[Koopo] Booking refunded – #%d', $booking_id);

    $body_admin = self::render_email([
      'title' => 'Booking Refunded',
      'lines' => [
        "Booking #{$booking_id} was refunded.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'Booking Refunded',
      'lines' => [
        "A booking on your listing was refunded.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
      ],
    ]);

    $body_customer = self::render_email([
      'title' => 'Your refund is being processed',
      'lines' => [
        "Your booking was refunded.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time: {$ctx['start']} → {$ctx['end']}",
        "Refund timing depends on your payment method.",
      ],
    ]);

    self::send_mail($admin, $subject, $body_admin);
    self::send_mail($seller, $subject, $body_seller);
    if ($customer) self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_expired(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);
    if (!$customer) return;

    $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    $subject = sprintf('[Koopo] Booking hold expired – #%d', $booking_id);

    $body_customer = self::render_email([
      'title' => 'Your booking hold expired',
      'lines' => [
        "We held your selected time for {$minutes} minutes, but checkout wasn’t completed.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']} → {$ctx['end']}",
        "Please choose a new time and try again.",
      ],
    ]);

    self::send_mail($customer, $subject, $body_customer);
  }

  private static function send_mail(string $to, string $subject, string $body): void {
    if (!$to) return;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($to, $subject, $body, $headers);
  }

  private static function render_email(array $data): string {
    $title = esc_html($data['title'] ?? 'Notification');
    $lines = $data['lines'] ?? [];
    $lis = '';
    foreach ($lines as $l) $lis .= '<li>' . esc_html($l) . '</li>';
    return "
      <div style='font-family:Arial,sans-serif;line-height:1.5'>
        <h2>{$title}</h2>
        <ul>{$lis}</ul>
        <p style='opacity:.7'>— Koopo</p>
      </div>
    ";
  }
}
