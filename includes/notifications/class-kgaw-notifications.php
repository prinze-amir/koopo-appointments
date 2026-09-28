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
    add_action('koopo_booking_pending_payment', [__CLASS__, 'schedule_pending_payment_notice'], 10, 2);
    add_action('koopo_appt_pending_payment_notice', [__CLASS__, 'send_pending_payment_notice'], 10, 1);
    add_action('koopo_booking_expired_safe', [__CLASS__, 'notify_expired'], 10, 2);
    add_action('koopo_booking_review_invite', [__CLASS__, 'email_review_invite'], 10, 2);
    add_action('koopo_booking_review_invite', [__CLASS__, 'notify_review_invite'], 10, 2);

    if (function_exists('bp_notifications_add_notification')) {
      add_filter('bp_notifications_get_notifications_for_user', [__CLASS__, 'format_buddyboss_notifications'], 10, 9);
      add_filter('bb_notifications_get_notifications_for_user', [__CLASS__, 'format_buddyboss_notifications'], 10, 9);
      add_filter('bp_notifications_get_registered_components', [__CLASS__, 'register_buddyboss_component']);
      add_filter('bb_notifications_get_registered_components', [__CLASS__, 'register_buddyboss_component']);
    }
  }

  private static function admin_email(): string {
    return (string) apply_filters('koopo_appt_admin_email', get_option('admin_email'));
  }

  private static function listing_owner_email(int $listing_id, int $payee_user_id = 0): string {
    $owner_id = $payee_user_id ?: (int) get_post_field('post_author', $listing_id);
    $u = $owner_id ? get_user_by('id', $owner_id) : null;
    return ($u && !empty($u->user_email)) ? $u->user_email : self::admin_email();
  }

  private static function customer_email(int $customer_id, ?int $order_id = null, $booking = null): string {
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order && $order->get_billing_email()) return $order->get_billing_email();
    }
    $u = $customer_id ? get_user_by('id', $customer_id) : null;
    if ($u && !empty($u->user_email)) return $u->user_email;
    return (string) Bookings::extra_from_record($booking, 'customer_email', '');
  }

  private static function booking_context(int $booking_id): array {
    $b = Bookings::get_booking($booking_id);
    if (!$b) return [];

    $listing_id = (int) $b->listing_id;
    $provider_id = (int) ($b->provider_id ?? 0);
    $subject_id = $listing_id ?: $provider_id;
    $service_id = (int) $b->service_id;
    $tz = !empty($b->timezone) ? (string) $b->timezone : '';

    // Format dates for email display
    $start_formatted = Date_Formatter::format((string)$b->start_datetime, $tz, 'full');
    $duration_mins = (strtotime((string)$b->end_datetime) - strtotime((string)$b->start_datetime)) / 60;
    $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

    return [
      'booking' => $b,
      'listing_id' => $listing_id,
      'provider_id' => $provider_id,
      'subject_id' => $subject_id,
      'listing_title' => $subject_id ? get_the_title($subject_id) : '',
      'payee_user_id' => (int) ($b->payee_user_id ?: $b->listing_author_id),
      'service_title' => $service_id ? get_the_title($service_id) : '',
      'start' => (string) $b->start_datetime,
      'end' => (string) $b->end_datetime,
      'start_formatted' => $start_formatted,
      'duration_formatted' => $duration_formatted,
      'timezone' => $tz,
      'timezone_abbr' => Date_Formatter::get_timezone_abbr($tz, (string)$b->start_datetime),
      'order_id' => (int) ($b->wc_order_id ?? 0),
      'calendar_links' => Date_Formatter::get_calendar_links($b),
    ];
  }

    public static function email_conflict(int $booking_id, int $conflict_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id'], $ctx['payee_user_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);

    $subject = sprintf('Koopo Booking conflict – #%d', $booking_id);
    $external = $conflict_id < 0;

    $body_admin = self::render_email([
      'title' => 'Booking Conflict Detected',
      'lines' => [
        $external ? "Booking #{$booking_id} could not be confirmed because the provider's connected calendar is unavailable at that time." : "Booking #{$booking_id} could not be confirmed because it conflicts with booking #{$conflict_id}.",
        "Listing: {$ctx['listing_title']} (ID {$ctx['listing_id']})",
        "Service: {$ctx['service_title']} (ID " . (int)$ctx['booking']->service_id . ")",
        "Time: {$ctx['start']} → {$ctx['end']}",
        "Action needed: refund or reschedule.",
      ],
    ]);

    $body_seller = self::render_email([
      'title' => 'Booking Conflict on Your Listing',
      'lines' => [
        $external ? "A customer’s payment completed, but a read-only busy block from your connected calendar now covers the selected time." : "A customer’s payment completed, but the selected slot became unavailable.",
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

    self::send_mail($booking_id, 'conflict', 'admin', $admin, $subject, $body_admin);
    self::send_mail($booking_id, 'conflict', 'provider', $seller, $subject, $body_seller);

    if ($customer) {
      self::send_mail($booking_id, 'conflict', 'customer', $customer, $subject, $body_customer);
    }
  }


  public static function email_confirmed(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $admin = self::admin_email();
    $seller = self::listing_owner_email($ctx['listing_id'], $ctx['payee_user_id']);
    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    $customer_name  = get_userdata((int)$ctx['booking']->customer_id)?->first_name;
    if (!$customer_name) {
      $customer_name = (string) Bookings::extra_from_record($ctx['booking'], 'customer_name', 'there');
    }
    $listing_url = $ctx['subject_id'] ? get_permalink($ctx['subject_id']) : home_url('/');
    $calendar_url = $ctx['calendar_links']['google'] ?? $ctx['calendar_links']['outlook'] ?? $ctx['calendar_links']['ical'] ?? '';
    $manage_url = class_exists('\Koopo_Appointments\MyAccount')
      ? MyAccount::manage_appointment_url($booking_id)
      : home_url('/my-account/koopo-appointments/');
    $seller_user = get_user_by('email', $seller);
    $seller_name = ($seller_user && !empty($seller_user->display_name)) ? $seller_user->display_name : 'there';
   
    $subject = sprintf('Koopo Booking confirmed – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'You’re all set! Your appointment is confirmed.',
      'intro' => "Hi {$customer_name}",
      'lines' => [
        '🎉 <strong>Your appointment is officially confirmed.</strong>',
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$ctx['duration_formatted']}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",
      ],
      'buttons' => [
        $calendar_url ? ['label' => 'Add to Calendar', 'url' => $calendar_url, 'style' => 'primary'] : null,
        $manage_url ? ['label' => 'Manage Appointment', 'url' => $manage_url, 'style' => 'secondary'] : null,
      ],
      'outro' => [
        'Please arrive a few minutes early to get settled.',
        'If you need to reschedule or cancel, you can manage your booking through your Koopo account.',
        $listing_url ? 'View this business: <a href="' . esc_url($listing_url) . '">' . esc_html($listing_url) . '</a>' : '',
        'Thanks for supporting local with Koopo 💛',
      ],
    ]);

    $body_seller = self::render_email_html([
      'title' => 'New confirmed appointment',
      'intro' => "Hi {$seller_name},",
      'lines' => [
        'You have a new confirmed appointment.',
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$ctx['duration_formatted']}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",],
      'outro' => [
        'Please make sure everything is ready before the appointment time.',
        'Thanks for being part of Koopo 💛',
      ],
    ]);

    $body_admin = self::render_email_html([
      'title' => 'New confirmed appointment',
      'intro' => 'An appointment has been confirmed.',
      'lines' => [
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$ctx['duration_formatted']}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",
        $ctx['order_id'] ? "🧾 <strong>Order ID:</strong> #{$ctx['order_id']}" : '',
      ],
    ]);

    if ($customer) self::send_mail($booking_id, 'confirmed', 'customer', $customer, $subject, $body_customer);
    self::send_mail($booking_id, 'confirmed', 'provider', $seller, $subject, $body_seller);
    self::send_mail($booking_id, 'confirmed', 'admin', $admin, $subject, $body_admin);
  }

  public static function email_cancelled(int $booking_id, $booking_obj) {
    if (is_string($booking_obj) && $booking_obj === 'refunded') {
      return;
    }

    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    $customer_name  = get_userdata((int)$ctx['booking']->customer_id)?->first_name;
    if (!$customer_name) {
      $customer_name = (string) Bookings::extra_from_record($ctx['booking'], 'customer_name', 'there');
    }
    $seller = self::listing_owner_email($ctx['listing_id'], $ctx['payee_user_id']);
    $cancelled_by = (string) Bookings::extra_from_record($ctx['booking'], 'cancelled_by', '');
    $cancel_reason = (string) Bookings::extra_from_record($ctx['booking'], 'cancel_reason', '');
    $listing_url = $ctx['subject_id'] ? get_permalink($ctx['subject_id']) : home_url('/');
    $business = $ctx['listing_title'] ?: 'the business';
    $seller_user = get_user_by('email', $seller);
    $seller_name = ($seller_user && !empty($seller_user->display_name)) ? $seller_user->display_name : 'there';
    $customer_fields = self::booking_customer_fields($booking_id, (int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null);

    $who_customer = $cancelled_by === 'customer'
      ? 'You cancelled your appointment.'
      : ($cancelled_by === 'vendor'
        ? "{$business} cancelled your appointment."
        : 'This appointment was cancelled.');

    $who_vendor = $cancelled_by === 'customer'
      ? 'Your customer cancelled this appointment.'
      : ($cancelled_by === 'vendor'
        ? 'You cancelled this appointment.'
        : 'This appointment was cancelled.');

    $subject_customer = sprintf('Koopo Appointment cancelled – #%d', $booking_id);
    $subject_vendor = sprintf('Koopo Appointment cancelled – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Your appointment was cancelled',
      'intro' => [
        "Hi {$customer_name},",
        $who_customer,
      ],
      'lines' => [
        "📍 <strong>Business:</strong> {$business}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$ctx['duration_formatted']}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",],
      'outro' => [
        'If a charge was captured, a refund may be processed depending on the payment method.',
        $listing_url ? 'View this business: <a href="' . esc_url($listing_url) . '">' . esc_html($listing_url) . '</a>' : '',
        'Thanks for supporting local with Koopo 💛',
      ],
    ]);

    $avatar_html = $customer_fields['avatar']
      ? '<img src="' . esc_url($customer_fields['avatar']) . '" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;display:inline-block;margin-right:10px;vertical-align:middle;">'
      : '';
    $customer_line = $avatar_html . '<strong>' . esc_html($customer_fields['name']) . '</strong>';
    $email_line = $customer_fields['email'] ? '✉️ <strong>Email:</strong> ' . esc_html($customer_fields['email']) : '';
    $phone_line = $customer_fields['phone'] ? '📞 <strong>Phone:</strong> ' . esc_html($customer_fields['phone']) : '';
    $note_line = $cancel_reason ? '📝 <strong>Customer note:</strong> ' . esc_html($cancel_reason) : '';

    $body_seller = self::render_email_html([
      'title' => 'Appointment cancelled',
      'intro' => [  
        "Hi {$seller_name},",
        $who_vendor,
      ],
      'lines' => [
        
        "📍 <strong>Business:</strong> {$business}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$ctx['duration_formatted']}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",
        '<strong>Customer:</strong> ' . $customer_line,
        $email_line,
        $phone_line,
        $note_line,],
      'outro' => [
        'If payment was captured, review the order for refund status.',
        'Thanks for being part of Koopo 💛',
      ],
    ]);

    if ($customer) self::send_mail($booking_id, 'cancelled', 'customer', $customer, $subject_customer, $body_customer);
    if ($seller) self::send_mail($booking_id, 'cancelled', 'provider', $seller, $subject_vendor, $body_seller);
  }

  public static function email_refunded(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    $seller = self::listing_owner_email($ctx['listing_id'], $ctx['payee_user_id']);
    $refund_amount = (float) Bookings::extra_from_record($ctx['booking'], 'refund_amount', 0.0);

    if ($refund_amount <= 0 && !empty($ctx['booking']->price)) {
      $refund_amount = (float) $ctx['booking']->price;
    }

    $currency = !empty($ctx['booking']->currency) ? (string) $ctx['booking']->currency : get_woocommerce_currency();
    $refund_display = wp_strip_all_tags(wc_price($refund_amount, ['currency' => $currency]));

    $subject = sprintf('Koopo Appointment refunded – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Your refund has been processed',
      'lines' => [
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>Date:</strong> {$ctx['start_formatted']} ({$ctx['timezone_abbr']})",
        "💳 <strong>Refund Amount:</strong> {$refund_display}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",
      ],
    ]);

    $body_seller = self::render_email_html([
      'title' => 'Appointment refunded',
      'lines' => [
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "💳 <strong>Refund Amount:</strong> {$refund_display}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",
      ],
    ]);

    if ($customer) self::send_mail($booking_id, 'refunded', 'customer', $customer, $subject, $body_customer);
    if ($seller) self::send_mail($booking_id, 'refunded', 'provider', $seller, $subject, $body_seller);
  }

  public static function email_rescheduled(int $booking_id, string $new_start, string $new_end, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    $customer_name  = get_userdata((int)$ctx['booking']->customer_id)?->display_name;
    if (!$customer_name) {
      $customer_name = (string) Bookings::extra_from_record($ctx['booking'], 'customer_name', 'there');
    }
    $tz = $ctx['timezone'];
    $listing_url = $ctx['listing_id'] ? get_permalink($ctx['listing_id']) : home_url('/');
    $calendar_url = $ctx['calendar_links']['google'] ?? $ctx['calendar_links']['outlook'] ?? $ctx['calendar_links']['ical'] ?? '';
    $manage_url = class_exists('\Koopo_Appointments\MyAccount')
      ? MyAccount::manage_appointment_url($booking_id)
      : home_url('/my-account/koopo-appointments/');
    
    $new_start_formatted = Date_Formatter::format($new_start, $tz, 'full');
    $duration_mins = (strtotime($new_end) - strtotime($new_start)) / 60;
    $duration_formatted = Date_Formatter::format_duration((int)$duration_mins);

    $subject = sprintf('Koopo Online Booking rescheduled – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Your appointment has been rescheduled',
      'intro' => [
        "Hi {$customer_name},",
        'Your appointment time has been updated and confirmed.',
      ],
      'lines' => [
        "📍 <strong>Business:</strong> {$ctx['listing_title']}",
        "🛎 <strong>Service:</strong> {$ctx['service_title']}",
        "🗓 <strong>New Date:</strong> {$new_start_formatted} ({$ctx['timezone_abbr']})",
        "⏱ <strong>Duration:</strong> {$duration_formatted}",
        "📌 <strong>Booking ID:</strong> #{$booking_id}",],
      'buttons' => [
        $calendar_url ? ['label' => 'Add to Calendar', 'url' => $calendar_url, 'style' => 'primary'] : null,
        $manage_url ? ['label' => 'Manage Appointment', 'url' => $manage_url, 'style' => 'secondary'] : null,
      ],
      'outro' => [
        'If you have any questions, please contact the business.',
        $listing_url ? 'View this business: <a href="' . esc_url($listing_url) . '">' . esc_html($listing_url) . '</a>' : '',
        'Thanks for supporting local with Koopo 💛',
      ],
    ]);

    $event_name = 'rescheduled_' . substr(hash('sha256', $new_start . '|' . $new_end), 0, 12);
    if ($customer) self::send_mail($booking_id, $event_name, 'customer', $customer, $subject, $body_customer);
  }

  public static function email_expired(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    if (!$customer) return;

    $minutes = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    $subject = sprintf('Koopo Booking hold expired – #%d', $booking_id);

    $listing_url = $ctx['listing_id'] ? get_permalink($ctx['listing_id']) : '';
    $body_customer = self::render_email_html([
      'title' => 'Your booking expired',
      'lines' => [
        "We could not verify a completed payment before the {$minutes}-minute booking hold expired.",
        'If you were charged, do not book or pay again. Contact Koopo support with this booking number so we can review the payment.',
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']} → {$ctx['end']}",
        $listing_url ? 'If you were not charged, choose a new time: <a href="' . esc_url($listing_url) . '">View listing</a>' : 'If you were not charged, please choose a new time and try again.',
      ],
    ]);

    self::send_mail($booking_id, 'expired', 'customer', $customer, $subject, $body_customer);
  }

  public static function email_pending_payment(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    if (!$customer) return;

    $minutes_total = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
    if ($minutes_total < 1) {
      $minutes_total = 10;
    }
    $minutes_left = max(1, $minutes_total - 3);

    $pay_url = class_exists('\Koopo_Appointments\MyAccount')
      ? MyAccount::pay_now_url($booking_id)
      : '';

    $subject = sprintf('Koopo Payment required – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Complete your booking to confirm',
      'lines' => [
        "Your appointment is not confirmed yet.",
        "Please complete checkout within {$minutes_left} minutes to confirm your booking or it will be deleted.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        "Time requested: {$ctx['start']} → {$ctx['end']}",
        $pay_url ? 'Pay now: <a href="' . esc_url($pay_url) . '">Complete checkout</a>' : '',
      ],
    ]);

    self::send_mail($booking_id, 'pending_payment', 'customer', $customer, $subject, $body_customer);
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

  public static function schedule_pending_payment_notice(int $booking_id, $booking_obj): void {
    $delay = 3 * 60;
    if (wp_next_scheduled('koopo_appt_pending_payment_notice', [$booking_id])) {
      return;
    }
    wp_schedule_single_event(time() + $delay, 'koopo_appt_pending_payment_notice', [$booking_id]);
  }

  public static function send_pending_payment_notice(int $booking_id): void {
    $booking = Bookings::get_booking($booking_id);
    if (!$booking) return;
    if ((string) $booking->status !== 'pending_payment') return;
    if (!empty($booking->wc_order_id)) {
      $order = wc_get_order((int) $booking->wc_order_id);
      if ($order && Order_Hooks::payment_is_verified($order)) return;
    }
    self::email_pending_payment($booking_id, $booking);
    self::notify_pending_payment($booking_id, $booking);
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

  public static function email_review_invite(int $booking_id, $booking_obj) {
    $ctx = self::booking_context($booking_id);
    if (!$ctx) return;

    $customer = self::customer_email((int)$ctx['booking']->customer_id, $ctx['order_id'] ?: null, $ctx['booking']);
    if (!$customer) return;

    $listing_url = $ctx['listing_id'] ? get_permalink($ctx['listing_id']) : '';
    $review_url = $listing_url ? rtrim($listing_url, '/') . '/#reviews' : '';

    $subject = sprintf('Koopo How was your appointment? – #%d', $booking_id);

    $body_customer = self::render_email_html([
      'title' => 'Leave feedback for your appointment',
      'lines' => [
        "We hope your appointment went well.",
        "Business: {$ctx['listing_title']}",
        "Service: {$ctx['service_title']}",
        $review_url ? 'Leave a review: <a href="' . esc_url($review_url) . '">Write a review</a>' : 'Please leave a review on the business listing.',
      ],
    ]);

    self::send_mail($booking_id, 'review_invite', 'customer', $customer, $subject, $body_customer);
  }

  public static function notify_review_invite(int $booking_id, $booking_obj) {
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
      'component_action'  => 'review_invite',
      'date_notified'     => bp_core_current_time(),
      'is_new'            => 1,
    ]);
  }

  public static function format_buddyboss_notifications($content, $user_id, $format = 'string', $action = '', $component = '', $notification_id = 0, $item_id = 0, $secondary_item_id = 0, $total_items = 0) {
    if ($component !== 'koopo_appointments') return $content;

    $booking = ($item_id && $action !== 'waitlist_offer') ? Bookings::get_booking((int) $item_id) : null;
    $listing_id = (int) $secondary_item_id;
    if (!$listing_id && $booking) {
      $listing_id = (int) $booking->listing_id;
    }

    $link = '';
    $text = '';

    if ($action === 'waitlist_offer') {
      $link = class_exists(Waitlist::class) ? Waitlist::offer_url((int) $item_id) : home_url('/');
      $text = 'An appointment opening is available. Confirm it before the offer expires.';
    } elseif ($action === 'pending_payment') {
      $minutes_total = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
      if ($minutes_total < 1) {
        $minutes_total = 10;
      }
      $minutes_left = max(1, $minutes_total - 3);
      $pay_url = class_exists('\Koopo_Appointments\MyAccount') ? MyAccount::pay_now_url((int) $item_id) : '';
      $link = $pay_url ?: home_url('/');
      $text = sprintf(
        'Your booking is not confirmed. Pay now to confirm (expires in %d minutes or it will be deleted).',
        $minutes_left
      );
    } elseif ($action === 'expired') {
      $listing_link = $listing_id ? get_permalink($listing_id) : home_url('/');
      $link = $listing_link ?: home_url('/');
      $text = 'Payment was not verified before your booking hold expired. If you were charged, do not pay again; contact support.';
    } elseif ($action === 'review_invite') {
      $listing_link = $listing_id ? get_permalink($listing_id) : home_url('/');
      $review_link = $listing_link ? rtrim($listing_link, '/') . '/#reviews' : home_url('/');
      $link = $review_link ?: home_url('/');
      $text = 'Your appointment has passed. Leave feedback for your recent booking.';
    } elseif (str_starts_with((string)$action, 'appointment_')) {
      $appointment_action = substr((string)$action, strlen('appointment_'));
      $link = class_exists(MyAccount::class) ? MyAccount::manage_appointment_url((int)$item_id) : home_url('/');
      $labels = [
        'checkout_required'=>'Your appointment is awaiting payment. Open it to continue checkout.',
        'confirmed'=>'Your appointment is confirmed.',
        'cancelled'=>'Your appointment was cancelled.',
        'refunded'=>'A refund update is available for your appointment.',
        'rescheduled'=>'Your appointment was rescheduled.',
        'review_invite'=>'Your appointment is ready for a review.',
        'reminder'=>'You have an upcoming appointment.',
        'claimed'=>'This appointment was added to your Koopo account.',
      ];
      $text = $labels[$appointment_action] ?? 'Your appointment has an update.';
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

  private static function send_mail(int $booking_id, string $event_name, string $recipient_role, string $to, string $subject, string $body): void {
    if (!$to) return;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    Notification_Delivery::send_email($booking_id, $event_name, $recipient_role, $to, $subject, $body, $headers);
  }

  private static function email_logo_url(): string {
    return Admin_Settings::get_active_email_logo_url();
  }

  private static function booking_customer_fields(int $booking_id, int $customer_id, ?int $order_id = null): array {
    $booking = Bookings::get_booking($booking_id);
    $name = (string) Bookings::extra_from_record($booking, 'customer_name', '');
    $email = (string) Bookings::extra_from_record($booking, 'customer_email', '');
    $phone = (string) Bookings::extra_from_record($booking, 'customer_phone', '');
    $avatar = '';

    if ($customer_id) {
      $user = get_user_by('id', $customer_id);
      if ($user) {
        if (!$name && !empty($user->display_name)) $name = (string) $user->display_name;
        if (!$email && !empty($user->user_email)) $email = (string) $user->user_email;
        $avatar = get_avatar_url($user->ID, ['size' => 64]) ?: '';
      }
      if (!$phone) {
        $billing_phone = get_user_meta($customer_id, 'billing_phone', true);
        if ($billing_phone) $phone = (string) $billing_phone;
      }
    }

    if ($order_id && (!$email || !$phone || !$name)) {
      $order = wc_get_order($order_id);
      if ($order) {
        if (!$email && $order->get_billing_email()) $email = (string) $order->get_billing_email();
        if (!$phone && $order->get_billing_phone()) $phone = (string) $order->get_billing_phone();
        if (!$name && $order->get_formatted_billing_full_name()) $name = (string) $order->get_formatted_billing_full_name();
      }
    }

    if (!$avatar && $email) {
      $avatar = get_avatar_url($email, ['size' => 64]) ?: '';
    }

    return [
      'name' => $name ?: 'Customer',
      'email' => $email,
      'phone' => $phone,
      'avatar' => $avatar,
    ];
  }

  private static function render_email(array $data): string {
    $title = esc_html($data['title'] ?? 'Notification');
    $lines = $data['lines'] ?? [];
    $lis = '';
    foreach ($lines as $l) $lis .= '<li>' . esc_html($l) . '</li>';
    $logo = self::email_logo_url();
    $logo_html = $logo ? "<div style='text-align:center;margin-bottom:12px;'><img src='{$logo}' alt='Koopo' style='max-width:290px;height:auto;'></div>" : '';
    return "
      <div style='font-family:Arial,sans-serif;line-height:1.6;max-width:600px;margin:0 auto;'>
        <div style='background:#000;padding:20px;border-radius:8px 8px 0 0;'>
          {$logo_html}
          <h2 style='margin:0;color:#fff;'>{$title}</h2>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #e5e5e5;'>
          <ul style='padding-left:20px;'>{$lis}</ul>
        </div>
        <div style='background:#f7f7f7;padding:15px;text-align:center;font-size:12px;color:#666;border-radius:0 0 8px 8px;'>
          <p style='margin:0;'>© 2026 Koopo Online.  All Rights Reserved.</p>
          <div style='margin-top:8px;display:flex;justify-content:center;align-items:center;flex-wrap:nowrap;margin:0 auto;'>
            <a href='https://koopoonline.com/privacy-policy-koopo/' style='color:#666;text-decoration:none;margin:0 8px;font-size:12px;'>Privacy Policy</a> |
            <a href='https://koopoonline.com/koopo-terms/' style='color:#666;text-decoration:none;margin:0 8px;font-size:12px;'>Terms of Service</a>
        </div>
      </div>
    ";
  }

  private static function render_email_html(array $data): string {
    $title = esc_html($data['title'] ?? 'Notification');
    $lines = $data['lines'] ?? [];
    $intro = $data['intro'] ?? '';
    $outro = $data['outro'] ?? '';
    $buttons = $data['buttons'] ?? [];
    $lis = '';
    $intro_lines = '';
    $outro_lines = '';
    $buttons_html = '';

    foreach ($lines as $l) {
      if (!$l) continue;
      $lis .= '<li>' . wp_kses_post($l) . '</li>';
    }
    foreach ((array) $outro as $o) {
      if (!$o) continue;
      $outro_lines .= '<p>' . wp_kses_post($o) . '</p>';
    }
    foreach ((array) $intro as $i) {
      if (!$i) continue;
      $intro_lines .= '<p>' . wp_kses_post($i) . '</p>';
    }
    if (!is_array($buttons)) {
      $buttons = [];
    }
    foreach ($buttons as $btn) {
      if (!$btn || !is_array($btn)) continue;
      $label = trim((string) ($btn['label'] ?? ''));
      $url = trim((string) ($btn['url'] ?? ''));
      if (!$label || !$url) continue;
      $style = (string) ($btn['style'] ?? 'primary');
      $bg = $style === 'secondary' ? '#fff' : '#f4b400';
      $color = $style === 'secondary' ? '#3d2e00' : '#111';
      $border = $style === 'secondary' ? '1px solid #d7c07a' : '1px solid #f4b400';
      $buttons_html .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:600;margin:4px;background:' . $bg . ';color:' . $color . ';border:' . $border . ';">' . esc_html($label) . '</a>';
    }
    $logo = self::email_logo_url();
    $logo_html = $logo ? "<div style='text-align:center;margin-bottom:12px;'><img src='{$logo}' alt='Koopo' style='max-width:290px;height:auto;'></div>" : '';
    return "
      <div style='font-family:Arial,sans-serif;line-height:1.6;max-width:600px;margin:0 auto;'>
        <div style='background:#000;padding:20px;border-radius:8px 8px 0 0;'>
          {$logo_html}
          <h2 style='margin:0;color:#fff;'>{$title}</h2>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #e5e5e5;'>
          {$intro_lines}
          <ul style='padding-left:20px;'>{$lis}</ul>
          <div style='margin:16px 0;text-align:center;'>{$buttons_html}</div>
          <div>{$outro_lines}</div>
        </div>
        <div style='background:#f7f7f7;padding:15px;text-align:center;font-size:12px;color:#666;border-radius:0 0 8px 8px;'>
<p style='margin:0;'>—© 2026 Koopo Online.  All Rights Reserved.</p>
          <div style='margin-top:8px;display:flex;justify-content:center;align-items:center;flex-wrap:nowrap;'>
            <a href='https://koopoonline.com/privacy-policy-koopo/' style='color:#666;text-decoration:none;margin:0 8px;font-size:12px;'>Privacy Policy</a> |
            <a href='https://koopoonline.com/koopo-terms/' style='color:#666;text-decoration:none;margin:0 8px;font-size:12px;'>Terms of Service</a>
          </div>
        </div>
      </div>
    ";
  }
}
