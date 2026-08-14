<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Imports external events as privacy-safe, read-only availability blocks. */
final class Calendar_Busy {
  const REFRESH_HOOK = 'koopo_appt_calendar_refresh_busy';

  public static function init(): void {
    add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
    add_action(self::REFRESH_HOOK, [__CLASS__, 'refresh_due']);
    if (!wp_next_scheduled(self::REFRESH_HOOK)) wp_schedule_event(time() + 300, 'koopo_five_minutes', self::REFRESH_HOOK);
  }

  public static function cron_schedules(array $schedules): array {
    $schedules['koopo_five_minutes'] = ['interval'=>300,'display'=>__('Every five minutes','koopo-appointments')];
    return $schedules;
  }

  public static function refresh_due(): void {
    foreach (Calendar_Repository::list_due_busy_sources(100) as $source) self::sync_source($source);
  }

  public static function sync_resource(int $resource_id): array {
    $result = ['sources'=>0,'blocks'=>0,'errors'=>[],'synced_at'=>current_time('mysql', true)];
    foreach (Calendar_Repository::list_busy_sources_for_resource($resource_id) as $source) {
      if (empty($source->enabled) || (string) $source->busy_mode === 'informational') continue;
      try {
        $result['blocks'] += self::sync_source($source);
        $result['sources']++;
      } catch (\Throwable $error) {
        $result['errors'][] = sanitize_text_field($error->getMessage());
      }
    }
    return $result;
  }

  public static function sync_source(object $source): int {
    try {
      $connection = Calendar_Repository::get_connection((int) $source->connection_id, (int) $source->user_id);
      if (!$connection || (string) $connection->status === 'disconnected') throw new \RuntimeException('Calendar connection is unavailable.');
      $provider = new Calendar_Provider((string) $source->provider);
      $start = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
      $end = gmdate('Y-m-d H:i:s', time() + 120 * DAY_IN_SECONDS);
      $blocks = $provider->list_busy_events($connection, (string) $source->external_calendar_id, $start, $end, (string) $source->busy_mode, (string) $source->external_timezone);
      Calendar_Repository::replace_busy_blocks((int) $source->id, (int) $source->resource_id, $blocks);
      Calendar_Repository::mark_busy_source((int) $source->id);
      Calendar_Repository::mark_connection((int) $connection->id, 'connected');
      return count($blocks);
    } catch (\Throwable $error) {
      Calendar_Repository::mark_busy_source((int) $source->id, $error->getMessage());
      throw $error;
    }
  }

  public static function ranges_for_local_date(int $resource_id, string $date, string $timezone): array {
    if (!$resource_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    try { $zone = new \DateTimeZone($timezone ?: 'UTC'); } catch (\Throwable $error) { $zone = new \DateTimeZone('UTC'); }
    $utc = new \DateTimeZone('UTC');
    $local_start = new \DateTimeImmutable($date . ' 00:00:00', $zone);
    $local_end = $local_start->modify('+1 day');
    $start_utc = $local_start->setTimezone($utc)->format('Y-m-d H:i:s');
    $end_utc = $local_end->setTimezone($utc)->format('Y-m-d H:i:s');
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT starts_at_utc, ends_at_utc FROM ' . Calendar_Repository::busy_blocks_table() . ' WHERE resource_id = %d AND starts_at_utc < %s AND ends_at_utc > %s ORDER BY starts_at_utc',
      $resource_id,
      $end_utc,
      $start_utc
    )) ?: [];
    return array_map(static function($row) use ($utc, $zone): array {
      $start = (new \DateTimeImmutable((string) $row->starts_at_utc, $utc))->setTimezone($zone);
      $end = (new \DateTimeImmutable((string) $row->ends_at_utc, $utc))->setTimezone($zone);
      return ['start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),'label'=>__('Unavailable','koopo-appointments'),'source'=>'external_calendar'];
    }, $rows);
  }

  public static function has_conflict(int $resource_id, string $start, string $end, string $timezone): bool {
    if (!$resource_id) return false;
    try { $zone = new \DateTimeZone($timezone ?: 'UTC'); } catch (\Throwable $error) { $zone = new \DateTimeZone('UTC'); }
    try {
      $utc = new \DateTimeZone('UTC');
      $start_utc = (new \DateTimeImmutable($start, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
      $end_utc = (new \DateTimeImmutable($end, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
    } catch (\Throwable $error) { return false; }
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . Calendar_Repository::busy_blocks_table() . ' WHERE resource_id = %d AND starts_at_utc < %s AND ends_at_utc > %s LIMIT 1',
      $resource_id,
      $end_utc,
      $start_utc
    ));
  }
}
