<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Admin Management Dashboard
 * Centralized admin control with bulk actions and system overview
 */
class Admin_Dashboard {

  public static function init(): void {
    // Admin menu
    add_action('admin_menu', [__CLASS__, 'add_menu'], 20);
    
    // Admin assets
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    
    // AJAX handlers for bulk actions
    add_action('wp_ajax_koopo_bulk_action', [__CLASS__, 'handle_bulk_action']);
    
    // Register REST routes for admin API
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  /**
   * Add admin menu pages
   */
  public static function add_menu(): void {
    
    // Main dashboard
    add_menu_page(
      __('Koopo Appointments', 'koopo-appointments'),
      __('Appointments', 'koopo-appointments'),
      'manage_options',
      'koopo-appointments',
      [__CLASS__, 'render_dashboard'],
      'dashicons-calendar-alt',
      56
    );

    // Bookings submenu
    add_submenu_page(
      'koopo-appointments',
      __('All Bookings', 'koopo-appointments'),
      __('All Bookings', 'koopo-appointments'),
      'manage_options',
      'koopo-appointments-bookings',
      [__CLASS__, 'render_bookings']
    );

    // Analytics submenu
    add_submenu_page(
      'koopo-appointments',
      __('Analytics', 'koopo-appointments'),
      __('Analytics', 'koopo-appointments'),
      'manage_options',
      'koopo-appointments-analytics',
      [__CLASS__, 'render_analytics']
    );

    // Settings submenu (redirect to existing)
    add_submenu_page(
      'koopo-appointments',
      __('Settings', 'koopo-appointments'),
      __('Settings', 'koopo-appointments'),
      'manage_options',
      'koopo-appointments-settings',
      [__CLASS__, 'render_settings']
    );
  }

  /**
   * Enqueue admin assets
   */
  public static function enqueue_assets($hook): void {
    
    // Only load on our admin pages
    if (strpos($hook, 'koopo-appointments') === false) {
      return;
    }

    wp_enqueue_style(
      'koopo-admin-dashboard',
      KOOPO_APPT_URL . 'assets/admin-dashboard.css',
      [],
      KOOPO_APPT_VERSION
    );

    wp_enqueue_script(
      'koopo-admin-dashboard',
      KOOPO_APPT_URL . 'assets/admin-dashboard.js',
      ['jquery', 'wp-util'],
      KOOPO_APPT_VERSION,
      true
    );

    wp_localize_script('koopo-admin-dashboard', 'KOOPO_ADMIN', [
      'api_url' => rest_url('koopo/v1'),
      'nonce' => wp_create_nonce('wp_rest'),
      'ajax_url' => admin_url('admin-ajax.php'),
      'ajax_nonce' => wp_create_nonce('koopo_admin_action'),
      'i18n' => [
        'confirm_delete' => __('Are you sure you want to delete the selected bookings?', 'koopo-appointments'),
        'confirm_cancel' => __('Are you sure you want to cancel the selected bookings?', 'koopo-appointments'),
        'bulk_success' => __('Bulk action completed successfully.', 'koopo-appointments'),
        'bulk_error' => __('Bulk action failed. Please try again.', 'koopo-appointments'),
      ],
    ]);
  }

  /**
   * Register REST API routes
   */
  public static function register_routes(): void {
    
    // Admin statistics
    register_rest_route('koopo/v1', '/admin/stats', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_stats'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
    ]);

    // Admin bookings list
    register_rest_route('koopo/v1', '/admin/bookings', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_bookings'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
    ]);

    // Export bookings
    register_rest_route('koopo/v1', '/admin/export', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'export_bookings'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
    ]);
  }

  /**
   * Get system statistics
   */
  public static function get_stats(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    // Today's stats
    $today = current_time('Y-m-d');
    $today_bookings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE DATE(start_datetime) = %s",
      $today
    ));

    // This week's stats
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_bookings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE DATE(start_datetime) >= %s",
      $week_start
    ));

    // Status breakdown
    $status_counts = $wpdb->get_results(
      "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
      ARRAY_A
    );

    // Revenue stats
    $revenue_today = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(price) FROM {$table} WHERE status = 'confirmed' AND DATE(created_at) = %s",
      $today
    ));

    $revenue_week = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(price) FROM {$table} WHERE status = 'confirmed' AND DATE(created_at) >= %s",
      $week_start
    ));

    // Upcoming bookings
    $upcoming = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND start_datetime > %s",
      current_time('mysql')
    ));

    // Conflicts needing resolution
    $conflicts = $wpdb->get_var(
      "SELECT COUNT(*) FROM {$table} WHERE status = 'conflict'"
    );

    return rest_ensure_response([
      'today' => [
        'bookings' => (int) $today_bookings,
        'revenue' => (float) ($revenue_today ?? 0),
      ],
      'week' => [
        'bookings' => (int) $week_bookings,
        'revenue' => (float) ($revenue_week ?? 0),
      ],
      'status_breakdown' => $status_counts,
      'upcoming' => (int) $upcoming,
      'conflicts' => (int) $conflicts,
    ]);
  }

  /**
   * Get bookings for admin list
   */
  public static function get_bookings(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $page = max(1, (int) $request->get_param('page'));
    $per_page = min(100, max(10, (int) $request->get_param('per_page')));
    $status = sanitize_text_field((string) $request->get_param('status'));
    $search = sanitize_text_field((string) $request->get_param('search'));
    $date_from = sanitize_text_field((string) $request->get_param('date_from'));
    $date_to = sanitize_text_field((string) $request->get_param('date_to'));
    
    // Sorting parameters
    $orderby = sanitize_key($request->get_param('orderby') ?? '');
    $order = strtoupper(sanitize_key($request->get_param('order') ?? 'ASC'));
    $order = in_array($order, ['ASC', 'DESC']) ? $order : 'ASC';

    $where = '1=1';
    $params = [];

    if ($status && $status !== 'all') {
      $where .= ' AND status = %s';
      $params[] = $status;
    }

    if ($search) {
      $where .= ' AND (id = %d OR customer_id = %d)';
      $search_int = (int) $search;
      $params[] = $search_int;
      $params[] = $search_int;
    }

    if ($date_from) {
      $where .= ' AND DATE(start_datetime) >= %s';
      $params[] = $date_from;
    }

    if ($date_to) {
      $where .= ' AND DATE(start_datetime) <= %s';
      $params[] = $date_to;
    }

    $sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $total = (int) $wpdb->get_var($params ? $wpdb->prepare($sql_count, $params) : $sql_count);

    // Build ORDER BY clause
    $order_clause = 'ORDER BY created_at DESC'; // Default
    
    if ($orderby === 'date') {
      $order_clause = "ORDER BY start_datetime {$order}";
    } elseif ($orderby === 'customer') {
      // For customer name, we need to join with users table
      $order_clause = "ORDER BY (SELECT display_name FROM {$wpdb->users} WHERE ID = {$table}.customer_id) {$order}";
    } elseif ($orderby === 'vendor') {
      // For vendor name, we need to join with users table  
      $order_clause = "ORDER BY (SELECT display_name FROM {$wpdb->users} WHERE ID = {$table}.listing_author_id) {$order}";
    } elseif ($orderby === 'created') {
      $order_clause = "ORDER BY created_at {$order}";
    }

    $offset = ($page - 1) * $per_page;
    $sql_items = "SELECT * FROM {$table} WHERE {$where} {$order_clause} LIMIT %d OFFSET %d";
    $params[] = $per_page;
    $params[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql_items, $params), ARRAY_A);

    $items = [];
    foreach ($rows as $row) {
      $items[] = self::format_booking_for_admin($row);
    }

    return rest_ensure_response([
      'items' => $items,
      'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => (int) ceil($total / $per_page),
      ],
    ]);
  }

  /**
   * Format booking for admin display
   */
  private static function format_booking_for_admin(array $row): array {
    
    $customer = get_userdata((int) $row['customer_id']);
    $service_title = get_the_title((int) $row['service_id']);
    $listing_title = get_the_title((int) $row['listing_id']);
    $vendor = get_userdata((int) $row['listing_author_id']);

    $tz = $row['timezone'] ?? '';
    $start_formatted = Date_Formatter::format($row['start_datetime'], $tz, 'full');
    $duration_mins = (strtotime($row['end_datetime']) - strtotime($row['start_datetime'])) / 60;
    $duration_formatted = Date_Formatter::format_duration((int) $duration_mins);
    
    // Format created date
    $created_timestamp = strtotime($row['created_at']);
    $created_formatted = Date_Formatter::format($row['created_at'], $tz, 'full');
    $created_relative = human_time_diff($created_timestamp, current_time('timestamp')) . ' ago';

    return [
      'id' => (int) $row['id'],
      'customer_name' => $customer ? $customer->display_name : '',
      'customer_email' => $customer ? $customer->user_email : '',
      'vendor_name' => $vendor ? $vendor->display_name : '',
      'listing_title' => $listing_title,
      'service_title' => $service_title,
      'start_datetime' => $row['start_datetime'],
      'start_formatted' => $start_formatted,
      'duration_formatted' => $duration_formatted,
      'status' => $row['status'],
      'price' => (float) $row['price'],
      'currency' => $row['currency'] ?? 'USD',
      'wc_order_id' => (int) ($row['wc_order_id'] ?? 0),
      'created_at' => $row['created_at'],
      'created_formatted' => $created_formatted,
      'created_relative' => $created_relative,
    ];
  }

  /**
   * Handle bulk actions via AJAX
   */
  public static function handle_bulk_action(): void {
    
    check_ajax_referer('koopo_admin_action', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    $action = sanitize_key($_POST['bulk_action'] ?? '');
    $booking_ids = array_map('intval', $_POST['booking_ids'] ?? []);

    if (!$action || empty($booking_ids)) {
      wp_send_json_error(['message' => 'Invalid request'], 400);
    }

    $results = [
      'success' => [],
      'failed' => [],
    ];

    foreach ($booking_ids as $booking_id) {
      $result = false;

      switch ($action) {
        case 'cancel':
          $res = Bookings::cancel_booking_safely($booking_id, 'cancelled');
          $result = $res['ok'] ?? false;
          break;

        case 'confirm':
          $res = Bookings::confirm_booking_safely($booking_id);
          $result = $res['ok'] ?? false;
          break;

        case 'delete':
          global $wpdb;
          $result = $wpdb->delete(DB::table(), ['id' => $booking_id], ['%d']);
          break;
      }

      if ($result) {
        $results['success'][] = $booking_id;
      } else {
        $results['failed'][] = $booking_id;
      }
    }

    wp_send_json_success([
      'message' => sprintf(
        'Processed %d bookings. Success: %d, Failed: %d',
        count($booking_ids),
        count($results['success']),
        count($results['failed'])
      ),
      'results' => $results,
    ]);
  }

  /**
   * Export bookings to CSV
   */
  public static function export_bookings(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $status = sanitize_text_field((string) $request->get_param('status'));
    $date_from = sanitize_text_field((string) $request->get_param('date_from'));
    $date_to = sanitize_text_field((string) $request->get_param('date_to'));

    $where = '1=1';
    $params = [];

    if ($status && $status !== 'all') {
      $where .= ' AND status = %s';
      $params[] = $status;
    }

    if ($date_from) {
      $where .= ' AND DATE(start_datetime) >= %s';
      $params[] = $date_from;
    }

    if ($date_to) {
      $where .= ' AND DATE(start_datetime) <= %s';
      $params[] = $date_to;
    }

    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY start_datetime DESC LIMIT 10000";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    // Generate CSV
    $csv_data = [];
    $csv_data[] = [
      'Booking ID',
      'Customer',
      'Customer Email',
      'Vendor',
      'Listing',
      'Service',
      'Start Date/Time',
      'Duration',
      'Status',
      'Price',
      'Order ID',
      'Created',
    ];

    foreach ($rows as $row) {
      $formatted = self::format_booking_for_admin($row);
      $csv_data[] = [
        $formatted['id'],
        $formatted['customer_name'],
        $formatted['customer_email'],
        $formatted['vendor_name'],
        $formatted['listing_title'],
        $formatted['service_title'],
        $formatted['start_formatted'],
        $formatted['duration_formatted'],
        $formatted['status'],
        $formatted['price'],
        $formatted['wc_order_id'],
        $formatted['created_at'],
      ];
    }

    return rest_ensure_response([
      'csv' => $csv_data,
      'filename' => 'koopo-bookings-' . date('Y-m-d') . '.csv',
    ]);
  }

  /**
   * Render dashboard page
   */
  public static function render_dashboard(): void {
    include KOOPO_APPT_PATH . 'templates/admin/dashboard.php';
  }

  /**
   * Render bookings page
   */
  public static function render_bookings(): void {
    include KOOPO_APPT_PATH . 'templates/admin/bookings.php';
  }

  /**
   * Render analytics page
   */
  public static function render_analytics(): void {
    include KOOPO_APPT_PATH . 'templates/admin/analytics.php';
  }

  /**
   * Render settings page (redirect to existing)
   */
  public static function render_settings(): void {
    // Redirect to existing settings
    wp_redirect(admin_url('options-general.php?page=koopo-appointments-settings'));
    exit;
  }
}
