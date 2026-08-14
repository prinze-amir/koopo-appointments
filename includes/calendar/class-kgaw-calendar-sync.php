<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Calendar_Sync {
  const JOB_HOOK = 'koopo_appt_calendar_sync_booking';
  const RECONCILE_HOOK = 'koopo_appt_calendar_reconcile';

  public static function init(): void {
    add_action('koopo_booking_confirmed_safe', [__CLASS__, 'enqueue_booking'], 20, 1);
    add_action('koopo_booking_rescheduled', [__CLASS__, 'enqueue_booking'], 20, 1);
    add_action('koopo_booking_cancelled_safe', [__CLASS__, 'enqueue_booking'], 20, 1);
    add_action('koopo_booking_refunded_safe', [__CLASS__, 'enqueue_booking'], 20, 1);
    add_action('koopo_booking_expired_safe', [__CLASS__, 'enqueue_booking'], 20, 1);
    add_action(self::JOB_HOOK, [__CLASS__, 'sync_booking'], 10, 1);
    add_action(self::RECONCILE_HOOK, [__CLASS__, 'reconcile']);
    if (!wp_next_scheduled(self::RECONCILE_HOOK)) {
      wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::RECONCILE_HOOK);
    }
  }

  public static function enqueue_booking(int $booking_id): void {
    if ($booking_id <= 0) return;
    if (function_exists('as_enqueue_async_action')) {
      if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::JOB_HOOK, [$booking_id], 'koopo-appointments-calendar')) return;
      as_enqueue_async_action(self::JOB_HOOK, [$booking_id], 'koopo-appointments-calendar', true);
      return;
    }
    if (!wp_next_scheduled(self::JOB_HOOK, [$booking_id])) {
      wp_schedule_single_event(time() + 5, self::JOB_HOOK, [$booking_id]);
    }
  }

  public static function sync_booking(int $booking_id): void {
    $booking = Bookings::get_booking($booking_id);
    if (!$booking) return;
    $resource_id = Resources::booking_resource_id($booking);
    $bindings = $resource_id
      ? Calendar_Repository::list_active_bindings_for_resource($resource_id)
      : Calendar_Repository::list_active_bindings_for_listing((int) $booking->listing_id);
    foreach ($bindings as $binding) {
      if ((string) $binding->provider === 'ics') continue;
      self::sync_binding($booking, $binding);
    }
  }

  private static function sync_binding(object $booking, object $binding): void {
    $mapping = Calendar_Repository::get_event((int) $booking->id, (int) $binding->id);
    $uid = self::uid((int) $booking->id);
    $terminal = in_array((string) $booking->status, ['cancelled', 'refunded', 'expired', 'conflict'], true);
    try {
      $connection = Calendar_Repository::get_connection((int) $binding->connection_id, (int) $binding->user_id);
      if (!$connection || (string) $connection->status === 'disconnected') return;
      $provider = new Calendar_Provider((string) $binding->provider);
      if ($terminal) {
        if ($mapping && (string) $mapping->external_event_id !== '') {
          $provider->delete_event($connection, (string) $binding->external_calendar_id, (string) $mapping->external_event_id);
        }
        Calendar_Repository::save_event((int) $booking->id, (int) $binding->id, [
          'external_event_id' => '',
          'external_etag' => '',
          'ical_uid' => $uid,
          'sync_status' => 'deleted',
          'last_error' => null,
          'last_synced_at' => current_time('mysql', true),
        ]);
        Calendar_Repository::mark_connection((int) $connection->id, 'connected');
        return;
      }
      if ((string) $booking->status !== 'confirmed') return;
      $event = self::event_data($booking, (string) $binding->privacy_mode);
      $fingerprint = hash('sha256', wp_json_encode($event));
      if ($mapping && (string) $mapping->sync_status === 'synced' && hash_equals((string) $mapping->booking_fingerprint, $fingerprint)) return;
      $result = $provider->upsert_event(
        $connection,
        (string) $binding->external_calendar_id,
        $event,
        $mapping ? (string) $mapping->external_event_id : ''
      );
      Calendar_Repository::save_event((int) $booking->id, (int) $binding->id, [
        'external_event_id' => (string) ($result['id'] ?? ''),
        'external_etag' => (string) ($result['etag'] ?? ''),
        'ical_uid' => $uid,
        'booking_fingerprint' => $fingerprint,
        'sync_status' => 'synced',
        'last_error' => null,
        'last_synced_at' => current_time('mysql', true),
      ]);
      Calendar_Repository::mark_connection((int) $connection->id, 'connected');
    } catch (\Throwable $error) {
      Calendar_Repository::save_event((int) $booking->id, (int) $binding->id, [
        'ical_uid' => $uid,
        'sync_status' => 'error',
        'last_error' => sanitize_text_field($error->getMessage()),
      ]);
      if (!empty($binding->connection_id)) {
        $status = preg_match('/reauthor|invalid[_ ]grant|invalid token|unauthor|consent/i', $error->getMessage())
          ? 'reauth_required'
          : 'error';
        Calendar_Repository::mark_connection((int) $binding->connection_id, $status, sanitize_text_field($error->getMessage()));
      }
      throw $error;
    }
  }

  public static function enqueue_listing(int $listing_id): int {
    $resource_id = Resources::ensure_for_listing($listing_id);
    return $resource_id ? self::enqueue_resource($resource_id) : 0;
  }

  public static function enqueue_resource(int $resource_id): int {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
      'SELECT id FROM ' . DB::table() . " WHERE resource_id = %d AND status IN ('confirmed','cancelled','refunded','expired','conflict') ORDER BY updated_at DESC LIMIT 500",
      $resource_id
    ));
    foreach ($ids as $id) self::enqueue_booking((int) $id);
    return count($ids);
  }

  public static function remove_binding_events(object $binding, bool $best_effort = false): void {
    $connection = Calendar_Repository::get_connection((int) $binding->connection_id, (int) $binding->user_id);
    if (!$connection) {
      Calendar_Repository::delete_events_for_binding((int) $binding->id);
      return;
    }
    $provider = new Calendar_Provider((string) $binding->provider);
    foreach (Calendar_Repository::list_events_for_binding((int) $binding->id) as $mapping) {
      if ((string) $mapping->external_event_id === '') continue;
      try {
        $provider->delete_event($connection, (string) $binding->external_calendar_id, (string) $mapping->external_event_id);
      } catch (\Throwable $error) {
        if (!$best_effort) throw $error;
      }
    }
    Calendar_Repository::delete_events_for_binding((int) $binding->id);
  }

  public static function reconcile(): void {
    global $wpdb;
    $resource_ids = $wpdb->get_col(
      'SELECT DISTINCT resource_id FROM ' . Calendar_Repository::bindings_table() . " WHERE resource_id IS NOT NULL AND enabled = 1 AND provider IN ('google','microsoft') LIMIT 100"
    );
    foreach ($resource_ids as $resource_id) self::enqueue_resource((int) $resource_id);
  }

  public static function event_data(object $booking, string $privacy_mode = 'minimal'): array {
    $timezone = !empty($booking->timezone) ? (string) $booking->timezone : 'UTC';
    try {
      $tz = new \DateTimeZone($timezone);
    } catch (\Throwable $error) {
      $timezone = 'UTC';
      $tz = new \DateTimeZone('UTC');
    }
    $start = new \DateTimeImmutable((string) $booking->start_datetime, $tz);
    $end = new \DateTimeImmutable((string) $booking->end_datetime, $tz);
    $subject_id = (int) ($booking->listing_id ?? 0) ?: (int) ($booking->provider_id ?? 0);
    $listing = get_the_title($subject_id) ?: 'Koopo professional';
    $service = get_the_title((int) $booking->service_id) ?: 'Appointment';
    $description = 'Koopo appointment #' . (int) $booking->id . '. Manage this appointment in Koopo; changes made in this calendar do not update Koopo.';
    if ($privacy_mode === 'standard') {
      $customer = (string) Bookings::extra_from_record($booking, 'customer_name', '');
      if ($customer !== '') $description .= "\nCustomer: " . $customer;
    }
    $fulfillment_mode = (string) Bookings::extra_from_record($booking, 'fulfillment_mode', 'at_location');
    $location = $listing;
    if ($fulfillment_mode === 'mobile') {
      $location = implode(', ', array_filter([(string)Bookings::extra_from_record($booking,'service_address_1',''),(string)Bookings::extra_from_record($booking,'service_address_2',''),(string)Bookings::extra_from_record($booking,'service_city',''),(string)Bookings::extra_from_record($booking,'service_region',''),(string)Bookings::extra_from_record($booking,'service_postal_code','')]));
    } elseif ($fulfillment_mode === 'virtual') {
      $location = (string) Bookings::extra_from_record($booking, 'virtual_join_url', '') ?: 'Online appointment';
    }
    $utc = new \DateTimeZone('UTC');
    return [
      'booking_id' => (int) $booking->id,
      'uid' => self::uid((int) $booking->id),
      'transaction_id' => self::transaction_id((int) $booking->id),
      'title' => $service . ' at ' . $listing,
      'description' => $description,
      'location' => $location,
      'timezone' => $timezone,
      'start_rfc3339' => $start->format(DATE_RFC3339),
      'end_rfc3339' => $end->format(DATE_RFC3339),
      'start_local' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s'),
      'end_local' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s'),
      'microsoft_timezone' => 'UTC',
    ];
  }

  public static function ics_for_listing(int $listing_id, string $privacy_mode = 'minimal'): string {
    $resource_id = Resources::ensure_for_listing($listing_id);
    return self::ics_for_resource($resource_id, $privacy_mode);
  }

  public static function ics_for_resource(int $resource_id, string $privacy_mode = 'minimal'): string {
    global $wpdb;
    $resource = Resources::get($resource_id);
    if (!$resource) return '';
    $from = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
    $bookings = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . DB::table() . " WHERE resource_id = %d AND status = 'confirmed' AND end_datetime >= %s ORDER BY start_datetime ASC LIMIT 1000",
      $resource_id,
      $from
    )) ?: [];
    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Koopo//Appointments Calendar//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'X-WR-CALNAME:' . self::ics_escape((get_the_title((int) $resource->subject_id) ?: 'Koopo') . ' Appointments'),
      'X-PUBLISHED-TTL:PT15M',
      'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
    ];
    foreach ($bookings as $booking) {
      $event = self::event_data($booking, $privacy_mode);
      $start = new \DateTimeImmutable($event['start_rfc3339']);
      $end = new \DateTimeImmutable($event['end_rfc3339']);
      $lines = array_merge($lines, [
        'BEGIN:VEVENT',
        'UID:' . self::ics_escape($event['uid']),
        'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime((string) ($booking->updated_at ?? 'now'))),
        'DTSTART:' . $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        'DTEND:' . $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        'SUMMARY:' . self::ics_escape($event['title']),
        'DESCRIPTION:' . self::ics_escape($event['description']),
        'LOCATION:' . self::ics_escape($event['location']),
        'STATUS:CONFIRMED',
        'TRANSP:OPAQUE',
        'SEQUENCE:' . max(0, (int) strtotime((string) ($booking->updated_at ?? 'now'))),
        'END:VEVENT',
      ]);
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map([__CLASS__, 'fold_ics_line'], $lines)) . "\r\n";
  }

  public static function uid(int $booking_id): string {
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    return 'koopo-booking-' . $booking_id . '@' . ($host ?: 'koopoonline.com');
  }

  private static function transaction_id(int $booking_id): string {
    $hex = md5(self::uid($booking_id));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
  }

  private static function ics_escape(string $value): string {
    return str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
  }

  public static function fold_ics_line(string $line): string {
    $result = '';
    while (strlen($line) > 75) {
      $chunk = function_exists('mb_strcut') ? mb_strcut($line, 0, 75, 'UTF-8') : substr($line, 0, 75);
      $result .= $chunk . "\r\n ";
      $line = substr($line, strlen($chunk));
    }
    return $result . $line;
  }
}
