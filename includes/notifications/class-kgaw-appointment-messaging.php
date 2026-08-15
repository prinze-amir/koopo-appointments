<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Sends registered customers appointment updates through the Koopo/BuddyBoss inbox. */
final class Appointment_Messaging {
  public static function init(): void {
    add_action('koopo_booking_pending_payment', static fn(int $id) => self::send($id, 'checkout_required'), 5, 1);
    add_action('koopo_booking_confirmed_safe', static fn(int $id) => self::send($id, 'confirmed'), 15, 1);
    add_action('koopo_booking_cancelled_safe', static fn(int $id) => self::send($id, 'cancelled'), 15, 1);
    add_action('koopo_booking_refunded_safe', static fn(int $id) => self::send($id, 'refunded'), 15, 1);
    add_action('koopo_booking_rescheduled', static fn(int $id) => self::send($id, 'rescheduled'), 15, 1);
    add_action('koopo_booking_review_invite', static fn(int $id) => self::send($id, 'review_invite'), 15, 1);
  }

  public static function send_reminder(int $booking_id, int $hours_before): bool {
    return self::send($booking_id, 'reminder', ['hours_before' => $hours_before]);
  }

  public static function send(int $booking_id, string $action, array $context = []): bool {
    $booking = Bookings::get_booking($booking_id);
    if (!$booking || (int) ($booking->customer_id ?? 0) <= 0) return false;

    $recipient_id = (int) $booking->customer_id;
    $sender_id = (int) ($booking->payee_user_id ?? $booking->listing_author_id ?? 0);
    if (!$sender_id || $sender_id === $recipient_id) return false;

    $content = self::content($booking, $action, $context);
    $thread_id = (int) ($booking->inbox_thread_id ?? 0);
    $message_id = 0;
    if (function_exists('messages_new_message')) {
      // Appointment updates are transactional account messages. Bypass paid-tier
      // compose restrictions only for this tightly scoped send operation.
      $allow_transactional_message = static fn() => true;
      add_filter('bb_user_can_send_messages', $allow_transactional_message, PHP_INT_MAX, 3);
      try {
        $args = [
          'sender_id' => $sender_id,
          'content' => $content,
          'error_type' => 'wp_error',
          'mark_visible' => true,
          'return' => 'object',
        ];
        if ($thread_id > 0) $args['thread_id'] = $thread_id;
        else {
          $args['recipients'] = [$recipient_id];
          $args['subject'] = __('Appointment updates', 'koopo-appointments');
        }
        $sent = messages_new_message($args);
        if (!is_wp_error($sent) && is_object($sent)) {
          $message_id = absint($sent->id ?? 0);
          $thread_id = absint($sent->thread_id ?? $thread_id);
        }
      } finally {
        remove_filter('bb_user_can_send_messages', $allow_transactional_message, PHP_INT_MAX);
      }
    }

    if ($message_id && function_exists('bp_messages_update_meta')) {
      bp_messages_update_meta($message_id, '_koopo_linked_entity', wp_json_encode([
        'type' => 'appointment',
        'bookingId' => $booking_id,
        'action' => sanitize_key($action),
        'deepLink' => 'koopo://appointments/' . $booking_id,
      ]));
    }
    if ($thread_id && $thread_id !== (int) ($booking->inbox_thread_id ?? 0)) Bookings::set_inbox_thread_id($booking_id, $thread_id);

    if (function_exists('bp_notifications_add_notification')) {
      bp_notifications_add_notification([
        'user_id' => $recipient_id,
        'item_id' => $booking_id,
        'secondary_item_id' => (int) ($booking->listing_id ?: $booking->provider_id),
        'component_name' => 'koopo_appointments',
        'component_action' => 'appointment_' . sanitize_key($action),
        'date_notified' => function_exists('bp_core_current_time') ? bp_core_current_time() : current_time('mysql', true),
        'is_new' => 1,
      ]);
    }

    do_action('koopo_appt_internal_booking_message_sent', $booking_id, $action, $message_id, $thread_id);
    return $message_id > 0 || function_exists('bp_notifications_add_notification');
  }

  private static function content(object $booking, string $action, array $context): string {
    $subject_id = (int) ($booking->listing_id ?: $booking->provider_id);
    $business = $subject_id ? get_the_title($subject_id) : __('your provider', 'koopo-appointments');
    $service = get_the_title((int) $booking->service_id);
    $when = Date_Formatter::format((string) $booking->start_datetime, (string) $booking->timezone, 'full');
    $url = MyAccount::manage_appointment_url((int) $booking->id);
    $messages = [
      'checkout_required' => sprintf(__('Your %1$s appointment with %2$s on %3$s is awaiting payment.', 'koopo-appointments'), $service, $business, $when),
      'confirmed' => sprintf(__('Your %1$s appointment with %2$s on %3$s is confirmed.', 'koopo-appointments'), $service, $business, $when),
      'cancelled' => sprintf(__('Your %1$s appointment with %2$s on %3$s was cancelled.', 'koopo-appointments'), $service, $business, $when),
      'refunded' => sprintf(__('A refund update is available for your %1$s appointment with %2$s.', 'koopo-appointments'), $service, $business),
      'rescheduled' => sprintf(__('Your %1$s appointment with %2$s was rescheduled to %3$s.', 'koopo-appointments'), $service, $business, $when),
      'review_invite' => sprintf(__('How was your %1$s appointment with %2$s? You can now leave a review.', 'koopo-appointments'), $service, $business),
      'reminder' => sprintf(__('Reminder: your %1$s appointment with %2$s is on %3$s.', 'koopo-appointments'), $service, $business, $when),
      'claimed' => sprintf(__('Your %1$s appointment with %2$s has been added to your Koopo account.', 'koopo-appointments'), $service, $business),
    ];
    return ($messages[$action] ?? sprintf(__('Appointment update from %s.', 'koopo-appointments'), $business)) . "\n\n" . $url;
  }
}
