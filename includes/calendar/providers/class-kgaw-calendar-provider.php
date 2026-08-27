<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Calendar_Provider {
  private const DEFAULT_MAX_PAGES = 50;
  private string $provider;

  public function __construct(string $provider) {
    if (!in_array($provider, ['google', 'microsoft'], true)) {
      throw new \InvalidArgumentException('Unsupported calendar provider.');
    }
    $this->provider = $provider;
  }

  public static function supported(): array {
    return ['google', 'microsoft'];
  }

  public function is_configured(): bool {
    return $this->client_id() !== '' && $this->client_secret() !== '';
  }

  public function is_enabled(): bool {
    return Admin_Settings::calendar_provider_enabled($this->provider);
  }

  public function callback_url(): string {
    return rest_url('koopo/v1/appointments/calendar/oauth/' . $this->provider . '/callback');
  }

  public function authorization_url(string $state): string {
    $this->require_enabled();
    $this->require_configuration();
    if ($this->provider === 'google') {
      return add_query_arg([
        'client_id' => $this->client_id(),
        'redirect_uri' => $this->callback_url(),
        'response_type' => 'code',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'scope' => implode(' ', [
          'openid',
          'email',
          'profile',
          'https://www.googleapis.com/auth/calendar.events',
          'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ]),
        'state' => $state,
      ], 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    return add_query_arg([
      'client_id' => $this->client_id(),
      'redirect_uri' => $this->callback_url(),
      'response_type' => 'code',
      'response_mode' => 'query',
      'scope' => 'openid profile email offline_access User.Read Calendars.ReadWrite',
      'state' => $state,
    ], 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/authorize');
  }

  public function exchange_code(string $code): array {
    $this->require_configuration();
    $url = $this->provider === 'google'
      ? 'https://oauth2.googleapis.com/token'
      : 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/token';
    $body = [
      'client_id' => $this->client_id(),
      'client_secret' => $this->client_secret(),
      'code' => $code,
      'redirect_uri' => $this->callback_url(),
      'grant_type' => 'authorization_code',
    ];
    if ($this->provider === 'microsoft') {
      $body['scope'] = 'openid profile email offline_access User.Read Calendars.ReadWrite';
    }
    return $this->normalize_tokens($this->request_json($url, ['method' => 'POST', 'body' => $body]));
  }

  public function refresh_tokens(array $tokens): array {
    $refresh_token = (string) ($tokens['refresh_token'] ?? '');
    if ($refresh_token === '') throw new \RuntimeException('Calendar connection must be reauthorized.');
    $url = $this->provider === 'google'
      ? 'https://oauth2.googleapis.com/token'
      : 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/token';
    $body = [
      'client_id' => $this->client_id(),
      'client_secret' => $this->client_secret(),
      'refresh_token' => $refresh_token,
      'grant_type' => 'refresh_token',
    ];
    if ($this->provider === 'microsoft') {
      $body['scope'] = 'openid profile email offline_access User.Read Calendars.ReadWrite';
    }
    $fresh = $this->normalize_tokens($this->request_json($url, ['method' => 'POST', 'body' => $body]));
    if (empty($fresh['refresh_token'])) $fresh['refresh_token'] = $refresh_token;
    return array_merge($tokens, $fresh);
  }

  public function ensure_access_token(object $connection): array {
    $tokens = Calendar_Crypto::decrypt((string) $connection->token_encrypted);
    $expires_at = (int) ($tokens['expires_at'] ?? 0);
    if (!empty($tokens['access_token']) && ($expires_at === 0 || $expires_at > time() + 120)) return $tokens;
    $tokens = $this->refresh_tokens($tokens);
    Calendar_Repository::update_connection_tokens((int) $connection->id, $tokens);
    return $tokens;
  }

  public function profile(array $tokens): array {
    $access_token = (string) ($tokens['access_token'] ?? '');
    if ($this->provider === 'google') {
      $profile = $this->request_json('https://openidconnect.googleapis.com/v1/userinfo', [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
      ]);
      return [
        'id' => (string) ($profile['sub'] ?? $profile['email'] ?? ''),
        'email' => (string) ($profile['email'] ?? ''),
        'name' => (string) ($profile['name'] ?? $profile['email'] ?? 'Google Calendar'),
      ];
    }
    $profile = $this->request_json('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName', [
      'headers' => ['Authorization' => 'Bearer ' . $access_token],
    ]);
    return [
      'id' => (string) ($profile['id'] ?? ''),
      'email' => (string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''),
      'name' => (string) ($profile['displayName'] ?? $profile['mail'] ?? 'Microsoft Outlook'),
    ];
  }

  public function list_calendars(object $connection): array {
    return array_values(array_filter($this->list_all_calendars($connection), static fn(array $calendar): bool => empty($calendar['read_only'])));
  }

  public function list_availability_calendars(object $connection): array {
    return $this->list_all_calendars($connection);
  }

  private function list_all_calendars(object $connection): array {
    $tokens = $this->ensure_access_token($connection);
    $headers = ['Authorization' => 'Bearer ' . $tokens['access_token']];
    if ($this->provider === 'google') {
      $items = [];
      $query = ['showHidden' => 'false', 'maxResults' => 250];
      $page = 0;
      do {
        $payload = $this->request_json(add_query_arg($query, 'https://www.googleapis.com/calendar/v3/users/me/calendarList'), ['headers' => $headers]);
        $items = array_merge($items, is_array($payload['items'] ?? null) ? $payload['items'] : []);
        $query['pageToken'] = sanitize_text_field((string) ($payload['nextPageToken'] ?? ''));
        $page++;
      } while ($query['pageToken'] !== '' && $page < $this->max_pages());
      return array_values(array_map(static function(array $calendar): array {
        $access = sanitize_key((string) ($calendar['accessRole'] ?? 'reader'));
        return [
          'id' => (string) ($calendar['id'] ?? ''),
          'name' => (string) ($calendar['summary'] ?? ''),
          'primary' => !empty($calendar['primary']),
          'read_only' => !in_array($access, ['writer', 'owner'], true),
          'timezone' => (string) ($calendar['timeZone'] ?? 'UTC'),
        ];
      }, $items));
    }
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/me/calendars?$select=id,name,canEdit,isDefaultCalendar&$top=250';
    $page = 0;
    while ($url !== '' && $page < $this->max_pages()) {
      $payload = $this->request_json($url, ['headers' => $headers]);
      $items = array_merge($items, is_array($payload['value'] ?? null) ? $payload['value'] : []);
      $url = $this->microsoft_next_link($payload);
      $page++;
    }
    return array_values(array_map(static function(array $calendar): array {
      return [
        'id' => (string) ($calendar['id'] ?? ''),
        'name' => (string) ($calendar['name'] ?? ''),
        'primary' => !empty($calendar['isDefaultCalendar']),
        'read_only' => empty($calendar['canEdit']),
        'timezone' => 'UTC',
      ];
    }, $items));
  }

  /** Return privacy-safe time ranges only; event titles and attendees are discarded. */
  public function list_busy_events(object $connection, string $calendar_id, string $start_utc, string $end_utc, string $mode = 'respect_provider', string $calendar_timezone = 'UTC'): array {
    if ($mode === 'informational') return [];
    $tokens = $this->ensure_access_token($connection);
    $headers = ['Authorization' => 'Bearer ' . $tokens['access_token']];
    $blocks = [];
    if ($this->provider === 'google') {
      $query = [
        'singleEvents'=>'true',
        'showDeleted'=>'false',
        'timeMin'=>gmdate('Y-m-d\\TH:i:s\\Z', strtotime($start_utc . ' UTC')),
        'timeMax'=>gmdate('Y-m-d\\TH:i:s\\Z', strtotime($end_utc . ' UTC')),
        'maxResults'=>2500,
        'fields'=>'items(id,status,transparency,start/date,start/dateTime,start/timeZone,end/date,end/dateTime,end/timeZone,extendedProperties/private),nextPageToken',
      ];
      $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events';
      do {
        $payload = $this->request_json(add_query_arg($query, $base), ['headers'=>$headers]);
        foreach ((array) ($payload['items'] ?? []) as $event) {
          if (($event['status'] ?? '') === 'cancelled') continue;
          if ($mode === 'respect_provider' && ($event['transparency'] ?? '') === 'transparent') continue;
          if (!empty($event['extendedProperties']['private']['koopo_booking_id'])) continue;
          $normalized = $this->normalize_google_busy_event((array) $event, $calendar_timezone);
          if ($normalized) $blocks[] = $normalized;
        }
        $query['pageToken'] = sanitize_text_field((string) ($payload['nextPageToken'] ?? ''));
      } while ($query['pageToken'] !== '');
      return $blocks;
    }

    $query = [
      'startDateTime'=>gmdate('c', strtotime($start_utc . ' UTC')),
      'endDateTime'=>gmdate('c', strtotime($end_utc . ' UTC')),
      '$select'=>'id,start,end,showAs,isCancelled',
      '$top'=>1000,
    ];
    $url = add_query_arg($query, 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendar_id) . '/calendarView');
    $headers['Prefer'] = 'outlook.timezone="UTC"';
    $page = 0;
    while ($url !== '' && $page < $this->max_pages()) {
      $payload = $this->request_json($url, ['headers'=>$headers]);
      foreach ((array) ($payload['value'] ?? []) as $event) {
        if (!empty($event['isCancelled'])) continue;
        if ($mode === 'respect_provider' && in_array(sanitize_key((string) ($event['showAs'] ?? 'busy')), ['free', 'workingelsewhere'], true)) continue;
        $start = $this->utc_mysql((string) ($event['start']['dateTime'] ?? ''), (string) ($event['start']['timeZone'] ?? 'UTC'));
        $end = $this->utc_mysql((string) ($event['end']['dateTime'] ?? ''), (string) ($event['end']['timeZone'] ?? 'UTC'));
        if ($start && $end) $blocks[] = ['event_key'=>(string)($event['id']??'') . '|' . $start,'start_utc'=>$start,'end_utc'=>$end,'all_day'=>false];
      }
      $url = $this->microsoft_next_link($payload);
      $page++;
    }
    return $blocks;
  }

  private function max_pages(): int {
    return min(100, max(1, (int) apply_filters('koopo_appt_calendar_max_pages', self::DEFAULT_MAX_PAGES, $this->provider)));
  }

  /** Only follow opaque Microsoft continuations back to the Graph API. */
  private function microsoft_next_link(array $payload): string {
    $url = trim((string) ($payload['@odata.nextLink'] ?? ''));
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    $path = (string) ($parts['path'] ?? '');
    if ($scheme !== 'https' || $host !== 'graph.microsoft.com' || $port !== 443 || !str_starts_with($path, '/v1.0/')) {
      Logger::warning('calendar_continuation_rejected', [
        'provider' => $this->provider,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
      ]);
      throw new \RuntimeException('Microsoft Calendar returned an invalid continuation URL.');
    }
    return esc_url_raw($url, ['https']);
  }

  private function normalize_google_busy_event(array $event, string $calendar_timezone = 'UTC'): ?array {
    $all_day = !empty($event['start']['date']);
    $start_raw = (string) ($event['start']['dateTime'] ?? $event['start']['date'] ?? '');
    $end_raw = (string) ($event['end']['dateTime'] ?? $event['end']['date'] ?? '');
    if (!$start_raw || !$end_raw) return null;
    $start = $this->utc_mysql($all_day ? $start_raw . ' 00:00:00' : $start_raw, (string) ($event['start']['timeZone'] ?? $calendar_timezone ?: 'UTC'));
    $end = $this->utc_mysql($all_day ? $end_raw . ' 00:00:00' : $end_raw, (string) ($event['end']['timeZone'] ?? $calendar_timezone ?: 'UTC'));
    if (!$start || !$end) return null;
    return ['event_key'=>(string)($event['id']??'') . '|' . $start,'start_utc'=>$start,'end_utc'=>$end,'all_day'=>$all_day];
  }

  private function utc_mysql(string $value, string $timezone): string {
    if ($value === '') return '';
    try { $zone = new \DateTimeZone($timezone ?: 'UTC'); } catch (\Throwable $error) { $zone = new \DateTimeZone('UTC'); }
    try { return (new \DateTimeImmutable($value, $zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (\Throwable $error) { return ''; }
  }

  public function upsert_event(object $connection, string $calendar_id, array $event, string $external_event_id = ''): array {
    $tokens = $this->ensure_access_token($connection);
    $headers = [
      'Authorization' => 'Bearer ' . $tokens['access_token'],
      'Content-Type' => 'application/json',
    ];
    if ($this->provider === 'google') {
      $payload = [
        'summary' => $event['title'],
        'description' => $event['description'],
        'location' => $event['location'],
        'start' => ['dateTime' => $event['start_rfc3339'], 'timeZone' => $event['timezone']],
        'end' => ['dateTime' => $event['end_rfc3339'], 'timeZone' => $event['timezone']],
        'extendedProperties' => ['private' => ['koopo_booking_id' => (string) $event['booking_id'], 'koopo_uid' => $event['uid']]],
      ];
      $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events';
      $deterministic_id = 'koopo' . substr(hash('sha256', (string) $event['uid']), 0, 40);
      if ($external_event_id === '') $payload['id'] = $deterministic_id;
      $url = $external_event_id !== '' ? $base . '/' . rawurlencode($external_event_id) : $base;
      $method = $external_event_id !== '' ? 'PATCH' : 'POST';
      $response = $this->request_json(
        $url,
        ['method' => $method, 'headers' => $headers, 'body' => wp_json_encode($payload)],
        $external_event_id !== '',
        $external_event_id === ''
      );
      if (!empty($response['_koopo_not_found'])) {
        $recovery_id = 'koopo' . substr(hash('sha256', $event['uid'] . '|' . $event['start_rfc3339'] . '|' . $event['end_rfc3339'] . '|' . $external_event_id), 0, 40);
        $payload['id'] = $recovery_id;
        $response = $this->request_json(
          $base,
          ['method' => 'POST', 'headers' => $headers, 'body' => wp_json_encode($payload)],
          false,
          true
        );
        if (!empty($response['_koopo_conflict'])) {
          $response = $this->request_json($base . '/' . rawurlencode($recovery_id), ['headers' => $headers]);
        }
      } elseif (!empty($response['_koopo_conflict'])) {
        $response = $this->request_json($base . '/' . rawurlencode($deterministic_id), ['headers' => $headers]);
      }
      return ['id' => (string) ($response['id'] ?? ''), 'etag' => (string) ($response['etag'] ?? '')];
    }

    $payload = [
      'subject' => $event['title'],
      'body' => ['contentType' => 'text', 'content' => $event['description']],
      'location' => ['displayName' => $event['location']],
      'start' => ['dateTime' => $event['start_local'], 'timeZone' => $event['microsoft_timezone']],
      'end' => ['dateTime' => $event['end_local'], 'timeZone' => $event['microsoft_timezone']],
      'showAs' => 'busy',
    ];
    if ($external_event_id === '') $payload['transactionId'] = $event['transaction_id'];
    $base = 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendar_id) . '/events';
    $url = $external_event_id !== '' ? $base . '/' . rawurlencode($external_event_id) : $base;
    $method = $external_event_id !== '' ? 'PATCH' : 'POST';
    $response = $this->request_json($url, ['method' => $method, 'headers' => $headers, 'body' => wp_json_encode($payload)], $external_event_id !== '');
    if (!empty($response['_koopo_not_found'])) {
      $payload['transactionId'] = $event['transaction_id'];
      $response = $this->request_json($base, ['method' => 'POST', 'headers' => $headers, 'body' => wp_json_encode($payload)]);
    }
    return ['id' => (string) ($response['id'] ?? $external_event_id), 'etag' => (string) ($response['@odata.etag'] ?? '')];
  }

  public function delete_event(object $connection, string $calendar_id, string $external_event_id): void {
    if ($external_event_id === '') return;
    $this->require_enabled();
    $tokens = $this->ensure_access_token($connection);
    $url = $this->provider === 'google'
      ? 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($external_event_id)
      : 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($external_event_id);
    $response = wp_remote_request($url, [
      'method' => 'DELETE',
      'timeout' => 20,
      'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
    ]);
    if (is_wp_error($response)) throw new \RuntimeException($response->get_error_message());
    $status = (int) wp_remote_retrieve_response_code($response);
    if (!in_array($status, [200, 204, 404, 410], true)) {
      throw new \RuntimeException('Calendar provider returned HTTP ' . $status . ' while deleting an event.');
    }
  }

  private function normalize_tokens(array $tokens): array {
    if (empty($tokens['access_token'])) throw new \RuntimeException('Calendar provider did not return an access token.');
    $tokens['expires_at'] = time() + max(0, (int) ($tokens['expires_in'] ?? 3600));
    return $tokens;
  }

  private function request_json(string $url, array $args = [], bool $allow_not_found = false, bool $allow_conflict = false): array {
    $this->require_enabled();
    $args = wp_parse_args($args, ['method' => 'GET', 'timeout' => 20]);
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) throw new \RuntimeException($response->get_error_message());
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($allow_not_found && in_array($status, [404, 410], true)) return ['_koopo_not_found' => true];
    if ($allow_conflict && $status === 409) return ['_koopo_conflict' => true];
    if ($status < 200 || $status >= 300) {
      $message = '';
      if (is_array($decoded)) {
        if (!empty($decoded['error_description']) && is_string($decoded['error_description'])) {
          $message = $decoded['error_description'];
        } elseif (!empty($decoded['error']['message']) && is_string($decoded['error']['message'])) {
          $message = $decoded['error']['message'];
        } elseif (!empty($decoded['error']) && is_string($decoded['error'])) {
          $message = $decoded['error'];
        }
      }
      throw new \RuntimeException($message ?: 'Calendar provider returned HTTP ' . $status . '.');
    }
    return is_array($decoded) ? $decoded : [];
  }

  private function require_configuration(): void {
    if (!$this->is_configured()) {
      throw new \RuntimeException(ucfirst($this->provider) . ' Calendar is not configured by the site administrator.');
    }
  }

  private function require_enabled(): void {
    if (!$this->is_enabled()) {
      throw new \RuntimeException(ucfirst($this->provider) . ' Calendar is disabled by the site administrator.');
    }
  }

  private function client_id(): string {
    return Admin_Settings::calendar_client_id($this->provider);
  }

  private function client_secret(): string {
    return Admin_Settings::calendar_client_secret($this->provider);
  }

  private function tenant(): string {
    return Admin_Settings::calendar_tenant();
  }
}
