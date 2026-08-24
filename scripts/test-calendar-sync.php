<?php
declare(strict_types=1);

namespace {
  define('ABSPATH', __DIR__ . '/');
  define('AUTH_KEY', 'calendar-test-auth-key');
  define('SECURE_AUTH_KEY', 'calendar-test-secure-key');

  function wp_json_encode($value) { return json_encode($value); }
  function wp_unslash($value) { return $value; }
  function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
  function add_settings_error($setting, $code, $message) { $GLOBALS['calendar_test_settings_errors'][] = compact('setting', 'code', 'message'); }
  function get_option($name, $default = false) { return $GLOBALS['calendar_test_options'][$name] ?? $default; }
  function get_the_title($id) { return (int) $id === 22 ? 'Studio' : 'Consultation'; }
  function home_url($path = '') { return 'https://beta.example.test' . $path; }
  function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
  function esc_url_raw($url, $protocols = null) { return filter_var($url, FILTER_SANITIZE_URL); }
  function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
  function apply_filters($hook, $value) { return $value; }
  function do_action($hook, ...$args) {}
}

namespace Koopo_Appointments {
  final class Bookings {
    public static function extra_from_record($booking, string $key, $default = '') {
      return $booking->{$key} ?? $default;
    }
  }

  require_once dirname(__DIR__) . '/includes/core/class-kgaw-logger.php';
  require_once dirname(__DIR__) . '/includes/calendar/class-kgaw-calendar-crypto.php';
  require_once dirname(__DIR__) . '/includes/admin/class-kgaw-admin-settings.php';
  require_once dirname(__DIR__) . '/includes/calendar/providers/class-kgaw-calendar-provider.php';
  require_once dirname(__DIR__) . '/includes/calendar/class-kgaw-calendar-sync.php';

  function expect(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
  }

  $encrypted = Calendar_Crypto::encrypt(['refresh_token' => 'secret', 'expires_at' => 123]);
  expect(strpos($encrypted, 'secret') === false, 'Encrypted token exposed plaintext.');
  expect(Calendar_Crypto::decrypt($encrypted)['refresh_token'] === 'secret', 'Token encryption round-trip failed.');

  $saved_secret = Admin_Settings::sanitize_google_calendar_secret('dashboard-secret');
  expect(strpos($saved_secret, 'dashboard-secret') === false, 'Admin setting stored the calendar secret in plaintext.');
  $GLOBALS['calendar_test_options'][Admin_Settings::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET] = $saved_secret;
  $GLOBALS['calendar_test_options'][Admin_Settings::OPTION_GOOGLE_CALENDAR_CLIENT_ID] = 'dashboard-client-id';
  expect(Admin_Settings::calendar_client_secret('google') === 'dashboard-secret', 'Admin calendar secret could not be decrypted.');
  expect(Admin_Settings::sanitize_google_calendar_secret('') === $saved_secret, 'Blank admin secret did not preserve the saved value.');
  expect((new Calendar_Provider('google'))->is_configured(), 'Provider did not read dashboard OAuth settings.');
  expect(!Admin_Settings::calendar_provider_enabled('google'), 'Google Calendar must fail closed when its release toggle is absent.');
  $GLOBALS['calendar_test_options'][Admin_Settings::OPTION_GOOGLE_CALENDAR_ENABLED] = 1;
  expect(Admin_Settings::calendar_provider_enabled('google'), 'Google Calendar release toggle could not enable the provider.');
  expect(!Admin_Settings::calendar_provider_enabled('microsoft'), 'Microsoft Calendar must fail closed when its release toggle is absent.');
  $GLOBALS['calendar_test_options'][Admin_Settings::OPTION_MICROSOFT_CALENDAR_ENABLED] = 1;
  expect(Admin_Settings::calendar_provider_enabled('microsoft'), 'Microsoft Calendar release toggle could not enable the provider.');
  $_POST[Admin_Settings::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET . '_clear'] = '1';
  expect(Admin_Settings::sanitize_google_calendar_secret('') === '', 'Explicit admin secret removal failed.');
  unset($_POST[Admin_Settings::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET . '_clear']);

  $microsoft = new Calendar_Provider('microsoft');
  $next_link = new \ReflectionMethod(Calendar_Provider::class, 'microsoft_next_link');
  expect($next_link->invoke($microsoft, ['@odata.nextLink'=>'https://graph.microsoft.com/v1.0/me/events?$skiptoken=safe']) !== '', 'Valid Microsoft continuation was rejected.');
  try {
    $next_link->invoke($microsoft, ['@odata.nextLink'=>'https://attacker.example/v1.0/me/events']);
    throw new \RuntimeException('Untrusted Microsoft continuation host was accepted.');
  } catch (\ReflectionException $error) {
    throw $error;
  } catch (\Throwable $error) {
    expect(strpos($error->getMessage(), 'invalid continuation URL') !== false, 'Unexpected continuation validation error.');
  }

  $booking = (object) [
    'id' => 8273,
    'listing_id' => 22,
    'service_id' => 33,
    'start_datetime' => '2026-08-08 09:00:00',
    'end_datetime' => '2026-08-08 10:00:00',
    'timezone' => 'America/Detroit',
    'customer_name' => 'Test Customer',
  ];
  $minimal = Calendar_Sync::event_data($booking, 'minimal');
  expect($minimal['uid'] === 'koopo-booking-8273@beta.example.test', 'Stable UID is incorrect.');
  expect($minimal['start_local'] === '2026-08-08T13:00:00', 'UTC conversion is incorrect.');
  expect(strpos($minimal['description'], 'Test Customer') === false, 'Minimal privacy leaked customer name.');
  $standard = Calendar_Sync::event_data($booking, 'standard');
  expect(strpos($standard['description'], 'Test Customer') !== false, 'Standard privacy omitted customer name.');

  $folded = Calendar_Sync::fold_ics_line(str_repeat('A', 90));
  expect(strpos($folded, "\r\n ") !== false, 'iCalendar line folding failed.');

  $calendar_api = (string) file_get_contents(dirname(__DIR__) . '/includes/calendar/class-kgaw-calendar-api.php');
  expect(strpos($calendar_api, 'calendar_provider_disabled') !== false, 'Disconnect does not preserve disabled calendar connections for cleanup.');
  expect(strpos($calendar_api, 'Calendar_Sync::remove_binding_events($binding, false)') !== false, 'Disconnect still uses best-effort external-event cleanup.');
  expect(strpos($calendar_api, 'calendar_disconnect_cleanup_failed') !== false, 'Disconnect does not retain credentials when remote cleanup fails.');

  echo "calendar-sync tests passed\n";
}
