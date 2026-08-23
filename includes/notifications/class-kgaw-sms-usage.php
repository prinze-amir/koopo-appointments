<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Atomic, global SMS quota buckets. All periods and resets use UTC. */
final class SMS_Usage {
  const OPTION_PAUSED = 'koopo_appt_sms_paused';
  const OPTION_DAILY_LIMIT_ENABLED = 'koopo_appt_sms_daily_limit_enabled';
  const OPTION_DAILY_LIMIT = 'koopo_appt_sms_daily_limit';
  const OPTION_MONTHLY_LIMIT_ENABLED = 'koopo_appt_sms_monthly_limit_enabled';
  const OPTION_MONTHLY_LIMIT = 'koopo_appt_sms_monthly_limit';
  const DEFAULT_DAILY_LIMIT = 100;
  const DEFAULT_MONTHLY_LIMIT = 1500;

  public static function reserve() {
    global $wpdb;
    if (self::paused()) return new \WP_Error('sms_globally_paused', __('SMS delivery is paused by an administrator.', 'koopo-appointments'));
    $buckets = self::current_buckets();
    $table = DB::sms_usage_table();
    $wpdb->query('START TRANSACTION');
    try {
      foreach ($buckets as $bucket) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO {$table} (bucket_key,period_type,period_start,period_end,created_at,updated_at) VALUES (%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
          $bucket['key'], $bucket['type'], $bucket['start'], $bucket['end']
        ));
      }
      $keys = array_column($buckets, 'key');
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT bucket_key,id,period_type,period_start,period_end,reserved_count,sent_count,failed_count FROM {$table} WHERE bucket_key IN (%s,%s) ORDER BY bucket_key FOR UPDATE",
        $keys[0], $keys[1]
      ), OBJECT_K) ?: [];
      foreach ($buckets as $bucket) {
        $row = $rows[$bucket['key']] ?? null;
        if (!$row) throw new \RuntimeException('sms_usage_bucket_failed');
        $used = (int) $row->sent_count + (int) $row->reserved_count;
        if (self::limit_enabled($bucket['type']) && $used >= self::limit($bucket['type'])) {
          $wpdb->query('ROLLBACK');
          return new \WP_Error('sms_' . $bucket['type'] . '_limit_reached', sprintf(__('The global SMS %s limit has been reached.', 'koopo-appointments'), $bucket['type']));
        }
      }
      foreach ($keys as $key) {
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET reserved_count=reserved_count+1,updated_at=UTC_TIMESTAMP() WHERE bucket_key=%s", $key));
      }
      $wpdb->query('COMMIT');
      return ['bucket_keys'=>$keys, 'reserved_at'=>time()];
    } catch (\Throwable $error) {
      $wpdb->query('ROLLBACK');
      return new \WP_Error('sms_usage_reservation_failed', __('SMS quota could not be reserved.', 'koopo-appointments'));
    }
  }

  public static function finish(array $reservation, bool $accepted, bool $uncertain = false): void {
    global $wpdb;
    $keys = array_values(array_filter(array_map('sanitize_text_field', (array) ($reservation['bucket_keys'] ?? []))));
    if (count($keys) !== 2 || $uncertain) return;
    $table = DB::sms_usage_table();
    foreach ($keys as $key) {
      if ($accepted) {
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET reserved_count=GREATEST(reserved_count-1,0),sent_count=sent_count+1,updated_at=UTC_TIMESTAMP() WHERE bucket_key=%s", $key));
      } else {
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET reserved_count=GREATEST(reserved_count-1,0),failed_count=failed_count+1,updated_at=UTC_TIMESTAMP() WHERE bucket_key=%s", $key));
      }
    }
  }

  public static function usage(): array {
    global $wpdb;
    $table = DB::sms_usage_table();
    $out = [];
    foreach (self::current_buckets() as $bucket) {
      $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE bucket_key=%s", $bucket['key']));
      $sent = $row ? (int) $row->sent_count : 0;
      $reserved = $row ? (int) $row->reserved_count : 0;
      $limit = self::limit($bucket['type']);
      $out[$bucket['type']] = [
        'sent'=>$sent, 'reserved'=>$reserved, 'failed'=>$row ? (int) $row->failed_count : 0,
        'used'=>$sent+$reserved, 'limit'=>$limit, 'enabled'=>self::limit_enabled($bucket['type']),
        'remaining'=>self::limit_enabled($bucket['type']) ? max(0, $limit-$sent-$reserved) : null,
        'period_start'=>$bucket['start'], 'period_end'=>$bucket['end'],
      ];
    }
    return $out;
  }

  public static function paused(): bool { return '1' === (string) get_option(self::OPTION_PAUSED, '0'); }
  public static function limit_enabled(string $type): bool {
    $option = $type === 'daily' ? self::OPTION_DAILY_LIMIT_ENABLED : self::OPTION_MONTHLY_LIMIT_ENABLED;
    return '1' === (string) get_option($option, '1');
  }
  public static function limit(string $type): int {
    $option = $type === 'daily' ? self::OPTION_DAILY_LIMIT : self::OPTION_MONTHLY_LIMIT;
    $default = $type === 'daily' ? self::DEFAULT_DAILY_LIMIT : self::DEFAULT_MONTHLY_LIMIT;
    return max(1, min(10000000, absint(get_option($option, $default)) ?: $default));
  }

  private static function current_buckets(): array {
    $timestamp = (int) apply_filters('koopo_appt_sms_usage_timestamp', time());
    $now = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    $day_start = $now->setTime(0, 0, 0);
    $month_start = $day_start->modify('first day of this month');
    return [
      ['key'=>'daily:' . $day_start->format('Y-m-d'), 'type'=>'daily', 'start'=>$day_start->format('Y-m-d H:i:s'), 'end'=>$day_start->modify('+1 day')->format('Y-m-d H:i:s')],
      ['key'=>'monthly:' . $month_start->format('Y-m'), 'type'=>'monthly', 'start'=>$month_start->format('Y-m-d H:i:s'), 'end'=>$month_start->modify('+1 month')->format('Y-m-d H:i:s')],
    ];
  }
}
