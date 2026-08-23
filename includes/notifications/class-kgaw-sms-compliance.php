<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Immutable invitation consent copy and privacy-safe phone suppression. */
final class SMS_Compliance {
  const CONSENT_METHOD = 'voice';
  const DISCLOSURE_VERSION = 'guest-invite-voice-v1';
  const DISCLOSURE = 'Do you agree to receive a transactional SMS from Koopo containing one appointment invitation? Message frequency may vary. Standard message and data rates may apply. Reply STOP to opt out or HELP for assistance. Your mobile information will not be sold or shared for promotional or marketing purposes.';
  const CONFIRMATION = 'I confirm that I read the disclosure above and the customer expressly agreed to receive this transactional appointment invitation by SMS.';
  const STOP_KEYWORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'];
  const HELP_KEYWORDS = ['help', 'info'];
  const START_KEYWORDS = ['start', 'yes'];

  public static function validate_evidence(bool $confirmed, string $method, string $version) {
    if (!$confirmed || sanitize_key($method) !== self::CONSENT_METHOD || sanitize_key($version) !== self::DISCLOSURE_VERSION) {
      return new \WP_Error('sms_voice_consent_required', __('Read the SMS disclosure and record the customer\'s express verbal agreement before sending a text invitation.', 'koopo-appointments'));
    }
    return true;
  }

  public static function phone_hash(string $phone): string {
    $phone = Booking_Invitations::normalize_phone($phone);
    return $phone === '' ? '' : hash_hmac('sha256', $phone, wp_salt('auth'));
  }

  public static function mask_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', Booking_Invitations::normalize_phone($phone));
    return $digits === '' ? '' : '***-***-' . substr($digits, -4);
  }

  public static function is_suppressed(string $phone): bool {
    global $wpdb;
    $hash = self::phone_hash($phone);
    if ($hash === '') return true;
    return (bool) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . DB::sms_suppressions_table() . " WHERE phone_hash=%s AND status='suppressed' LIMIT 1",
      $hash
    ));
  }

  public static function record_reply(string $phone, string $reply, string $source, string $event_at = ''): string {
    $keyword = strtolower(trim((string) preg_replace('/[^a-z]/i', '', $reply)));
    if (in_array($keyword, self::STOP_KEYWORDS, true)) {
      self::suppress($phone, 'customer_opt_out', $source, $keyword, $event_at);
      return 'stop';
    }
    if (in_array($keyword, self::HELP_KEYWORDS, true)) {
      self::upsert_event($phone, $source, $keyword, 'help_requested_at', $event_at);
      return 'help';
    }
    if (in_array($keyword, self::START_KEYWORDS, true)) {
      // START is evidence of an inbound reply, but does not silently erase Koopo's
      // permanent suppression record. Re-enrollment requires a new scoped consent flow.
      self::upsert_event($phone, $source, $keyword, 'start_received_at', $event_at);
      return 'start';
    }
    return '';
  }

  public static function suppress(string $phone, string $reason, string $source, string $keyword = 'stop', string $event_at = ''): bool {
    global $wpdb;
    $normalized = Booking_Invitations::normalize_phone($phone);
    $saved = self::upsert_event($normalized, $source, $keyword, 'suppressed_at', $event_at, sanitize_key($reason));
    if ($saved && $normalized !== '') {
      $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event_at) ? $event_at : current_time('mysql', true);
      $wpdb->query($wpdb->prepare(
        'UPDATE ' . DB::booking_invites_table() . " SET sms_consent_status='revoked',sms_consent_revoked_at=%s,sms_consent_revocation_source=%s,updated_at=%s WHERE sms_consent_phone=%s AND sms_consent_status IN ('active','consumed')",
        $at, sanitize_key($source), current_time('mysql', true), $normalized
      ));
    }
    return $saved;
  }

  private static function upsert_event(string $phone, string $source, string $keyword, string $date_field, string $event_at, string $reason = ''): bool {
    global $wpdb;
    $normalized = Booking_Invitations::normalize_phone($phone);
    $hash = self::phone_hash($normalized);
    if ($hash === '') return false;
    $allowed = ['suppressed_at', 'help_requested_at', 'start_received_at'];
    if (!in_array($date_field, $allowed, true)) return false;
    $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event_at) ? $event_at : current_time('mysql', true);
    $now = current_time('mysql', true);
    $status = $date_field === 'suppressed_at' ? 'suppressed' : 'observed';
    $table = DB::sms_suppressions_table();
    $sql = "INSERT INTO {$table} (phone_hash,phone_last4,status,reason,source,last_keyword,{$date_field},last_event_at,created_at,updated_at)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
      ON DUPLICATE KEY UPDATE
        status=IF(status='suppressed' OR VALUES(status)='suppressed','suppressed',status),
        reason=IF(VALUES(reason)='',reason,VALUES(reason)), source=VALUES(source), last_keyword=VALUES(last_keyword),
        {$date_field}=VALUES({$date_field}), last_event_at=VALUES(last_event_at), updated_at=VALUES(updated_at)";
    return false !== $wpdb->query($wpdb->prepare($sql,
      $hash, substr(preg_replace('/\D+/', '', $normalized), -4), $status, sanitize_key($reason), sanitize_key($source), sanitize_key($keyword), $at, $at, $now, $now
    ));
  }
}
