<?php
declare(strict_types=1);

namespace {
  define('ABSPATH', __DIR__ . '/');
  function __(string $text, ?string $domain = null): string { return $text; }
  function get_the_title(int $id): string { return $id === 10 ? 'Consultation' : 'Provider'; }
}

namespace Koopo_Appointments {
  require_once dirname(__DIR__) . '/includes/core/class-kgaw-bookings.php';
  require_once dirname(__DIR__) . '/includes/core/class-kgaw-date-formatter.php';

  function expect(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
  }

  $overlap = new \ReflectionMethod(Bookings::class, 'effective_ranges_overlap');
  $zone = new \DateTimeZone('America/Detroit');
  expect($overlap->invoke(null,
    new \DateTimeImmutable('2026-08-20 09:30:00', $zone),
    new \DateTimeImmutable('2026-08-20 10:30:00', $zone),
    new \DateTimeImmutable('2026-08-20 10:00:00', $zone),
    new \DateTimeImmutable('2026-08-20 11:00:00', $zone)
  ) === true, 'Expanded booking ranges should conflict.');
  expect($overlap->invoke(null,
    new \DateTimeImmutable('2026-08-20 09:00:00', $zone),
    new \DateTimeImmutable('2026-08-20 10:00:00', $zone),
    new \DateTimeImmutable('2026-08-20 10:00:00', $zone),
    new \DateTimeImmutable('2026-08-20 11:00:00', $zone)
  ) === false, 'Adjacent effective ranges should remain bookable.');

  $booking = (object) [
    'service_id' => 10,
    'provider_id' => 20,
    'listing_id' => 0,
    'start_datetime' => '2026-08-20 09:00:00',
    'end_datetime' => '2026-08-20 10:00:00',
    'timezone' => 'America/Detroit',
    'fulfillment_mode' => 'virtual',
    'virtual_join_url' => 'https://meet.example.test/private-room',
    'status' => 'pending_payment',
  ];
  $pending_links = Date_Formatter::get_calendar_links($booking);
  expect(strpos(urldecode($pending_links['google']), 'private-room') === false, 'Pending calendar link leaked the private meeting URL.');
  expect(strpos(urldecode($pending_links['ical']), 'private-room') === false, 'Pending iCal data leaked the private meeting URL.');
  $booking->status = 'confirmed';
  $confirmed_links = Date_Formatter::get_calendar_links($booking);
  expect(strpos(urldecode($confirmed_links['google']), 'private-room') !== false, 'Confirmed calendar link omitted the meeting URL.');

  $root = dirname(__DIR__);
  $source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
  expect(strpos($source('includes/core/class-kgaw-availability.php'), 'authorized_excluded_booking_id') !== false, 'Reschedule availability exclusion guard is missing.');
  expect(strpos($source('includes/privacy/class-kgaw-privacy.php'), 'wp_privacy_personal_data_erasers') !== false, 'WordPress privacy eraser is missing.');
  expect(strpos($source('includes/providers/class-kgaw-service-areas.php'), 'geocoder_privacy_configuration_required') !== false, 'Public geocoder privacy guard is missing.');
  expect(strpos($source('includes/core/class-kgaw-db.php'), 'form_snapshot_json') !== false, 'Immutable intake form snapshot schema is missing.');

  echo "production hardening tests passed\n";
}
