<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class DB {
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
      service_id VARCHAR(100) NULL,
      start_datetime DATETIME NOT NULL,
      end_datetime DATETIME NOT NULL,
      timezone VARCHAR(64) NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      currency VARCHAR(10) NOT NULL DEFAULT 'USD',
      status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
      wc_order_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY listing_id (listing_id),
      KEY customer_id (customer_id),
      KEY wc_order_id (wc_order_id),
      KEY status (status),
      KEY start_datetime (start_datetime),
      KEY listing_status_start (listing_id, status, start_datetime),
      KEY listing_start_end (listing_id, start_datetime, end_datetime)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }
}
