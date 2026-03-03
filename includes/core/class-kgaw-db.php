<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class DB {
  const VERSION = '1.2';

  public static function table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_gd_bookings';
  }

  public static function create_tables() {
    global $wpdb;

    $table = self::table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      listing_id BIGINT UNSIGNED NOT NULL,
      listing_author_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      customer_name VARCHAR(191) NOT NULL DEFAULT '',
      customer_email VARCHAR(191) NOT NULL DEFAULT '',
      customer_phone VARCHAR(64) NOT NULL DEFAULT '',
      customer_notes TEXT NULL,
      booking_for_other TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      addon_ids LONGTEXT NULL,
      service_id VARCHAR(100) NULL,
      start_datetime DATETIME NOT NULL,
      end_datetime DATETIME NOT NULL,
      timezone VARCHAR(64) NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      currency VARCHAR(10) NOT NULL DEFAULT 'USD',
      status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
      cancelled_by VARCHAR(30) NOT NULL DEFAULT '',
      cancel_reason TEXT NULL,
      refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      refund_status VARCHAR(30) NOT NULL DEFAULT '',
      review_invite_sent DATETIME NULL,
      wc_order_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY listing_id (listing_id),
      KEY listing_author_id (listing_author_id),
      KEY customer_id (customer_id),
      KEY wc_order_id (wc_order_id),
      KEY status (status),
      KEY start_datetime (start_datetime),
      KEY customer_email (customer_email),
      KEY customer_phone (customer_phone),
      KEY listing_author_start (listing_author_id, start_datetime),
      KEY customer_start (customer_id, start_datetime),
      KEY listing_status_start (listing_id, status, start_datetime),
      KEY listing_start_end (listing_id, start_datetime, end_datetime)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('koopo_appt_db_version', self::VERSION);
  }

  public static function maybe_upgrade(): void {
    $current = (string) get_option('koopo_appt_db_version', '');
    if ($current !== self::VERSION) {
      self::create_tables();
      self::migrate_legacy_booking_options();
    }
  }

  private static function extra_migration_fields(): array {
    return [
      'customer_name',
      'customer_email',
      'customer_phone',
      'customer_notes',
      'booking_for_other',
      'addon_ids',
      'cancelled_by',
      'cancel_reason',
      'refund_amount',
      'refund_status',
      'review_invite_sent',
    ];
  }

  private static function sanitize_migrated_value(string $key, $value) {
    switch ($key) {
      case 'booking_for_other':
        return !empty($value) ? 1 : 0;
      case 'refund_amount':
        return is_numeric($value) ? (float) $value : 0.0;
      case 'customer_email':
        return sanitize_email((string) $value);
      case 'customer_notes':
      case 'cancel_reason':
        return sanitize_textarea_field((string) $value);
      case 'review_invite_sent':
        $value = sanitize_text_field((string) $value);
        return $value !== '' ? $value : null;
      case 'addon_ids':
        if (is_array($value)) {
          return wp_json_encode(array_values(array_filter(array_map('absint', $value))));
        }
        return sanitize_text_field((string) $value);
      default:
        return sanitize_text_field((string) $value);
    }
  }

  private static function migration_format(string $key): string {
    if ($key === 'booking_for_other') return '%d';
    if ($key === 'refund_amount') return '%f';
    if ($key === 'review_invite_sent') return '%s';
    return '%s';
  }

  private static function migrate_legacy_booking_options(): void {
    global $wpdb;

    $table = self::table();
    $fields = self::extra_migration_fields();
    $last_id = 0;
    $batch_size = 250;

    while (true) {
      $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
        $last_id,
        $batch_size
      ));

      if (!$ids) {
        break;
      }

      $ids = array_values(array_filter(array_map('absint', $ids)));
      if (!$ids) {
        break;
      }
      $last_id = (int) max($ids);

      $option_names = [];
      foreach ($ids as $id) {
        foreach ($fields as $field) {
          $option_names[] = "koopo_booking_{$id}_{$field}";
        }
      }
      if (!$option_names) {
        continue;
      }

      $placeholders = implode(',', array_fill(0, count($option_names), '%s'));
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
          $option_names
        ),
        ARRAY_A
      ) ?: [];

      $mapped = [];
      foreach ($rows as $row) {
        $name = isset($row['option_name']) ? (string) $row['option_name'] : '';
        if (!$name) continue;
        if (!preg_match('/^koopo_booking_(\d+)_(.+)$/', $name, $m)) continue;
        $booking_id = (int) $m[1];
        $field = (string) $m[2];
        if (!in_array($field, $fields, true)) continue;
        if (!isset($mapped[$booking_id])) $mapped[$booking_id] = [];
        $mapped[$booking_id][$field] = $row['option_value'] ?? '';
      }

      foreach ($ids as $id) {
        if (empty($mapped[$id]) || !is_array($mapped[$id])) continue;
        $update = [];
        $format = [];
        foreach ($mapped[$id] as $field => $value) {
          $update[$field] = self::sanitize_migrated_value($field, $value);
          $format[] = self::migration_format($field);
        }
        if (!$update) continue;
        $wpdb->update($table, $update, ['id' => $id], $format, ['%d']);
      }

      // Remove migrated legacy option rows to prevent long-term options-table growth.
      $delete_placeholders = implode(',', array_fill(0, count($option_names), '%s'));
      $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN ({$delete_placeholders})",
        $option_names
      ));
    }
  }
}
