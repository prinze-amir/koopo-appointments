<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 22: Order Display Integration
 * Adds formatted booking details to WooCommerce order pages
 */
class Order_Display {

  public static function init(): void {
    // Add booking details to thank you page
    add_action('woocommerce_thankyou', [__CLASS__, 'display_booking_details'], 10, 1);
    
    // Add booking details to view order page (My Account)
    add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'display_booking_details'], 10, 1);
    
    // Add booking details to admin order screen
    add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_booking_details'], 10, 1);
    
    // Add booking info to order item meta display
    add_filter('woocommerce_order_item_display_meta_key', [__CLASS__, 'format_meta_key'], 10, 3);
    add_filter('woocommerce_order_item_display_meta_value', [__CLASS__, 'format_meta_value'], 10, 3);
  }

  /**
   * Display booking details on order pages (customer-facing)
   */
  public static function display_booking_details($order): void {
    
    if (is_numeric($order)) {
      $order = wc_get_order($order);
    }
    
    if (!$order) {
      return;
    }

    // Get booking for this order
    global $wpdb;
    $table = DB::table();
    $order_id = $order->get_id();
    
    $booking = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1",
      $order_id
    ));

    if (!$booking) {
      return;
    }

    // Calculate duration
    $start_ts = strtotime($booking->start_datetime);
    $end_ts = strtotime($booking->end_datetime);
    $duration_minutes = ($end_ts - $start_ts) / 60;
    $booking->duration_minutes = (int) $duration_minutes;

    // Load template
    $template_path = KOOPO_APPT_PATH . 'templates/woocommerce/order/booking-details.php';
    
    if (file_exists($template_path)) {
      include $template_path;
    }
  }

  /**
   * Display booking details on admin order screen
   */
  public static function display_admin_booking_details(\WC_Order $order): void {
    
    global $wpdb;
    $table = DB::table();
    $order_id = $order->get_id();
    
    $booking = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1",
      $order_id
    ));

    if (!$booking) {
      return;
    }

    $start = Date_Formatter::format($booking->start_datetime, $booking->timezone ?? '', 'full');
    $end = Date_Formatter::format($booking->end_datetime, $booking->timezone ?? '', 'time');
    $relative = Date_Formatter::relative($booking->start_datetime, $booking->timezone ?? '');
    $status = ucfirst(str_replace('_', ' ', $booking->status));
    
    $service_title = get_the_title((int) $booking->service_id);
    $listing_title = get_the_title((int) $booking->listing_id);
    $customer_name = get_userdata((int) $booking->customer_id)->display_name ?? '';

    ?>
    <div class="koopo-admin-booking-details">
      <h3><?php esc_html_e('Appointment Details', 'koopo-appointments'); ?></h3>
      <p>
        <strong><?php esc_html_e('Service:', 'koopo-appointments'); ?></strong><br>
        <?php echo esc_html($service_title); ?>
        <?php if ($listing_title): ?>
          <br><small><?php echo esc_html($listing_title); ?></small>
        <?php endif; ?>
      </p>
      <p>
        <strong><?php esc_html_e('Customer:', 'koopo-appointments'); ?></strong><br>
        <?php echo esc_html($customer_name); ?>
      </p>
      <p>
        <strong><?php esc_html_e('Date & Time:', 'koopo-appointments'); ?></strong><br>
        <?php echo esc_html($start); ?> - <?php echo esc_html($end); ?>
        <?php if (!empty($booking->timezone)): ?>
          <br><small><?php echo esc_html($booking->timezone); ?></small>
        <?php endif; ?>
      </p>
      <p>
        <strong><?php esc_html_e('Status:', 'koopo-appointments'); ?></strong><br>
        <span class="koopo-admin-status koopo-admin-status--<?php echo esc_attr($booking->status); ?>">
          <?php echo esc_html($status); ?>
        </span>
      </p>
      <?php if ($booking->status === 'confirmed'): ?>
      <p>
        <strong><?php esc_html_e('Starts:', 'koopo-appointments'); ?></strong><br>
        <em><?php echo esc_html(ucfirst($relative)); ?></em>
      </p>
      <?php endif; ?>
      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=koopo-bookings&id=' . $booking->id)); ?>" 
           class="button button-secondary">
          <?php esc_html_e('View Booking Details', 'koopo-appointments'); ?>
        </a>
      </p>
    </div>
    <style>
      .koopo-admin-booking-details {
        padding: 15px;
        background: #f9f9f9;
        border: 1px solid #ddd;
        margin: 15px 0;
      }
      .koopo-admin-booking-details h3 {
        margin-top: 0;
      }
      .koopo-admin-status {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
      }
      .koopo-admin-status--confirmed {
        background: #e8f5e9;
        color: #2c7a3c;
      }
      .koopo-admin-status--pending_payment {
        background: #fff3cd;
        color: #856404;
      }
      .koopo-admin-status--cancelled,
      .koopo-admin-status--refunded {
        background: #ffecec;
        color: #d63638;
      }
    </style>
    <?php
  }

  /**
   * Format order item meta keys for display
   */
  public static function format_meta_key(string $display_key, $meta, $item): string {
    
    $key = $meta->key ?? '';
    
    $formatted_keys = [
      '_koopo_booking_id' => 'Booking ID',
      '_koopo_booking_start' => 'Appointment Date',
      '_koopo_booking_end' => 'Appointment End',
      '_koopo_booking_duration' => 'Duration',
      '_koopo_booking_timezone' => 'Timezone',
      '_koopo_service_id' => 'Service',
      '_koopo_listing_id' => 'Listing',
    ];

    return $formatted_keys[$key] ?? $display_key;
  }

  /**
   * Format order item meta values for display
   */
  public static function format_meta_value(string $display_value, $meta, $item): string {
    
    $key = $meta->key ?? '';
    $value = $meta->value ?? '';
    
    // Format datetime values
    if (in_array($key, ['_koopo_booking_start', '_koopo_booking_end'], true)) {
      $timezone = $item->get_meta('_koopo_booking_timezone', true);
      return Date_Formatter::format($value, $timezone, 'full');
    }
    
    // Format duration
    if ($key === '_koopo_booking_duration') {
      return Date_Formatter::format_duration((int) $value);
    }
    
    // Format service/listing (show title instead of ID)
    if ($key === '_koopo_service_id' || $key === '_koopo_listing_id') {
      $title = get_the_title((int) $value);
      return $title ?: $display_value;
    }

    return $display_value;
  }
}
