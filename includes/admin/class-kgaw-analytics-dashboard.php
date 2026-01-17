<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Analytics Dashboard
 * Business insights and reporting
 */
class Analytics_Dashboard {

  public static function init(): void {
    // Register REST routes
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  /**
   * Register analytics API routes
   */
  public static function register_routes(): void {
    
    // Overview metrics
    register_rest_route('koopo/v1', '/analytics/overview', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_overview'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
      'args' => [
        'period' => ['default' => '30'],
      ],
    ]);

    // Revenue analytics
    register_rest_route('koopo/v1', '/analytics/revenue', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_revenue'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
      'args' => [
        'period' => ['default' => '30'],
        'groupby' => ['default' => 'day'],
      ],
    ]);

    // Popular services
    register_rest_route('koopo/v1', '/analytics/popular-services', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_popular_services'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
      'args' => [
        'period' => ['default' => '30'],
        'limit' => ['default' => '10'],
      ],
    ]);

    // Top vendors
    register_rest_route('koopo/v1', '/analytics/top-vendors', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_top_vendors'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
      'args' => [
        'period' => ['default' => '30'],
        'limit' => ['default' => '10'],
      ],
    ]);

    // Booking trends
    register_rest_route('koopo/v1', '/analytics/trends', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_trends'],
      'permission_callback' => function() {
        return current_user_can('manage_options');
      },
      'args' => [
        'period' => ['default' => '30'],
      ],
    ]);
  }

  /**
   * Get overview metrics
   */
  public static function get_overview(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $period = (int) $request->get_param('period');
    $date_from = date('Y-m-d', strtotime("-{$period} days"));

    // Total bookings
    $total_bookings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) >= %s",
      $date_from
    ));

    // Confirmed bookings
    $confirmed_bookings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND DATE(created_at) >= %s",
      $date_from
    ));

    // Total revenue
    $total_revenue = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(price) FROM {$table} WHERE status IN ('confirmed', 'completed') AND DATE(created_at) >= %s",
      $date_from
    ));

    // Average booking value
    $avg_booking = $total_bookings > 0 ? ($total_revenue / $total_bookings) : 0;

    // Cancellation rate
    $cancelled = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status IN ('cancelled', 'refunded') AND DATE(created_at) >= %s",
      $date_from
    ));
    $cancellation_rate = $total_bookings > 0 ? ($cancelled / $total_bookings) * 100 : 0;

    // No-show rate (expired bookings)
    $no_shows = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status = 'expired' AND DATE(created_at) >= %s",
      $date_from
    ));
    $no_show_rate = $total_bookings > 0 ? ($no_shows / $total_bookings) * 100 : 0;

    // Unique customers
    $unique_customers = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT customer_id) FROM {$table} WHERE DATE(created_at) >= %s",
      $date_from
    ));

    // Active vendors
    $active_vendors = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT listing_author_id) FROM {$table} WHERE DATE(created_at) >= %s",
      $date_from
    ));

    // Compare with previous period
    $prev_date_from = date('Y-m-d', strtotime("-" . ($period * 2) . " days"));
    $prev_date_to = date('Y-m-d', strtotime("-{$period} days"));

    $prev_bookings = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) BETWEEN %s AND %s",
      $prev_date_from,
      $prev_date_to
    ));

    $prev_revenue = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(price) FROM {$table} WHERE status IN ('confirmed', 'completed') AND DATE(created_at) BETWEEN %s AND %s",
      $prev_date_from,
      $prev_date_to
    ));

    $bookings_change = $prev_bookings > 0 ? (($total_bookings - $prev_bookings) / $prev_bookings) * 100 : 0;
    $revenue_change = $prev_revenue > 0 ? (($total_revenue - $prev_revenue) / $prev_revenue) * 100 : 0;

    return rest_ensure_response([
      'period_days' => $period,
      'metrics' => [
        'total_bookings' => (int) $total_bookings,
        'confirmed_bookings' => (int) $confirmed_bookings,
        'total_revenue' => (float) ($total_revenue ?? 0),
        'avg_booking_value' => (float) $avg_booking,
        'cancellation_rate' => round($cancellation_rate, 2),
        'no_show_rate' => round($no_show_rate, 2),
        'unique_customers' => (int) $unique_customers,
        'active_vendors' => (int) $active_vendors,
      ],
      'changes' => [
        'bookings_change_percent' => round($bookings_change, 2),
        'revenue_change_percent' => round($revenue_change, 2),
      ],
    ]);
  }

  /**
   * Get revenue analytics
   */
  public static function get_revenue(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $period = (int) $request->get_param('period');
    $groupby = sanitize_key($request->get_param('groupby'));
    $date_from = date('Y-m-d', strtotime("-{$period} days"));

    $date_format = $groupby === 'week' ? '%Y-%U' : '%Y-%m-%d';

    $data = $wpdb->get_results($wpdb->prepare(
      "SELECT 
        DATE_FORMAT(created_at, %s) as period,
        COUNT(*) as bookings,
        SUM(CASE WHEN status IN ('confirmed', 'completed') THEN price ELSE 0 END) as revenue,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status = 'refunded' THEN price ELSE 0 END) as refunded
       FROM {$table}
       WHERE DATE(created_at) >= %s
       GROUP BY period
       ORDER BY period ASC",
      $date_format,
      $date_from
    ), ARRAY_A);

    return rest_ensure_response([
      'period' => $period,
      'groupby' => $groupby,
      'data' => $data,
    ]);
  }

  /**
   * Get popular services
   */
  public static function get_popular_services(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $period = (int) $request->get_param('period');
    $limit = min(50, (int) $request->get_param('limit'));
    $date_from = date('Y-m-d', strtotime("-{$period} days"));

    $data = $wpdb->get_results($wpdb->prepare(
      "SELECT 
        service_id,
        COUNT(*) as bookings,
        SUM(CASE WHEN status IN ('confirmed', 'completed') THEN price ELSE 0 END) as revenue,
        AVG(price) as avg_price
       FROM {$table}
       WHERE DATE(created_at) >= %s
       GROUP BY service_id
       ORDER BY bookings DESC
       LIMIT %d",
      $date_from,
      $limit
    ), ARRAY_A);

    // Add service titles
    foreach ($data as &$row) {
      $row['service_title'] = get_the_title((int) $row['service_id']);
      $row['bookings'] = (int) $row['bookings'];
      $row['revenue'] = (float) $row['revenue'];
      $row['avg_price'] = (float) $row['avg_price'];
    }

    return rest_ensure_response($data);
  }

  /**
   * Get top vendors
   */
  public static function get_top_vendors(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $period = (int) $request->get_param('period');
    $limit = min(50, (int) $request->get_param('limit'));
    $date_from = date('Y-m-d', strtotime("-{$period} days"));

    $data = $wpdb->get_results($wpdb->prepare(
      "SELECT 
        listing_author_id as vendor_id,
        COUNT(*) as bookings,
        SUM(CASE WHEN status IN ('confirmed', 'completed') THEN price ELSE 0 END) as revenue,
        COUNT(DISTINCT customer_id) as unique_customers
       FROM {$table}
       WHERE DATE(created_at) >= %s
       GROUP BY listing_author_id
       ORDER BY revenue DESC
       LIMIT %d",
      $date_from,
      $limit
    ), ARRAY_A);

    // Add vendor names
    foreach ($data as &$row) {
      $vendor = get_userdata((int) $row['vendor_id']);
      $row['vendor_name'] = $vendor ? $vendor->display_name : 'Unknown';
      $row['bookings'] = (int) $row['bookings'];
      $row['revenue'] = (float) $row['revenue'];
      $row['unique_customers'] = (int) $row['unique_customers'];
    }

    return rest_ensure_response($data);
  }

  /**
   * Get booking trends
   */
  public static function get_trends(\WP_REST_Request $request) {
    global $wpdb;
    $table = DB::table();

    $period = (int) $request->get_param('period');
    $date_from = date('Y-m-d', strtotime("-{$period} days"));

    // Peak hours
    $peak_hours = $wpdb->get_results($wpdb->prepare(
      "SELECT 
        HOUR(start_datetime) as hour,
        COUNT(*) as bookings
       FROM {$table}
       WHERE DATE(created_at) >= %s
       GROUP BY hour
       ORDER BY bookings DESC
       LIMIT 5",
      $date_from
    ), ARRAY_A);

    // Peak days of week
    $peak_days = $wpdb->get_results($wpdb->prepare(
      "SELECT 
        DAYOFWEEK(start_datetime) as day_num,
        COUNT(*) as bookings
       FROM {$table}
       WHERE DATE(created_at) >= %s
       GROUP BY day_num
       ORDER BY bookings DESC",
      $date_from
    ), ARRAY_A);

    $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    foreach ($peak_days as &$day) {
      $day['day_name'] = $day_names[$day['day_num'] - 1];
    }

    // Average lead time (days between booking creation and appointment)
    $avg_lead_time = $wpdb->get_var($wpdb->prepare(
      "SELECT AVG(DATEDIFF(start_datetime, created_at)) as avg_days
       FROM {$table}
       WHERE DATE(created_at) >= %s AND status IN ('confirmed', 'pending_payment')",
      $date_from
    ));

    return rest_ensure_response([
      'peak_hours' => $peak_hours,
      'peak_days' => $peak_days,
      'avg_lead_time_days' => round((float) $avg_lead_time, 1),
    ]);
  }
}
