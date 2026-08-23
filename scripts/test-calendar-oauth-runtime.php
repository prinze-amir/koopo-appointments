<?php
/**
 * Non-authorizing beta preflight for Google and Microsoft calendar OAuth.
 * Run with: KOOPO_APPT_OAUTH_UAT=1 wp eval-file scripts/test-calendar-oauth-runtime.php <resource_id>
 */

use Koopo_Appointments\Admin_Settings;
use Koopo_Appointments\Calendar_API;
use Koopo_Appointments\Calendar_Crypto;
use Koopo_Appointments\Calendar_Provider;
use Koopo_Appointments\Calendar_Repository;
use Koopo_Appointments\Resources;

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_OAUTH_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_OAUTH_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

$resource_id = absint($args[0] ?? 0);
$resource = Resources::get($resource_id);
if (!$resource || $resource->status !== 'active') throw new RuntimeException('Choose an active booking resource.');
wp_set_current_user((int) $resource->owner_user_id);

$connections = Calendar_Repository::list_connections((int) $resource->owner_user_id);
$connection_counts = ['google' => 0, 'microsoft' => 0];
foreach ($connections as $connection) {
  if (isset($connection_counts[$connection->provider])) $connection_counts[$connection->provider]++;
}

$results = [];
foreach (Calendar_Provider::supported() as $provider_name) {
  $provider = new Calendar_Provider($provider_name);
  $client_id = Admin_Settings::calendar_client_id($provider_name);
  $client_secret = Admin_Settings::calendar_client_secret($provider_name);
  $secret_option = $provider_name === 'google'
    ? Admin_Settings::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET
    : Admin_Settings::OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET;
  $stored_secret = (string) get_option($secret_option, '');
  $secret_roundtrip = false;
  if ($stored_secret !== '' && $client_secret !== '') {
    try {
      $secret_roundtrip = ((string) (Calendar_Crypto::decrypt($stored_secret)['secret'] ?? '')) === $client_secret;
    } catch (Throwable $error) {
      $secret_roundtrip = false;
    }
  }

  $request = new WP_REST_Request('POST');
  $request->set_param('provider', $provider_name);
  $request->set_param('resource_id', $resource_id);
  $authorization = Calendar_API::authorize($request);
  if (is_wp_error($authorization)) throw new RuntimeException($provider_name . ' authorization preflight failed: ' . $authorization->get_error_message());
  $authorization_data = (array) $authorization->get_data();
  $authorize_url = (string) ($authorization_data['authorize_url'] ?? '');
  $query = [];
  parse_str((string) wp_parse_url($authorize_url, PHP_URL_QUERY), $query);
  $state = sanitize_text_field((string) ($query['state'] ?? ''));
  $state_recorded = $state !== '' && is_array(get_transient('koopo_cal_oauth_' . hash('sha256', $state)));
  if ($state !== '') delete_transient('koopo_cal_oauth_' . hash('sha256', $state));

  $discovery_url = $provider_name === 'google'
    ? 'https://accounts.google.com/.well-known/openid-configuration'
    : 'https://login.microsoftonline.com/' . rawurlencode(Admin_Settings::calendar_tenant()) . '/v2.0/.well-known/openid-configuration';
  $discovery = wp_remote_get($discovery_url, ['timeout' => 20, 'redirection' => 3]);
  $callback = $provider->callback_url();
  $required_scopes = $provider_name === 'google'
    ? ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly']
    : ['openid', 'profile', 'email', 'offline_access', 'User.Read', 'Calendars.ReadWrite'];
  $scopes = preg_split('/\s+/', trim((string) ($query['scope'] ?? ''))) ?: [];

  $results[$provider_name] = [
    'configured' => $provider->is_configured(),
    'client_id_shape_valid' => $provider_name === 'google'
      ? (bool) preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $client_id)
      : (bool) preg_match('/^[0-9a-f-]{36}$/i', $client_id),
    'secret_encrypted_at_rest' => $stored_secret !== '' && $stored_secret !== $client_secret,
    'secret_decrypts' => $secret_roundtrip,
    'callback_url' => $callback,
    'callback_is_https' => wp_parse_url($callback, PHP_URL_SCHEME) === 'https',
    'authorize_host' => (string) wp_parse_url($authorize_url, PHP_URL_HOST),
    'authorize_path' => (string) wp_parse_url($authorize_url, PHP_URL_PATH),
    'redirect_matches_callback' => (string) ($query['redirect_uri'] ?? '') === $callback,
    'required_scopes_present' => count(array_diff($required_scopes, $scopes)) === 0,
    'offline_access_requested' => $provider_name === 'google'
      ? (($query['access_type'] ?? '') === 'offline' && ($query['prompt'] ?? '') === 'consent')
      : in_array('offline_access', $scopes, true),
    'state_recorded' => $state_recorded,
    'state_bytes' => $state === '' ? 0 : strlen((string) base64_decode(strtr($state, '-_', '+/') . str_repeat('=', (4 - strlen($state) % 4) % 4), true)),
    'discovery_status' => is_wp_error($discovery) ? 0 : wp_remote_retrieve_response_code($discovery),
    'existing_connections' => $connection_counts[$provider_name],
  ];
}

$invalid = new WP_REST_Request('GET');
$invalid->set_param('provider', 'google');
$invalid->set_param('state', 'koopo-invalid-oauth-state');
$invalid_result = Calendar_API::oauth_callback($invalid);

echo wp_json_encode([
  'resource_id' => $resource_id,
  'owner_user_id' => (int) $resource->owner_user_id,
  'providers' => $results,
  'invalid_state_rejected' => is_wp_error($invalid_result)
    && $invalid_result->get_error_code() === 'invalid_oauth_state'
    && (int) ($invalid_result->get_error_data()['status'] ?? 0) === 400,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
