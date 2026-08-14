<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Calendar_Repository {
  const VERSION = '3.0';

  public static function connections_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_calendar_connections';
  }

  public static function bindings_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_calendar_bindings';
  }

  public static function events_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_calendar_events';
  }

  public static function busy_sources_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_calendar_busy_sources';
  }

  public static function busy_blocks_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_calendar_busy_blocks';
  }

  public static function maybe_upgrade(): void {
    if ((string) get_option('koopo_appt_calendar_db_version', '') === self::VERSION) return;
    self::create_tables();
  }

  public static function create_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $connections = self::connections_table();
    $bindings = self::bindings_table();
    $events = self::events_table();
    $busy_sources = self::busy_sources_table();
    $busy_blocks = self::busy_blocks_table();

    $connections_sql = "CREATE TABLE {$connections} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(30) NOT NULL,
      provider_account_id VARCHAR(191) NOT NULL DEFAULT '',
      account_email VARCHAR(191) NOT NULL DEFAULT '',
      account_label VARCHAR(191) NOT NULL DEFAULT '',
      token_encrypted LONGTEXT NOT NULL,
      scopes TEXT NULL,
      token_expires_at DATETIME NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'connected',
      last_synced_at DATETIME NULL,
      last_error TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY user_provider_account (user_id, provider, provider_account_id),
      KEY user_status (user_id, status)
    ) {$charset};";

    $bindings_sql = "CREATE TABLE {$bindings} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      listing_id BIGINT UNSIGNED NULL,
      provider_id BIGINT UNSIGNED NULL,
      resource_id BIGINT UNSIGNED NULL,
      provider VARCHAR(30) NOT NULL,
      connection_id BIGINT UNSIGNED NULL,
      external_calendar_id VARCHAR(255) NOT NULL DEFAULT '',
      external_calendar_name VARCHAR(191) NOT NULL DEFAULT '',
      ics_token_hash CHAR(64) NULL,
      ics_token_encrypted LONGTEXT NULL,
      enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
      privacy_mode VARCHAR(30) NOT NULL DEFAULT 'minimal',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY listing_provider (listing_id, provider),
      UNIQUE KEY resource_provider (resource_id, provider),
      UNIQUE KEY ics_token_hash (ics_token_hash),
      KEY connection_enabled (connection_id, enabled),
      KEY user_listing (user_id, listing_id)
    ) {$charset};";

    $events_sql = "CREATE TABLE {$events} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      booking_id BIGINT UNSIGNED NOT NULL,
      binding_id BIGINT UNSIGNED NOT NULL,
      external_event_id VARCHAR(255) NOT NULL DEFAULT '',
      external_etag VARCHAR(255) NOT NULL DEFAULT '',
      ical_uid VARCHAR(255) NOT NULL,
      booking_fingerprint CHAR(64) NOT NULL DEFAULT '',
      sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
      last_error TEXT NULL,
      last_synced_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY booking_binding (booking_id, binding_id),
      KEY binding_status (binding_id, sync_status),
      KEY external_event (binding_id, external_event_id)
    ) {$charset};";

    $busy_sources_sql = "CREATE TABLE {$busy_sources} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      resource_id BIGINT UNSIGNED NOT NULL,
      binding_id BIGINT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(30) NOT NULL,
      external_calendar_id VARCHAR(255) NOT NULL,
      external_calendar_name VARCHAR(191) NOT NULL DEFAULT '',
      external_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
      busy_mode VARCHAR(30) NOT NULL DEFAULT 'respect_provider',
      refresh_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
      enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
      last_synced_at DATETIME NULL,
      last_error TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY resource_connection_calendar (resource_id, connection_id, external_calendar_id(120)),
      KEY resource_enabled (resource_id, enabled),
      KEY refresh_due (enabled, last_synced_at),
      KEY connection_id (connection_id)
    ) {$charset};";

    $busy_blocks_sql = "CREATE TABLE {$busy_blocks} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      source_id BIGINT UNSIGNED NOT NULL,
      resource_id BIGINT UNSIGNED NOT NULL,
      external_event_key CHAR(64) NOT NULL,
      starts_at_utc DATETIME NOT NULL,
      ends_at_utc DATETIME NOT NULL,
      is_all_day TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY source_event (source_id, external_event_key),
      KEY resource_window (resource_id, starts_at_utc, ends_at_utc),
      KEY source_id (source_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($connections_sql);
    dbDelta($bindings_sql);
    dbDelta($events_sql);
    dbDelta($busy_sources_sql);
    dbDelta($busy_blocks_sql);
    $legacy_bindings = $wpdb->get_results('SELECT id, listing_id FROM ' . $bindings . ' WHERE (resource_id IS NULL OR resource_id = 0) AND listing_id IS NOT NULL AND listing_id > 0') ?: [];
    foreach ($legacy_bindings as $binding) {
      $resource_id = Resources::ensure_for_listing((int) $binding->listing_id);
      if ($resource_id) $wpdb->update($bindings, ['resource_id' => $resource_id], ['id' => (int) $binding->id], ['%d'], ['%d']);
    }
    update_option('koopo_appt_calendar_db_version', self::VERSION);
  }

  public static function get_connection(int $id, int $user_id = 0): ?object {
    global $wpdb;
    $sql = 'SELECT * FROM ' . self::connections_table() . ' WHERE id = %d';
    $args = [$id];
    if ($user_id > 0) {
      $sql .= ' AND user_id = %d';
      $args[] = $user_id;
    }
    $row = $wpdb->get_row($wpdb->prepare($sql, $args));
    return $row ?: null;
  }

  public static function list_connections(int $user_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT id, user_id, provider, account_email, account_label, scopes, token_expires_at, status, last_synced_at, last_error, created_at, updated_at FROM ' . self::connections_table() . ' WHERE user_id = %d ORDER BY provider, id',
      $user_id
    )) ?: [];
  }

  public static function save_connection(int $user_id, string $provider, array $profile, array $tokens): int {
    global $wpdb;
    $account_id = sanitize_text_field((string) ($profile['id'] ?? $profile['email'] ?? 'default'));
    $existing = $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . self::connections_table() . ' WHERE user_id = %d AND provider = %s AND provider_account_id = %s',
      $user_id,
      $provider,
      $account_id
    ));
    $expires_at = !empty($tokens['expires_at']) ? gmdate('Y-m-d H:i:s', (int) $tokens['expires_at']) : null;
    $data = [
      'user_id' => $user_id,
      'provider' => $provider,
      'provider_account_id' => $account_id,
      'account_email' => sanitize_email((string) ($profile['email'] ?? '')),
      'account_label' => sanitize_text_field((string) ($profile['name'] ?? $profile['email'] ?? ucfirst($provider))),
      'token_encrypted' => Calendar_Crypto::encrypt($tokens),
      'scopes' => sanitize_text_field((string) ($tokens['scope'] ?? '')),
      'token_expires_at' => $expires_at,
      'status' => 'connected',
      'last_error' => null,
    ];
    if ($existing) {
      $wpdb->update(self::connections_table(), $data, ['id' => (int) $existing]);
      return (int) $existing;
    }
    $wpdb->insert(self::connections_table(), $data);
    return (int) $wpdb->insert_id;
  }

  public static function update_connection_tokens(int $id, array $tokens): void {
    global $wpdb;
    $expires_at = !empty($tokens['expires_at']) ? gmdate('Y-m-d H:i:s', (int) $tokens['expires_at']) : null;
    $wpdb->update(self::connections_table(), [
      'token_encrypted' => Calendar_Crypto::encrypt($tokens),
      'token_expires_at' => $expires_at,
      'status' => 'connected',
      'last_error' => null,
    ], ['id' => $id]);
  }

  public static function mark_connection(int $id, string $status, string $error = ''): void {
    global $wpdb;
    $data = ['status' => $status, 'last_error' => $error ?: null];
    if ($status === 'connected') $data['last_synced_at'] = current_time('mysql', true);
    $wpdb->update(self::connections_table(), $data, ['id' => $id]);
  }

  public static function get_binding(int $listing_id, string $provider): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE listing_id = %d AND provider = %s',
      $listing_id,
      $provider
    ));
    return $row ?: null;
  }

  public static function get_binding_for_resource(int $resource_id, string $provider): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE resource_id = %d AND provider = %s',
      $resource_id,
      $provider
    ));
    return $row ?: null;
  }

  public static function list_bindings_for_user(int $user_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE user_id = %d ORDER BY listing_id, provider',
      $user_id
    )) ?: [];
  }

  public static function list_active_bindings_for_listing(int $listing_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE listing_id = %d AND enabled = 1',
      $listing_id
    )) ?: [];
  }

  public static function list_active_bindings_for_resource(int $resource_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE resource_id = %d AND enabled = 1',
      $resource_id
    )) ?: [];
  }

  public static function save_binding(array $data): int {
    global $wpdb;
    $resource_id = (int) ($data['resource_id'] ?? 0);
    $existing = $resource_id
      ? self::get_binding_for_resource($resource_id, (string) $data['provider'])
      : self::get_binding((int) ($data['listing_id'] ?? 0), (string) $data['provider']);
    if ($existing) {
      $wpdb->update(self::bindings_table(), $data, ['id' => (int) $existing->id]);
      return (int) $existing->id;
    }
    $wpdb->insert(self::bindings_table(), $data);
    return (int) $wpdb->insert_id;
  }

  public static function get_binding_by_ics_hash(string $hash): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::bindings_table() . ' WHERE provider = %s AND ics_token_hash = %s AND enabled = 1',
      'ics',
      $hash
    ));
    return $row ?: null;
  }

  public static function get_event(int $booking_id, int $binding_id): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::events_table() . ' WHERE booking_id = %d AND binding_id = %d',
      $booking_id,
      $binding_id
    ));
    return $row ?: null;
  }

  public static function list_events_for_binding(int $binding_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::events_table() . ' WHERE binding_id = %d',
      $binding_id
    )) ?: [];
  }

  public static function delete_events_for_binding(int $binding_id): void {
    global $wpdb;
    $wpdb->delete(self::events_table(), ['binding_id' => $binding_id]);
  }

  public static function save_event(int $booking_id, int $binding_id, array $data): void {
    global $wpdb;
    $existing = self::get_event($booking_id, $binding_id);
    $data['booking_id'] = $booking_id;
    $data['binding_id'] = $binding_id;
    if ($existing) {
      unset($data['booking_id'], $data['binding_id']);
      $wpdb->update(self::events_table(), $data, ['id' => (int) $existing->id]);
      return;
    }
    $wpdb->insert(self::events_table(), $data);
  }

  public static function delete_connection(int $id, int $user_id): bool {
    global $wpdb;
    $connection = self::get_connection($id, $user_id);
    if (!$connection) return false;
    $binding_ids = $wpdb->get_col($wpdb->prepare(
      'SELECT id FROM ' . self::bindings_table() . ' WHERE connection_id = %d',
      $id
    ));
    if ($binding_ids) {
      $ids = implode(',', array_map('absint', $binding_ids));
      $wpdb->query("DELETE FROM " . self::events_table() . " WHERE binding_id IN ({$ids})"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
    $source_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::busy_sources_table() . ' WHERE connection_id = %d', $id));
    if ($source_ids) {
      $ids = implode(',', array_map('absint', $source_ids));
      $wpdb->query('DELETE FROM ' . self::busy_blocks_table() . " WHERE source_id IN ({$ids})"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
    $wpdb->delete(self::busy_sources_table(), ['connection_id' => $id]);
    $wpdb->delete(self::bindings_table(), ['connection_id' => $id]);
    return (bool) $wpdb->delete(self::connections_table(), ['id' => $id, 'user_id' => $user_id]);
  }

  public static function list_busy_sources_for_resource(int $resource_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::busy_sources_table() . ' WHERE resource_id = %d ORDER BY provider, external_calendar_name',
      $resource_id
    )) ?: [];
  }

  public static function list_due_busy_sources(int $limit = 100): array {
    global $wpdb;
    $limit = min(500, max(1, $limit));
    return $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::busy_sources_table() . " WHERE enabled = 1 AND busy_mode <> 'informational' AND (last_synced_at IS NULL OR DATE_ADD(last_synced_at, INTERVAL refresh_minutes MINUTE) <= UTC_TIMESTAMP()) ORDER BY COALESCE(last_synced_at, '1970-01-01') ASC LIMIT %d",
      $limit
    )) ?: [];
  }

  public static function replace_busy_sources(int $resource_id, int $connection_id, int $user_id, string $provider, array $sources): array {
    global $wpdb;
    $existing = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::busy_sources_table() . ' WHERE resource_id = %d AND connection_id = %d',
      $resource_id,
      $connection_id
    )) ?: [];
    $keep_ids = [];
    foreach ($sources as $source) {
      $calendar_id = sanitize_text_field((string) ($source['calendar_id'] ?? ''));
      if ($calendar_id === '') continue;
      $mode = sanitize_key((string) ($source['busy_mode'] ?? 'respect_provider'));
      if (!in_array($mode, ['respect_provider', 'all_events', 'informational'], true)) $mode = 'respect_provider';
      $refresh = absint($source['refresh_minutes'] ?? 15);
      if (!in_array($refresh, [5, 15, 30, 60], true)) $refresh = 15;
      $match = null;
      foreach ($existing as $row) if ((string) $row->external_calendar_id === $calendar_id) { $match = $row; break; }
      $data = [
        'resource_id' => $resource_id,
        'binding_id' => !empty($source['binding_id']) ? absint($source['binding_id']) : null,
        'connection_id' => $connection_id,
        'user_id' => $user_id,
        'provider' => sanitize_key($provider),
        'external_calendar_id' => $calendar_id,
        'external_calendar_name' => sanitize_text_field((string) ($source['calendar_name'] ?? '')),
        'external_timezone' => sanitize_text_field((string) ($source['timezone'] ?? 'UTC')) ?: 'UTC',
        'busy_mode' => $mode,
        'refresh_minutes' => $refresh,
        'enabled' => empty($source['enabled']) ? 0 : 1,
      ];
      if ($match) {
        $wpdb->update(self::busy_sources_table(), $data, ['id'=>(int)$match->id]);
        $keep_ids[] = (int) $match->id;
      } else {
        $wpdb->insert(self::busy_sources_table(), $data);
        if ($wpdb->insert_id) $keep_ids[] = (int) $wpdb->insert_id;
      }
    }
    foreach ($existing as $row) {
      if (in_array((int) $row->id, $keep_ids, true)) continue;
      $wpdb->delete(self::busy_blocks_table(), ['source_id'=>(int)$row->id]);
      $wpdb->delete(self::busy_sources_table(), ['id'=>(int)$row->id]);
    }
    return self::list_busy_sources_for_resource($resource_id);
  }

  public static function replace_busy_blocks(int $source_id, int $resource_id, array $blocks): void {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
      $wpdb->delete(self::busy_blocks_table(), ['source_id'=>$source_id]);
      foreach ($blocks as $block) {
        $start = sanitize_text_field((string) ($block['start_utc'] ?? ''));
        $end = sanitize_text_field((string) ($block['end_utc'] ?? ''));
        $key = sanitize_text_field((string) ($block['event_key'] ?? ''));
        if (!$key || !$start || !$end || strtotime($end . ' UTC') <= strtotime($start . ' UTC')) continue;
        $wpdb->insert(self::busy_blocks_table(), [
          'source_id'=>$source_id,
          'resource_id'=>$resource_id,
          'external_event_key'=>hash('sha256', $key),
          'starts_at_utc'=>$start,
          'ends_at_utc'=>$end,
          'is_all_day'=>!empty($block['all_day']) ? 1 : 0,
        ]);
      }
      $wpdb->query('COMMIT');
    } catch (\Throwable $error) {
      $wpdb->query('ROLLBACK');
      throw $error;
    }
  }

  public static function mark_busy_source(int $source_id, string $error = ''): void {
    global $wpdb;
    $wpdb->update(self::busy_sources_table(), [
      'last_synced_at'=>current_time('mysql', true),
      'last_error'=>$error ? sanitize_text_field($error) : null,
    ], ['id'=>$source_id]);
  }
}
