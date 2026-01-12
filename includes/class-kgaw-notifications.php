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
    add_action('koopo_booking_rescheduled', [__CLASS__, 'email_rescheduled'], 10, 4); // NEW
    add_action('koopo_booking_pending_payment', [__CLASS__, 'email_pending_payment'], 10, 2);
    add_action('koopo_booking_pending_payment', [__CLASS__, 'notify_pending_payment'], 10, 2);
    add_action('koopo_booking_expired_safe', [__CLASS__, 'notify_expired'], 10, 2);

    if (function_exists('bp_notifications_add_notification')) {
      add_filter('bp_notifications_get_notifications_for_user', [__CLASS__, 'format_buddyboss_notifications'], 10, 9);
      add_filter('bp_notifications_get_registered_components', [__CLASS__, 'register_buddyboss_component']);
    }
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
    $tz = !empty($b->timezone) ? (string) $b->timezone : '';

    // Format dates for email display
    $start_formatted = Date_Formatter::format((string)$b->start_datetime, $tz, 'full');
    $duration_mins = (strtotime((string)$b->end_datetime) - strtotime((string)$b->start_datetime)) / 60;
    $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

    return [
      'booking' => $b,
      'listing_id' => $listing_id,
      'listing_title' => $listing_id ? get_the_title($listing_id) : '',
      'service_title' => $service_id ? get_the_title($service_id) : '',
      'start' => (string) $b->start_datetime,
      'end' => (string) $b->end_datetime,
      'start_formatted' => $start_formatted,
      'duration_formatted' => $duration_formatted,
      'timezone' => $tz,
      'timezone_abbr' => Date_Formatter::get_timezone_abbr($tz, (string)$b->start_datetime),
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

    $body_customer = self::render_email([
      'title' => 'Your booking is confirmed! ✓',
      'lines' => [
        "Your booking is confirmed for {$ctx['start_formatted']} ({$ctx['timezone_abbr']}).",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Duration: {$ctx['duration_formatted']}",
        "We look forward to seeing you!",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'New Confirmed Booking',
      'lines' => [
        "You have a new confirmed booking for {$ctx['start_formatted']}.",
        "Listing: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Duration: {$ctx['duration_formatted']}",
      ],
    ]);

    if ($customer) self::send_mail($customer, $subject, $body_customer);
    self::send_mail($seller, $subject, $body_seller);
  }

  public static function email_cancelled(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);
    $seller = self::listing_owner_email($ctx['listing_id']);

    $subject = sprintf('[Koopo] Booking cancelled – #%d', $booking_id);

    $body_customer = self::render_email([
      'title' => 'Your booking was cancelled',
      'lines' => [
        "Your booking for {$ctx['start_formatted']} has been cancelled.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "If you were charged, a refund may be processed depending on the payment method.",
      ],
    ]);

    if ($customer) self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_rescheduled(int $booking_id, string $new_start, string $new_end, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);
    $tz = $ctx['timezone'];
    
    $new_start_formatted = Date_Formatter::format($new_start, $tz, 'full');
    $duration_mins = (strtotime($new_end) - strtotime($new_start)) / 60;
    $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

    $subject = sprintf('[Koopo] Booking rescheduled – #%d', $booking_id);

    $body_customer = self::render_email([
      'title' => 'Your booking has been rescheduled',
      'lines' => [
        "Your booking has been moved to a new time:",
        "New Date & Time: {$new_start_formatted} ({$ctx['timezone_abbr']})",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Duration: {$duration_formatted}",
        "If you have any questions, please contact the business.",
      ],
    ]);

    if ($customer) self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_expired(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);
    if (!$customer) return;

    $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    $subject = sprintf('[Koopo] Booking hold expired – #%d', $booking_id);

    $listing_url = $ctx['listing_id'] ? get_permalink($ctx['listing_id']) : '';
    $body_customer = self::render_email_html([
      'title' => 'Your booking expired',
      'lines' => [
        "Your booking expired because checkout wasn’t completed within {$minutes} minutes.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']} → {$ctx['end']}",
        $listing_url ? 'Book a new appointment: <a href="' . esc_url($listing_url) . '">View listing</a>' : 'Please choose a new time and try again.',
      ],
    ]);

    self::send_mail($customer, $subject, $body_customer);
  }

  public static function email_pending_payment(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);
    if (!$customer) return;

    $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($minutes < 1) {
      $minutes = 10;
    }

    $pay_url = class_exists('\Koopo_Appointments\MyAccount')
      ? MyAccount::pay_now_url($booking_id)
      : '';

    $subject = sprintf('[Koopo] Payment required – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Complete your booking to confirm',
      'lines' => [
        "Your appointment is not confirmed yet.",
        "Please complete checkout within {$minutes} minutes to confirm your booking.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']} → {$ctx['end']}",
        $pay_url ? 'Pay now: <a href="' . esc_url($pay_url) . '">Complete checkout</a>' : '',
      ],
    ]);

    self::send_mail($customer, $subject, $body_customer);
  }

  public static function notify_pending_payment(int $booking_id, $booking_obj) {
    if (!function_exists('bp_notifications_add_notification')) return;
    $booking = $booking_obj ?: Bookings::get_booking($booking_id);
    if (!$booking) return;

    $user_id = (int) $booking->customer_id;
    $listing_id = (int) $booking->listing_id;

    bp_notifications_add_notification([
      'user_id'           => $user_id,
      'item_id'           => $booking_id,
      'secondary_item_id' => $listing_id,
      'component_name'    => 'koopo_appointments',
      'component_action'  => 'pending_payment',
      'date_notified'     => bp_core_current_time(),
      'is_new'            => 1,
    ]);
  }

  public static function notify_expired(int $booking_id, $booking_obj) {
    if (!function_exists('bp_notifications_add_notification')) return;
    $booking = $booking_obj ?: Bookings::get_booking($booking_id);
    if (!$booking) return;

    $user_id = (int) $booking->customer_id;
    $listing_id = (int) $booking->listing_id;

    bp_notifications_add_notification([
      'user_id'           => $user_id,
      'item_id'           => $booking_id,
      'secondary_item_id' => $listing_id,
      'component_name'    => 'koopo_appointments',
      'component_action'  => 'expired',
      'date_notified'     => bp_core_current_time(),
      'is_new'            => 1,
    ]);
  }

  public static function format_buddyboss_notifications($content, $user_id, $format = 'string', $action = '', $component = '', $notification_id = 0, $item_id = 0, $secondary_item_id = 0, $total_items = 0) {
    if ($component !== 'koopo_appointments') return $content;

    $booking = $item_id ? Bookings::get_booking((int) $item_id) : null;
    $listing_id = (int) $secondary_item_id;
    if (!$listing_id && $booking) {
      $listing_id = (int) $booking->listing_id;
    }

    $link = '';
    $text = '';

    if ($action === 'pending_payment') {
      $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
      if ($minutes < 1) {
        $minutes = 10;
      }
      $pay_url = class_exists('\Koopo_Appointments\MyAccount') ? MyAccount::pay_now_url((int) $item_id) : '';
      $link = $pay_url ?: home_url('/');
      $text = sprintf(
        'Your booking is not confirmed. Pay now to confirm (expires in %d minutes).',
        $minutes
      );
    } elseif ($action === 'expired') {
      $listing_link = $listing_id ? get_permalink($listing_id) : home_url('/');
      $link = $listing_link ?: home_url('/');
      $text = 'Your booking expired because checkout was not completed. Book a new appointment.';
    } else {
      return $content;
    }

    if ($format === 'array') {
      return [
        'text' => $text,
        'link' => $link,
      ];
    }

    return '<a href="' . esc_url($link) . '">' . esc_html($text) . '</a>';
  }

  public static function register_buddyboss_component($components) {
    if (empty($components) || !is_array($components)) {
      $components = [];
    }
    if (!in_array('koopo_appointments', $components, true)) {
      $components[] = 'koopo_appointments';
    }
    return $components;
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
      <div style='font-family:Arial,sans-serif;line-height:1.6;max-width:600px;margin:0 auto;'>
        <div style='background:#f7f7f7;padding:20px;border-radius:8px 8px 0 0;'>
          <h2 style='margin:0;color:#333;'>{$title}</h2>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #e5e5e5;'>
          <ul style='padding-left:20px;'>{$lis}</ul>
        </div>
        <div style='background:#f7f7f7;padding:15px;text-align:center;font-size:12px;color:#666;border-radius:0 0 8px 8px;'>
          <p style='margin:0;'>— Koopo Appointments</p>
        </div>
      </div>
    ";
  }

  private static function render_email_html(array $data): string {
    $title = esc_html($data['title'] ?? 'Notification');
    $lines = $data['lines'] ?? [];
    $lis = '';
    foreach ($lines as $l) {
      if (!$l) continue;
      $lis .= '<li>' . wp_kses_post($l) . '</li>';
    }
    return "
      <div style='font-family:Arial,sans-serif;line-height:1.6;max-width:600px;margin:0 auto;'>
        <div style='background:#f7f7f7;padding:20px;border-radius:8px 8px 0 0;'>
          <h2 style='margin:0;color:#333;'>{$title}</h2>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #e5e5e5;'>
          <ul style='padding-left:20px;'>{$lis}</ul>
        </div>
        <div style='background:#f7f7f7;padding:15px;text-align:center;font-size:12px;color:#666;border-radius:0 0 8px 8px;'>
          <p style='margin:0;'>— Koopo Appointments</p>
        </div>
      </div>
    ";
  }
}
