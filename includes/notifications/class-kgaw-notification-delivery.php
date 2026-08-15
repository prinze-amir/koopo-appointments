<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Idempotent, privacy-safe delivery records for appointment communications. */
final class Notification_Delivery {
  const MAX_ATTEMPTS = 3;
  const STALE_PROCESSING_SECONDS = 15 * MINUTE_IN_SECONDS;

  public static function send_email(int $booking_id, string $event_name, string $recipient_role, string $to, string $subject, string $body, array $headers = [], int $invitation_id = 0): bool {
    $to = sanitize_email($to);
    if (!$to) return false;
    $delivery_id = self::claim([
      'booking_id' => $booking_id,
      'invitation_id' => $invitation_id,
      'event_name' => $event_name . ':' . sanitize_key($recipient_role),
      'channel' => 'email',
      'recipient' => strtolower($to),
    ]);
    if (is_wp_error($delivery_id)) return $delivery_id->get_error_code() === 'delivery_already_handled';

    $sent = wp_mail($to, $subject, $body, $headers);
    if ($sent) self::complete($delivery_id);
    else self::fail($delivery_id, 'wp_mail_rejected');
    return (bool) $sent;
  }

  public static function claim(array $data) {
    global $wpdb;
    $event_name = sanitize_key((string) ($data['event_name'] ?? ''));
    $channel = sanitize_key((string) ($data['channel'] ?? ''));
    $booking_id = absint($data['booking_id'] ?? 0);
    $invitation_id = absint($data['invitation_id'] ?? 0);
    $recipient_user_id = absint($data['recipient_user_id'] ?? 0);
    $recipient = strtolower(trim((string) ($data['recipient'] ?? '')));
    if (!$event_name || !in_array($channel, ['email', 'inbox', 'push', 'sms'], true)) {
      return new \WP_Error('invalid_delivery', __('The notification delivery request is invalid.', 'koopo-appointments'));
    }
    $event_key = self::event_key($booking_id, $invitation_id, $recipient_user_id, $event_name, $channel, $recipient);
    $recipient_hash = $recipient !== '' ? hash_hmac('sha256', $recipient, wp_salt('nonce')) : '';
    $table = DB::notification_deliveries_table();
    // A duplicate event key is the normal idempotency path, not an operational
    // database error. Keep that expected collision out of WordPress error logs.
    $previous_suppression = $wpdb->suppress_errors(true);
    $inserted = $wpdb->insert($table, [
      'booking_id' => $booking_id ?: null,
      'invitation_id' => $invitation_id ?: null,
      'recipient_user_id' => $recipient_user_id ?: null,
      'event_key' => $event_key,
      'event_name' => $event_name,
      'channel' => $channel,
      'recipient_hash' => $recipient_hash,
      'status' => 'processing',
      'attempt_count' => 1,
      'created_at' => current_time('mysql', true),
      'updated_at' => current_time('mysql', true),
    ]);
    $wpdb->suppress_errors($previous_suppression);
    if ($inserted) return (int) $wpdb->insert_id;

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_key=%s", $event_key));
    if (!$row) return new \WP_Error('delivery_claim_failed', __('The notification could not be reserved.', 'koopo-appointments'));
    if (in_array((string) $row->status, ['sent', 'suppressed'], true)) {
      return new \WP_Error('delivery_already_handled', __('This notification was already handled.', 'koopo-appointments'));
    }
    $stale_before = gmdate('Y-m-d H:i:s', time() - self::STALE_PROCESSING_SECONDS);
    $retryable = (string) $row->status === 'failed'
      || ((string) $row->status === 'processing' && (string) $row->updated_at <= $stale_before);
    if (!$retryable || (int) $row->attempt_count >= self::MAX_ATTEMPTS) {
      return new \WP_Error('delivery_in_progress', __('This notification is already being processed.', 'koopo-appointments'));
    }
    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE {$table} SET status='processing',attempt_count=attempt_count+1,last_error_code='',updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s AND attempt_count=%d AND updated_at=%s AND attempt_count<%d",
      (int) $row->id,
      (string) $row->status,
      (int) $row->attempt_count,
      (string) $row->updated_at,
      self::MAX_ATTEMPTS
    ));
    return $updated === 1
      ? (int) $row->id
      : new \WP_Error('delivery_in_progress', __('This notification is already being processed.', 'koopo-appointments'));
  }

  public static function was_handled(array $data): bool {
    global $wpdb;
    $event_key = self::event_key(
      absint($data['booking_id'] ?? 0),
      absint($data['invitation_id'] ?? 0),
      absint($data['recipient_user_id'] ?? 0),
      sanitize_key((string) ($data['event_name'] ?? '')),
      sanitize_key((string) ($data['channel'] ?? '')),
      strtolower(trim((string) ($data['recipient'] ?? '')))
    );
    return (bool) $wpdb->get_var($wpdb->prepare(
      'SELECT 1 FROM ' . DB::notification_deliveries_table() . " WHERE event_key=%s AND status IN ('sent','suppressed') LIMIT 1",
      $event_key
    ));
  }

  public static function complete(int $delivery_id, string $provider_message_id = ''): void {
    global $wpdb;
    $wpdb->update(DB::notification_deliveries_table(), [
      'status' => 'sent',
      'provider_message_id' => sanitize_text_field($provider_message_id),
      'last_error_code' => '',
      'sent_at' => current_time('mysql', true),
      'updated_at' => current_time('mysql', true),
    ], ['id' => $delivery_id, 'status' => 'processing']);
  }

  public static function fail(int $delivery_id, string $error_code): void {
    global $wpdb;
    $wpdb->update(DB::notification_deliveries_table(), [
      'status' => 'failed',
      'last_error_code' => sanitize_key($error_code) ?: 'delivery_failed',
      'updated_at' => current_time('mysql', true),
    ], ['id' => $delivery_id, 'status' => 'processing']);
  }

  public static function suppress(int $delivery_id, string $reason): void {
    global $wpdb;
    $wpdb->update(DB::notification_deliveries_table(), [
      'status' => 'suppressed',
      'last_error_code' => sanitize_key($reason),
      'updated_at' => current_time('mysql', true),
    ], ['id' => $delivery_id, 'status' => 'processing']);
  }

  private static function event_key(int $booking_id, int $invitation_id, int $recipient_user_id, string $event_name, string $channel, string $recipient): string {
    return hash_hmac('sha256', implode('|', [$booking_id, $invitation_id, $recipient_user_id, $event_name, $channel, $recipient]), wp_salt('auth'));
  }
}
