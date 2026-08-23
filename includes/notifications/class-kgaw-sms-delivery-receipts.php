<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Privacy-safe provider receipt lifecycle for accepted SMS requests. */
final class SMS_Delivery_Receipts {
  const OPTION_WEBHOOK_TOKEN = 'koopo_appt_sms_webhook_token';
  const ROUTE = '/appointments/sms/brevo/(?P<token>[A-Za-z0-9_-]{43})';
  const FAILED_STATUSES = ['skipped', 'rejected', 'blocked', 'blacklisted', 'invalid', 'soft_bounce', 'hard_bounce'];

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', self::ROUTE, [
      'methods'=>\WP_REST_Server::CREATABLE,
      'callback'=>[__CLASS__, 'receive_brevo'],
      'permission_callback'=>[__CLASS__, 'authorize'],
    ]);
  }

  public static function authorize(\WP_REST_Request $request) {
    $provided = (string) $request['token'];
    $expected = self::token(false);
    return $expected !== '' && strlen($provided) === strlen($expected) && hash_equals($expected, $provided)
      ? true
      : new \WP_Error('sms_webhook_forbidden', __('Invalid SMS webhook credential.', 'koopo-appointments'), ['status'=>403]);
  }

  public static function webhook_url(): string {
    $token = self::token(true);
    return $token === '' ? '' : rest_url('koopo/v1/appointments/sms/brevo/' . rawurlencode($token));
  }

  public static function token(bool $create): string {
    $stored = (string) get_option(self::OPTION_WEBHOOK_TOKEN, '');
    if ($stored !== '') {
      try {
        $decoded = Calendar_Crypto::decrypt($stored);
        $token = (string) ($decoded['secret'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return $token;
      } catch (\Throwable $error) {}
    }
    if (!$create) return '';
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    try {
      update_option(self::OPTION_WEBHOOK_TOKEN, Calendar_Crypto::encrypt(['secret'=>$token]), false);
      return $token;
    } catch (\Throwable $error) {
      return '';
    }
  }

  public static function accepted(string $provider, string $message_id, array $context): void {
    global $wpdb;
    $provider = sanitize_key($provider);
    $message_id = sanitize_text_field($message_id);
    if ($provider === '' || $message_id === '') return;
    $table = DB::sms_receipts_table();
    $now = current_time('mysql', true);
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$table} (provider,provider_message_id,delivery_id,booking_id,invitation_id,message_type,status,accepted_at,last_event_at,created_at,updated_at)
       VALUES (%s,%s,NULLIF(%d,0),NULLIF(%d,0),NULLIF(%d,0),%s,'accepted',%s,%s,%s,%s)
       ON DUPLICATE KEY UPDATE delivery_id=COALESCE(VALUES(delivery_id),delivery_id),booking_id=COALESCE(VALUES(booking_id),booking_id),invitation_id=COALESCE(VALUES(invitation_id),invitation_id),message_type=IF(VALUES(message_type)='',message_type,VALUES(message_type)),accepted_at=COALESCE(accepted_at,VALUES(accepted_at)),updated_at=VALUES(updated_at)",
      $provider, $message_id, absint($context['delivery_id'] ?? 0), absint($context['booking_id'] ?? 0), absint($context['invitation_id'] ?? 0), sanitize_key((string) ($context['type'] ?? '')), $now, $now, $now, $now
    ));
    $delivery_id = absint($context['delivery_id'] ?? 0);
    if ($delivery_id > 0) {
      $receipt = $wpdb->get_row($wpdb->prepare("SELECT status,last_reason_code FROM {$table} WHERE provider=%s AND provider_message_id=%s", $provider, $message_id));
      if ($receipt) Notification_Delivery::update_sms_status($delivery_id, (string) $receipt->status, (string) $receipt->last_reason_code);
    }
  }

  public static function receive_brevo(\WP_REST_Request $request): \WP_REST_Response {
    $payload = $request->get_json_params();
    $events = isset($payload[0]) && is_array($payload[0]) ? $payload : (isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [$payload]);
    $processed = 0;
    foreach (array_slice($events, 0, 100) as $event) {
      if (is_array($event) && self::apply_event($event)) $processed++;
    }
    return new \WP_REST_Response(['received'=>true, 'processed'=>$processed], 200);
  }

  public static function apply_event(array $event): bool {
    global $wpdb;
    $message_id = sanitize_text_field((string) ($event['messageId'] ?? $event['message_id'] ?? ''));
    $raw_status = (string) ($event['event'] ?? $event['msg_status'] ?? $event['description'] ?? '');
    $status = self::normalize_status($raw_status);
    if ($message_id === '' || $status === '') return false;
    $timestamp = absint($event['ts_event'] ?? $event['ts'] ?? 0);
    $event_at = $timestamp > 946684800 && $timestamp < time() + DAY_IN_SECONDS ? gmdate('Y-m-d H:i:s', $timestamp) : current_time('mysql', true);
    $reason_source = (string) ($event['error_code'] ?? $event['reason'] ?? $event['description'] ?? '');
    $reason = substr(sanitize_key($reason_source), 0, 100);
    $phone = Booking_Invitations::normalize_phone((string) ($event['to'] ?? $event['phone_number'] ?? $event['from'] ?? ''));
    $is_reply = in_array($status, ['unsubscribed', 'replied'], true);
    if ($is_reply && !self::phone_has_consent_history($phone)) return false;
    if ($status === 'unsubscribed') {
      SMS_Compliance::suppress($phone, 'customer_opt_out', 'brevo_unsubscribe', 'stop', $event_at);
    } elseif ($status === 'replied') {
      SMS_Compliance::record_reply($phone, (string) ($event['reply'] ?? ''), 'brevo_reply', $event_at);
    }
    $table = DB::sms_receipts_table();
    $now = current_time('mysql', true);
    $wpdb->query('START TRANSACTION');
    $receipt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider='brevo' AND provider_message_id=%s FOR UPDATE", $message_id));
    if (!$receipt) {
      $wpdb->query('ROLLBACK');
      return $is_reply;
    }
    $current = sanitize_key((string) $receipt->status);
    $current_at = strtotime((string) $receipt->last_event_at . ' UTC') ?: 0;
    $incoming_at = strtotime($event_at . ' UTC') ?: time();
    $current_terminal = $current === 'delivered' || in_array($current, self::FAILED_STATUSES, true);
    $incoming_terminal = $status === 'delivered' || in_array($status, self::FAILED_STATUSES, true);
    $apply = $incoming_at >= $current_at && (!$current_terminal || $incoming_terminal);
    if ($status === 'delivered' && $current !== 'delivered') $apply = true;
    if ($apply) {
      $data = ['status'=>$status, 'last_reason_code'=>$reason, 'last_event_at'=>$event_at, 'updated_at'=>$now];
      if (in_array($status, ['accepted','sent'], true) && empty($receipt->accepted_at)) $data['accepted_at'] = $event_at;
      if ($status === 'delivered') { $data['delivered_at'] = $event_at; $data['last_reason_code'] = ''; }
      if (in_array($status, self::FAILED_STATUSES, true)) $data['failed_at'] = $event_at;
      $wpdb->update($table, $data, ['id'=>(int) $receipt->id]);
    }
    $wpdb->query('COMMIT');
    if ($receipt && (int) $receipt->delivery_id > 0) Notification_Delivery::update_sms_status((int) $receipt->delivery_id, $status, $reason);
    return true;
  }

  private static function phone_has_consent_history(string $phone): bool {
    global $wpdb;
    if ($phone === '') return false;
    return (bool) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . DB::booking_invites_table() . " WHERE sms_consent_phone=%s AND sms_consent_status IN ('active','consumed','revoked') LIMIT 1",
      $phone
    ));
  }

  public static function summary(int $days = 30): array {
    global $wpdb;
    $days = max(1, min(365, $days));
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) total FROM ' . DB::sms_receipts_table() . ' WHERE created_at>=%s GROUP BY status', $since)) ?: [];
    $out = ['accepted'=>0, 'sent'=>0, 'delivered'=>0, 'failed'=>0];
    foreach ($rows as $row) {
      $status = sanitize_key((string) $row->status);
      if (array_key_exists($status, $out)) $out[$status] += (int) $row->total;
      elseif (in_array($status, self::FAILED_STATUSES, true)) $out['failed'] += (int) $row->total;
    }
    return $out;
  }

  private static function normalize_status(string $status): string {
    $status = strtolower(trim($status));
    $status = str_replace(['-', ' '], '_', $status);
    $map = ['request'=>'accepted','queued'=>'accepted','accept'=>'accepted','skip'=>'skipped','rej'=>'rejected','hardbounce'=>'hard_bounce','softbounce'=>'soft_bounce','reply'=>'replied','unsubscribe'=>'unsubscribed'];
    $status = $map[$status] ?? $status;
    return in_array($status, array_merge(['accepted','sent','delivered','replied','unsubscribed'], self::FAILED_STATUSES), true) ? $status : '';
  }
}
