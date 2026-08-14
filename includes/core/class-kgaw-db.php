<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class DB {
  const VERSION = '4.2';

  public static function table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_gd_bookings';
  }

  public static function listing_index_table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_listing_index';
  }

  public static function service_index_table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_service_index';
  }

  public static function resources_table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_resources';
  }

  public static function affiliations_table() {
    global $wpdb;
    return $wpdb->prefix . 'koopo_appt_affiliations';
  }

  public static function waitlist_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_waitlist'; }
  public static function waitlist_offers_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_waitlist_offers'; }
  public static function clients_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_clients'; }
  public static function client_forms_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_client_forms'; }
  public static function form_submissions_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_form_submissions'; }
  public static function client_files_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_client_files'; }
  public static function service_areas_table() { global $wpdb; return $wpdb->prefix . 'koopo_appt_service_areas'; }

  public static function create_tables() {
    global $wpdb;

    $table = self::table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      listing_id BIGINT UNSIGNED NULL,
      listing_author_id BIGINT UNSIGNED NOT NULL,
      provider_id BIGINT UNSIGNED NULL,
      resource_id BIGINT UNSIGNED NULL,
      location_id BIGINT UNSIGNED NULL,
      service_area_id BIGINT UNSIGNED NULL,
      payee_user_id BIGINT UNSIGNED NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      customer_name VARCHAR(191) NOT NULL DEFAULT '',
      customer_email VARCHAR(191) NOT NULL DEFAULT '',
      customer_phone VARCHAR(64) NOT NULL DEFAULT '',
      customer_notes TEXT NULL,
      fulfillment_mode VARCHAR(30) NOT NULL DEFAULT 'at_location',
      service_address_1 VARCHAR(191) NOT NULL DEFAULT '',
      service_address_2 VARCHAR(191) NOT NULL DEFAULT '',
      service_city VARCHAR(120) NOT NULL DEFAULT '',
      service_region VARCHAR(120) NOT NULL DEFAULT '',
      service_postal_code VARCHAR(32) NOT NULL DEFAULT '',
      service_country VARCHAR(120) NOT NULL DEFAULT '',
      service_latitude DECIMAL(10,7) NULL,
      service_longitude DECIMAL(10,7) NULL,
      travel_buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0,
      virtual_provider VARCHAR(30) NOT NULL DEFAULT '',
      virtual_join_url TEXT NULL,
      virtual_instructions TEXT NULL,
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
      KEY provider_id (provider_id),
      KEY service_area_id (service_area_id),
      KEY resource_status_start (resource_id, status, start_datetime),
      KEY payee_start (payee_user_id, start_datetime),
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

    $resources = self::resources_table();
    $resources_sql = "CREATE TABLE {$resources} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      payee_user_id BIGINT UNSIGNED NOT NULL,
      subject_type VARCHAR(30) NOT NULL,
      subject_id BIGINT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY subject (subject_type, subject_id),
      KEY owner_status (owner_user_id, status),
      KEY payee_status (payee_user_id, status)
    ) {$charset};";

    $affiliations = self::affiliations_table();
    $affiliations_sql = "CREATE TABLE {$affiliations} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider_id BIGINT UNSIGNED NOT NULL,
      listing_id BIGINT UNSIGNED NOT NULL,
      requested_by BIGINT UNSIGNED NOT NULL,
      relationship_type VARCHAR(30) NOT NULL DEFAULT 'independent',
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      public_display TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY provider_listing (provider_id, listing_id),
      KEY listing_status (listing_id, status),
      KEY provider_status (provider_id, status)
    ) {$charset};";

    $listing_index = self::listing_index_table();
    $listing_sql = "CREATE TABLE {$listing_index} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      listing_id BIGINT UNSIGNED NOT NULL,
      listing_author_id BIGINT UNSIGNED NOT NULL,
      enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      service_count INT UNSIGNED NOT NULL DEFAULT 0,
      min_price DECIMAL(10,2) NULL,
      max_price DECIMAL(10,2) NULL,
      currency VARCHAR(10) NOT NULL DEFAULT 'USD',
      next_available_at DATETIME NULL,
      last_service_updated_at DATETIME NULL,
      last_booking_at DATETIME NULL,
      sort_score DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY listing_id (listing_id),
      KEY enabled_score (enabled, sort_score, updated_at),
      KEY author_enabled (listing_author_id, enabled),
      KEY next_available (enabled, next_available_at),
      KEY service_count (enabled, service_count)
    ) {$charset};";

    $service_index = self::service_index_table();
    $service_sql = "CREATE TABLE {$service_index} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      service_id BIGINT UNSIGNED NOT NULL,
      listing_id BIGINT UNSIGNED NULL,
      provider_id BIGINT UNSIGNED NULL,
      resource_id BIGINT UNSIGNED NULL,
      vendor_id BIGINT UNSIGNED NOT NULL,
      wc_product_id BIGINT UNSIGNED NULL,
      title VARCHAR(191) NOT NULL DEFAULT '',
      description TEXT NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      currency VARCHAR(10) NOT NULL DEFAULT 'USD',
      duration_minutes INT UNSIGNED NOT NULL DEFAULT 30,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      is_addon TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      instant TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
      color VARCHAR(20) NULL,
      category_ids TEXT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY service_id (service_id),
      KEY listing_status (listing_id, status, is_addon),
      KEY provider_status (provider_id, status, is_addon),
      KEY resource_status (resource_id, status, is_addon),
      KEY vendor_listing (vendor_id, listing_id),
      KEY price (price),
      KEY duration (duration_minutes)
    ) {$charset};";

    $waitlist = self::waitlist_table();
    $waitlist_sql = "CREATE TABLE {$waitlist} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      resource_id BIGINT UNSIGNED NOT NULL,
      listing_id BIGINT UNSIGNED NULL,
      provider_id BIGINT UNSIGNED NULL,
      service_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      customer_name VARCHAR(191) NOT NULL DEFAULT '',
      customer_email VARCHAR(191) NOT NULL DEFAULT '',
      customer_phone VARCHAR(64) NOT NULL DEFAULT '',
      fulfillment_mode VARCHAR(30) NOT NULL DEFAULT 'at_location',
      service_address_1 VARCHAR(191) NOT NULL DEFAULT '',
      service_address_2 VARCHAR(191) NOT NULL DEFAULT '',
      service_city VARCHAR(120) NOT NULL DEFAULT '',
      service_region VARCHAR(120) NOT NULL DEFAULT '',
      service_postal_code VARCHAR(32) NOT NULL DEFAULT '',
      service_country VARCHAR(120) NOT NULL DEFAULT '',
      date_from DATE NULL,
      date_to DATE NULL,
      preferred_days VARCHAR(80) NOT NULL DEFAULT '',
      earliest_time TIME NULL,
      latest_time TIME NULL,
      channels VARCHAR(100) NOT NULL DEFAULT 'email,push',
      priority INT UNSIGNED NOT NULL DEFAULT 100,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      provider_note TEXT NULL,
      last_notified_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY resource_status (resource_id, status, priority, created_at),
      KEY service_status (service_id, status),
      KEY customer_status (customer_id, status)
    ) {$charset};";

    $waitlist_offers = self::waitlist_offers_table();
    $waitlist_offers_sql = "CREATE TABLE {$waitlist_offers} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      waitlist_id BIGINT UNSIGNED NOT NULL,
      opening_booking_id BIGINT UNSIGNED NULL,
      resource_id BIGINT UNSIGNED NOT NULL,
      service_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      starts_at DATETIME NOT NULL,
      ends_at DATETIME NOT NULL,
      timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
      token_hash CHAR(64) NOT NULL,
      token_encrypted TEXT NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'offered',
      offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      accepted_at DATETIME NULL,
      booking_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY token_hash (token_hash),
      KEY waitlist_opening (waitlist_id, opening_booking_id),
      KEY opening_status (opening_booking_id, status, expires_at),
      KEY resource_slot (resource_id, starts_at, status)
    ) {$charset};";

    $clients = self::clients_table();
    $clients_sql = "CREATE TABLE {$clients} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      resource_id BIGINT UNSIGNED NOT NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      wp_user_id BIGINT UNSIGNED NULL,
      name VARCHAR(191) NOT NULL DEFAULT '',
      email VARCHAR(191) NOT NULL DEFAULT '',
      phone VARCHAR(64) NOT NULL DEFAULT '',
      birthday DATE NULL,
      preferences TEXT NULL,
      formulas TEXT NULL,
      private_notes LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY resource_user (resource_id, wp_user_id),
      KEY resource_email (resource_id, email),
      KEY owner_updated (owner_user_id, updated_at)
    ) {$charset};";

    $forms = self::client_forms_table();
    $forms_sql = "CREATE TABLE {$forms} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      resource_id BIGINT UNSIGNED NOT NULL,
      service_id BIGINT UNSIGNED NULL,
      title VARCHAR(191) NOT NULL,
      description TEXT NULL,
      fields_json LONGTEXT NOT NULL,
      requires_signature TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      send_hours_before SMALLINT UNSIGNED NOT NULL DEFAULT 24,
      enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY resource_enabled (resource_id, enabled),
      KEY service_enabled (service_id, enabled)
    ) {$charset};";

    $submissions = self::form_submissions_table();
    $submissions_sql = "CREATE TABLE {$submissions} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      form_id BIGINT UNSIGNED NOT NULL,
      booking_id BIGINT UNSIGNED NOT NULL,
      client_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      answers_json LONGTEXT NOT NULL,
      form_snapshot_json LONGTEXT NULL,
      consent_text TEXT NULL,
      signature_name VARCHAR(191) NOT NULL DEFAULT '',
      signature_hash CHAR(64) NOT NULL DEFAULT '',
      signer_ip_hash CHAR(64) NOT NULL DEFAULT '',
      user_agent_hash CHAR(64) NOT NULL DEFAULT '',
      signed_at DATETIME NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'completed',
      requested_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY booking_form (booking_id, form_id),
      KEY client_created (client_id, created_at),
      KEY customer_status (customer_id, status)
    ) {$charset};";

    $client_files = self::client_files_table();
    $client_files_sql = "CREATE TABLE {$client_files} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      resource_id BIGINT UNSIGNED NOT NULL,
      client_id BIGINT UNSIGNED NOT NULL,
      booking_id BIGINT UNSIGNED NULL,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      asset_id VARCHAR(191) NOT NULL,
      filename VARCHAR(255) NOT NULL,
      mime_type VARCHAR(100) NOT NULL,
      size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY asset_id (asset_id),
      KEY client_created (client_id, created_at),
      KEY resource_created (resource_id, created_at)
    ) {$charset};";

    $service_areas = self::service_areas_table();
    $service_areas_sql = "CREATE TABLE {$service_areas} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider_id BIGINT UNSIGNED NOT NULL,
      area_type VARCHAR(30) NOT NULL DEFAULT 'radius',
      origin_address VARCHAR(255) NOT NULL DEFAULT '',
      origin_city VARCHAR(120) NOT NULL DEFAULT '',
      origin_region VARCHAR(120) NOT NULL DEFAULT '',
      origin_postal_code VARCHAR(32) NOT NULL DEFAULT '',
      origin_country VARCHAR(120) NOT NULL DEFAULT '',
      origin_latitude DECIMAL(10,7) NOT NULL,
      origin_longitude DECIMAL(10,7) NOT NULL,
      radius_meters INT UNSIGNED NOT NULL DEFAULT 0,
      public_label VARCHAR(191) NOT NULL DEFAULT '',
      travel_buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY provider_id (provider_id),
      KEY provider_status (provider_id, status),
      KEY status_radius (status, radius_meters)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    dbDelta($resources_sql);
    dbDelta($affiliations_sql);
    dbDelta($listing_sql);
    dbDelta($service_sql);
    dbDelta($waitlist_sql);
    dbDelta($waitlist_offers_sql);
    dbDelta($clients_sql);
    dbDelta($forms_sql);
    dbDelta($submissions_sql);
    dbDelta($client_files_sql);
    dbDelta($service_areas_sql);
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
