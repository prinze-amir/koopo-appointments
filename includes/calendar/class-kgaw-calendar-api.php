<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Calendar_API {
  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
    add_filter('rest_pre_serve_request', [__CLASS__, 'serve_calendar_response'], 10, 4);
  }

  public static function register_routes(): void {
    register_rest_route('koopo/v1', '/appointments/calendar/connections', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'connections'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/connections/(?P<provider>google|microsoft)/authorize', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'authorize'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/oauth/(?P<provider>google|microsoft)/callback', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'oauth_callback'],
      'permission_callback' => '__return_true',
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/connections/(?P<id>\d+)/calendars', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'calendars'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/connections/(?P<id>\d+)/availability-calendars', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'availability_calendars'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/connections/(?P<id>\d+)', [
      'methods' => 'DELETE',
      'callback' => [__CLASS__, 'disconnect'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/bindings/(?P<listing_id>\d+)', [
      'methods' => 'PUT,POST',
      'callback' => [__CLASS__, 'save_binding'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/resource-bindings/(?P<resource_id>\d+)', [
      'methods' => 'PUT,POST',
      'callback' => [__CLASS__, 'save_binding'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/subscriptions/(?P<listing_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'create_subscription'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/resource-subscriptions/(?P<resource_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'create_subscription'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/sync/(?P<listing_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'sync_now'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/resource-sync/(?P<resource_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'sync_now'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/resource-availability-sources/(?P<resource_id>\d+)', [
      'methods' => 'PUT,POST',
      'callback' => [__CLASS__, 'save_availability_sources'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/resource-availability-sync/(?P<resource_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'sync_availability_now'],
      'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/appointments/calendar/feed/(?P<token>[A-Za-z0-9_-]{32,128})', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'feed'],
      'permission_callback' => '__return_true',
    ]);
  }

  public static function connections(\WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $connections = array_map([__CLASS__, 'connection_payload'], Calendar_Repository::list_connections($user_id));
    $bindings = array_map([__CLASS__, 'binding_payload'], Calendar_Repository::list_bindings_for_user($user_id));
    $providers = [];
    foreach (Calendar_Provider::supported() as $provider) {
      $client = new Calendar_Provider($provider);
      $providers[$provider] = [
        'configured' => $client->is_configured(),
        'enabled' => $client->is_enabled(),
        'callback_url' => $client->callback_url(),
      ];
    }
    return new \WP_REST_Response([
      'source_of_truth' => 'koopo',
      'direction' => 'outbound_appointments_inbound_busy',
      'connections' => $connections,
      'bindings' => $bindings,
      'providers' => $providers,
    ], 200);
  }

  public static function authorize(\WP_REST_Request $request) {
    $listing_id = absint($request->get_param('listing_id'));
    $resource_id = absint($request->get_param('resource_id'));
    if (!$resource_id && $listing_id) $resource_id = Resources::ensure_for_listing($listing_id);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status' => 403]);
    $resource = Resources::get($resource_id);
    $provider_name = sanitize_key((string) $request['provider']);
    if (!Admin_Settings::calendar_provider_enabled($provider_name)) {
      return self::provider_disabled_error($provider_name);
    }
    try {
      $provider = new Calendar_Provider($provider_name);
      $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
      set_transient('koopo_cal_oauth_' . hash('sha256', $state), [
        'user_id' => get_current_user_id(),
        'listing_id' => $listing_id,
        'provider_id' => $resource && $resource->subject_type === 'provider' ? (int) $resource->subject_id : 0,
        'resource_id' => $resource_id,
        'provider' => $provider_name,
        'redirect_url' => self::settings_url(),
      ], 15 * MINUTE_IN_SECONDS);
      return new \WP_REST_Response(['authorize_url' => $provider->authorization_url($state)], 200);
    } catch (\Throwable $error) {
      return new \WP_Error('calendar_authorize_failed', $error->getMessage(), ['status' => 503]);
    }
  }

  public static function oauth_callback(\WP_REST_Request $request) {
    $provider_name = sanitize_key((string) $request['provider']);
    $state = sanitize_text_field((string) $request->get_param('state'));
    $key = 'koopo_cal_oauth_' . hash('sha256', $state);
    $pending = $state !== '' ? get_transient($key) : false;
    delete_transient($key);
    if (!is_array($pending) || ($pending['provider'] ?? '') !== $provider_name) {
      return new \WP_Error('invalid_oauth_state', 'The calendar connection request expired or is invalid.', ['status' => 400]);
    }
    if (!Admin_Settings::calendar_provider_enabled($provider_name)) {
      return self::oauth_redirect((string) $pending['redirect_url'], 'error', ucfirst($provider_name) . ' Calendar is temporarily unavailable.');
    }
    if ($request->get_param('error')) {
      return self::oauth_redirect((string) $pending['redirect_url'], 'error', sanitize_text_field((string) $request->get_param('error')));
    }
    $code = sanitize_text_field((string) $request->get_param('code'));
    if ($code === '') return new \WP_Error('missing_oauth_code', 'Calendar provider did not return an authorization code.', ['status' => 400]);
    try {
      $provider = new Calendar_Provider($provider_name);
      $tokens = $provider->exchange_code($code);
      $profile = $provider->profile($tokens);
      $connection_id = Calendar_Repository::save_connection((int) $pending['user_id'], $provider_name, $profile, $tokens);
      $connection = Calendar_Repository::get_connection($connection_id, (int) $pending['user_id']);
      $calendars = $connection ? $provider->list_calendars($connection) : [];
      $selected = self::default_calendar($calendars);
      if ($selected) {
        Calendar_Repository::save_binding([
          'user_id' => (int) $pending['user_id'],
          'listing_id' => !empty($pending['listing_id']) ? (int) $pending['listing_id'] : null,
          'provider_id' => !empty($pending['provider_id']) ? (int) $pending['provider_id'] : null,
          'resource_id' => (int) ($pending['resource_id'] ?? 0),
          'provider' => $provider_name,
          'connection_id' => $connection_id,
          'external_calendar_id' => (string) $selected['id'],
          'external_calendar_name' => (string) $selected['name'],
          'enabled' => 1,
          'privacy_mode' => 'minimal',
        ]);
        Calendar_Sync::enqueue_resource((int) ($pending['resource_id'] ?? 0));
      }
      return self::oauth_redirect((string) $pending['redirect_url'], 'connected', $provider_name);
    } catch (\Throwable $error) {
      return self::oauth_redirect((string) $pending['redirect_url'], 'error', $error->getMessage());
    }
  }

  public static function calendars(\WP_REST_Request $request) {
    $connection = Calendar_Repository::get_connection(absint($request['id']), get_current_user_id());
    if (!$connection) return new \WP_Error('not_found', 'Calendar connection was not found.', ['status' => 404]);
    if (!Admin_Settings::calendar_provider_enabled((string) $connection->provider)) return self::provider_disabled_error((string) $connection->provider);
    try {
      $provider = new Calendar_Provider((string) $connection->provider);
      return new \WP_REST_Response(['items' => $provider->list_calendars($connection)], 200);
    } catch (\Throwable $error) {
      return new \WP_Error('calendar_list_failed', $error->getMessage(), ['status' => 502]);
    }
  }

  public static function availability_calendars(\WP_REST_Request $request) {
    $connection = Calendar_Repository::get_connection(absint($request['id']), get_current_user_id());
    if (!$connection) return new \WP_Error('not_found', 'Calendar connection was not found.', ['status' => 404]);
    if (!Admin_Settings::calendar_provider_enabled((string) $connection->provider)) return self::provider_disabled_error((string) $connection->provider);
    $resource_id = absint($request->get_param('resource_id'));
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status'=>403]);
    try {
      $provider = new Calendar_Provider((string) $connection->provider);
      $selected = [];
      foreach (Calendar_Repository::list_busy_sources_for_resource($resource_id) as $source) {
        if ((int) $source->connection_id !== (int) $connection->id) continue;
        $selected[(string) $source->external_calendar_id] = [
          'source_id'=>(int)$source->id,
          'enabled'=>!empty($source->enabled),
          'busy_mode'=>(string)$source->busy_mode,
          'refresh_minutes'=>(int)$source->refresh_minutes,
          'last_synced_at'=>$source->last_synced_at,
          'last_error'=>(string)($source->last_error??''),
        ];
      }
      $items = array_map(static function(array $calendar) use ($selected): array {
        return array_merge($calendar, $selected[(string)$calendar['id']] ?? ['source_id'=>0,'enabled'=>false,'busy_mode'=>'respect_provider','refresh_minutes'=>15,'last_synced_at'=>null,'last_error'=>'']);
      }, $provider->list_availability_calendars($connection));
      return new \WP_REST_Response(['items'=>$items], 200);
    } catch (\Throwable $error) {
      return new \WP_Error('calendar_list_failed', $error->getMessage(), ['status'=>502]);
    }
  }

  public static function save_availability_sources(\WP_REST_Request $request) {
    $resource_id = absint($request['resource_id']);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status'=>403]);
    $payload = (array) $request->get_json_params();
    $connection_id = absint($payload['connection_id'] ?? 0);
    $connection = Calendar_Repository::get_connection($connection_id, get_current_user_id());
    if (!$connection) return new \WP_Error('invalid_connection', 'Calendar connection was not found.', ['status'=>404]);
    if (!Admin_Settings::calendar_provider_enabled((string) $connection->provider)) return self::provider_disabled_error((string) $connection->provider);
    $calendars = [];
    try { $calendars = (new Calendar_Provider((string)$connection->provider))->list_availability_calendars($connection); }
    catch (\Throwable $error) { return new \WP_Error('calendar_list_failed', $error->getMessage(), ['status'=>502]); }
    $allowed = [];
    foreach ($calendars as $calendar) $allowed[(string)$calendar['id']] = $calendar;
    $sources = [];
    foreach ((array) ($payload['sources'] ?? []) as $source) {
      $id = sanitize_text_field((string) ($source['calendar_id'] ?? ''));
      if (!$id || empty($allowed[$id])) continue;
      $calendar = $allowed[$id];
      $sources[] = [
        'calendar_id'=>$id,
        'calendar_name'=>(string)$calendar['name'],
        'timezone'=>(string)($calendar['timezone']??'UTC'),
        'enabled'=>!empty($source['enabled']),
        'busy_mode'=>$source['busy_mode']??'respect_provider',
        'refresh_minutes'=>$source['refresh_minutes']??15,
      ];
    }
    $saved = Calendar_Repository::replace_busy_sources($resource_id, $connection_id, get_current_user_id(), (string)$connection->provider, $sources);
    $sync = Calendar_Busy::sync_resource($resource_id);
    return new \WP_REST_Response(['saved'=>count($saved),'sync'=>$sync], 200);
  }

  public static function sync_availability_now(\WP_REST_Request $request) {
    $resource_id = absint($request['resource_id']);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status'=>403]);
    return new \WP_REST_Response(Calendar_Busy::sync_resource($resource_id), 200);
  }

  public static function save_binding(\WP_REST_Request $request) {
    $listing_id = absint($request['listing_id']);
    $resource_id = absint($request['resource_id']);
    if (!$resource_id && $listing_id) $resource_id = Resources::ensure_for_listing($listing_id);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status' => 403]);
    $resource = Resources::get($resource_id);
    $payload = $request->get_json_params();
    $provider_name = sanitize_key((string) ($payload['provider'] ?? ''));
    if (!in_array($provider_name, Calendar_Provider::supported(), true)) return new \WP_Error('invalid_provider', 'Select a supported calendar provider.', ['status' => 400]);
    if (!Admin_Settings::calendar_provider_enabled($provider_name)) return self::provider_disabled_error($provider_name);
    $connection_id = absint($payload['connection_id'] ?? 0);
    $connection = Calendar_Repository::get_connection($connection_id, get_current_user_id());
    if (!$connection || (string) $connection->provider !== $provider_name) return new \WP_Error('invalid_connection', 'Calendar connection was not found.', ['status' => 404]);
    $calendar_id = sanitize_text_field((string) ($payload['calendar_id'] ?? ''));
    if ($calendar_id === '') return new \WP_Error('invalid_calendar', 'Select a calendar.', ['status' => 400]);
    $existing = Calendar_Repository::get_binding_for_resource($resource_id, $provider_name);
    if ($existing && (
      (int) $existing->connection_id !== $connection_id ||
      (string) $existing->external_calendar_id !== $calendar_id
    )) {
      try {
        Calendar_Sync::remove_binding_events($existing);
      } catch (\Throwable $error) {
        return new \WP_Error('calendar_move_failed', 'The old calendar could not be cleaned up, so Koopo kept the existing calendar selection. ' . $error->getMessage(), ['status' => 502]);
      }
    }
    $id = Calendar_Repository::save_binding([
      'user_id' => get_current_user_id(),
      'listing_id' => $listing_id ?: null,
      'provider_id' => $resource && $resource->subject_type === 'provider' ? (int) $resource->subject_id : null,
      'resource_id' => $resource_id,
      'provider' => $provider_name,
      'connection_id' => $connection_id,
      'external_calendar_id' => $calendar_id,
      'external_calendar_name' => sanitize_text_field((string) ($payload['calendar_name'] ?? '')),
      'enabled' => empty($payload['enabled']) ? 0 : 1,
      'privacy_mode' => ($payload['privacy_mode'] ?? '') === 'standard' ? 'standard' : 'minimal',
    ]);
    Calendar_Sync::enqueue_resource($resource_id);
    return new \WP_REST_Response(['binding_id' => $id, 'queued' => true], 200);
  }

  public static function create_subscription(\WP_REST_Request $request) {
    $listing_id = absint($request['listing_id']);
    $resource_id = absint($request['resource_id']);
    if (!$resource_id && $listing_id) $resource_id = Resources::ensure_for_listing($listing_id);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status' => 403]);
    $resource = Resources::get($resource_id);
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $payload = $request->get_json_params();
    $privacy = ($payload['privacy_mode'] ?? '') === 'standard' ? 'standard' : 'minimal';
    Calendar_Repository::save_binding([
      'user_id' => get_current_user_id(),
      'listing_id' => $listing_id ?: null,
      'provider_id' => $resource && $resource->subject_type === 'provider' ? (int) $resource->subject_id : null,
      'resource_id' => $resource_id,
      'provider' => 'ics',
      'connection_id' => null,
      'external_calendar_id' => '',
      'external_calendar_name' => 'Apple Calendar / iCalendar',
      'ics_token_hash' => hash('sha256', $token),
      'ics_token_encrypted' => Calendar_Crypto::encrypt(['token' => $token]),
      'enabled' => 1,
      'privacy_mode' => $privacy,
    ]);
    return new \WP_REST_Response(['subscription_url' => self::feed_url($token), 'privacy_mode' => $privacy], 201);
  }

  public static function sync_now(\WP_REST_Request $request) {
    $listing_id = absint($request['listing_id']);
    $resource_id = absint($request['resource_id']);
    if (!$resource_id && $listing_id) $resource_id = Resources::ensure_for_listing($listing_id);
    if (!self::can_manage_resource($resource_id)) return new \WP_Error('forbidden', 'You cannot manage this booking calendar.', ['status' => 403]);
    return new \WP_REST_Response(['queued' => Calendar_Sync::enqueue_resource($resource_id), 'availability'=>Calendar_Busy::sync_resource($resource_id)], 200);
  }

  public static function disconnect(\WP_REST_Request $request) {
    $connection = Calendar_Repository::get_connection(absint($request['id']), get_current_user_id());
    if (!$connection) return new \WP_Error('not_found', 'Calendar connection was not found.', ['status' => 404]);
    if (!Admin_Settings::calendar_provider_enabled((string) $connection->provider)) {
      return new \WP_Error('calendar_provider_disabled', 'This calendar provider must be enabled before Koopo can safely remove its external events and disconnect it.', ['status' => 409]);
    }
    foreach (Calendar_Repository::list_bindings_for_user(get_current_user_id()) as $binding) {
      if ((int) $binding->connection_id === (int) $connection->id) {
        try {
          Calendar_Sync::remove_binding_events($binding, false);
        } catch (\Throwable $error) {
          Logger::warning('calendar_disconnect_cleanup_failed', ['connection_id'=>(int)$connection->id, 'binding_id'=>(int)$binding->id, 'provider'=>(string)$connection->provider, 'error'=>$error->getMessage()]);
          return new \WP_Error('calendar_disconnect_cleanup_failed', 'Koopo could not remove its external calendar events. The connection was preserved so cleanup can be retried.', ['status' => 502]);
        }
      }
    }
    Calendar_Repository::delete_connection((int) $connection->id, get_current_user_id());
    return new \WP_REST_Response(['deleted' => true], 200);
  }

  public static function feed(\WP_REST_Request $request) {
    $token = (string) $request['token'];
    $binding = Calendar_Repository::get_binding_by_ics_hash(hash('sha256', $token));
    if (!$binding) return new \WP_Error('not_found', 'Calendar subscription was not found.', ['status' => 404]);
    $resource_id = (int) ($binding->resource_id ?? 0);
    $response = new \WP_REST_Response($resource_id
      ? Calendar_Sync::ics_for_resource($resource_id, (string) $binding->privacy_mode)
      : Calendar_Sync::ics_for_listing((int) $binding->listing_id, (string) $binding->privacy_mode), 200);
    $response->header('Content-Type', 'text/calendar; charset=utf-8');
    $response->header('Content-Disposition', 'inline; filename="koopo-appointments.ics"');
    $response->header('Cache-Control', 'private, max-age=300, no-transform');
    $response->header('X-Robots-Tag', 'noindex, nofollow');
    return $response;
  }

  public static function serve_calendar_response(bool $served, $result, $request, $server): bool {
    if ($served || !($request instanceof \WP_REST_Request)) return $served;
    if (strpos($request->get_route(), '/koopo/v1/appointments/calendar/feed/') !== 0) return $served;
    if (is_wp_error($result)) return $served;
    $data = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
    if (!is_string($data)) return $served;
    echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 payload.
    return true;
  }

  private static function connection_payload(object $connection): array {
    return [
      'id' => (int) $connection->id,
      'provider' => (string) $connection->provider,
      'email' => (string) $connection->account_email,
      'label' => (string) $connection->account_label,
      'status' => (string) $connection->status,
      'last_synced_at' => $connection->last_synced_at,
      'last_error' => (string) ($connection->last_error ?? ''),
    ];
  }

  private static function provider_disabled_error(string $provider): \WP_Error {
    return new \WP_Error(
      'calendar_provider_disabled',
      ucfirst(sanitize_key($provider)) . ' Calendar is temporarily unavailable.',
      ['status' => 503]
    );
  }

  private static function binding_payload(object $binding): array {
    $payload = [
      'id' => (int) $binding->id,
      'listing_id' => (int) $binding->listing_id,
      'provider_id' => (int) ($binding->provider_id ?? 0),
      'resource_id' => (int) ($binding->resource_id ?? 0),
      'provider' => (string) $binding->provider,
      'connection_id' => (int) $binding->connection_id,
      'calendar_id' => (string) $binding->external_calendar_id,
      'calendar_name' => (string) $binding->external_calendar_name,
      'enabled' => !empty($binding->enabled),
      'privacy_mode' => (string) $binding->privacy_mode,
    ];
    if ((string) $binding->provider === 'ics' && !empty($binding->ics_token_encrypted)) {
      $token = Calendar_Crypto::decrypt((string) $binding->ics_token_encrypted);
      if (!empty($token['token'])) $payload['subscription_url'] = self::feed_url((string) $token['token']);
    }
    return $payload;
  }

  private static function default_calendar(array $calendars): ?array {
    foreach ($calendars as $calendar) if (!empty($calendar['primary'])) return $calendar;
    return $calendars[0] ?? null;
  }

  private static function can_manage_listing(int $listing_id): bool {
    return $listing_id > 0 && (current_user_can('manage_options') || Access::can_manage_listing_feature($listing_id, 'appointments'));
  }

  private static function can_manage_resource(int $resource_id): bool {
    return $resource_id > 0 && Resources::can_manage($resource_id);
  }

  private static function feed_url(string $token): string {
    return rest_url('koopo/v1/appointments/calendar/feed/' . rawurlencode($token));
  }

  private static function settings_url(): string {
    return function_exists('dokan_get_navigation_url')
      ? dokan_get_navigation_url('koopo-appointment-settings')
      : home_url('/seller-dashboard/koopo-appointment-settings/');
  }

  private static function oauth_redirect(string $url, string $status, string $message): \WP_REST_Response {
    $url = add_query_arg([
      'koopo_calendar' => $status,
      'koopo_calendar_message' => $message,
    ], $url);
    $response = new \WP_REST_Response(null, 302);
    $response->header('Location', esc_url_raw($url));
    return $response;
  }
}
