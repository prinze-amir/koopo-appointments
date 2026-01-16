<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 22: Order Display Integration
 * Adds formatted booking details to WooCommerce order pages
 */
class Order_Display {
  private static $rendered_orders = [];

  public static function init(): void {
    // Add booking details to thank you page
    add_action('woocommerce_thankyou', [__CLASS__, 'display_booking_details'], 10, 1);

    // Add booking details to view order page (My Account)
    add_action('woocommerce_order_details_before_order_table', [__CLASS__, 'display_booking_details'], 10, 1);
    // Hide "Order again" button for booking orders
    add_action('woocommerce_order_details_before_order_table', [__CLASS__, 'maybe_disable_order_again'], 0, 1);

    // Add booking details to admin order screen
    add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_booking_details'], 10, 1);

    // Add booking details to Dokan vendor order details
    add_action('dokan_order_detail_after_order_items', [__CLASS__, 'display_booking_details'], 10, 1);

    // Use plugin thankyou template for booking orders
    add_filter('woocommerce_locate_template', [__CLASS__, 'locate_wc_template'], 10, 3);

    // Add booking info to order item meta display
    add_filter('woocommerce_order_item_display_meta_key', [__CLASS__, 'format_meta_key'], 10, 3);
    add_filter('woocommerce_order_item_display_meta_value', [__CLASS__, 'format_meta_value'], 10, 3);

    // Add booking details to WooCommerce emails
    add_action('woocommerce_email_order_details', [__CLASS__, 'display_booking_details_in_email'], 15, 4);
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

    $order_id = (int) $order->get_id();
    if ($order_id && !empty(self::$rendered_orders[$order_id])) {
      return;
    }

    // Get booking for this order
    $booking = self::get_booking_for_order($order);
    if (!$booking) {
      return;
    }

    if ($order_id) {
      self::$rendered_orders[$order_id] = true;
    }

    // Calculate duration
    $start_ts = strtotime($booking->start_datetime);
    $end_ts = strtotime($booking->end_datetime);
    $duration_minutes = ($end_ts - $start_ts) / 60;
    $booking->duration_minutes = (int) $duration_minutes;

    $listing_id = (int) $booking->listing_id;
    $listing = $listing_id ? get_post($listing_id) : null;
    $business_name = $listing ? get_the_title($listing_id) : '';
    $business_url = $listing ? get_permalink($listing_id) : '';
    $business_logo = $listing ? get_the_post_thumbnail_url($listing_id, 'medium') : '';
    $business_email = self::get_listing_meta_value($listing_id, [
      'business_email', 'gd_email', 'geodir_email', 'geodir_contact_email', 'contact_email', 'email'
    ]);
    $business_phone = self::get_listing_meta_value($listing_id, [
      'business_phone', 'gd_phone', 'geodir_phone', 'contact_phone', 'phone'
    ]);
    $business_address = self::get_listing_meta_value($listing_id, [
      'address', 'gd_address', 'geodir_address', 'address_line1', 'street'
    ]);
    if (!$business_address) {
      $city = self::get_listing_meta_value($listing_id, ['city', 'gd_city', 'geodir_city']);
      $region = self::get_listing_meta_value($listing_id, ['region', 'gd_region', 'geodir_region', 'state']);
      $zip = self::get_listing_meta_value($listing_id, ['zip', 'postcode', 'gd_postcode', 'geodir_postcode']);
      $parts = array_filter([$city, $region, $zip]);
      if (!empty($parts)) {
        $business_address = implode(', ', $parts);
      }
    }

    // Load template
    $template_path = KOOPO_APPT_PATH . 'templates/woocommerce/order/booking-details-injected.php';
    
    if (file_exists($template_path)) {
      include $template_path;
    }
  }

  private static function get_listing_meta_value(int $listing_id, array $keys): string {
    if (!$listing_id) return '';
    foreach ($keys as $key) {
      $val = get_post_meta($listing_id, $key, true);
      if (!empty($val)) return (string) $val;
    }
    return '';
  }

  private static function get_booking_for_order($order) {
    if (is_numeric($order)) {
      $order = wc_get_order($order);
    }
    if (!$order) {
      return null;
    }

    global $wpdb;
    $table = DB::table();
    $order_id = (int) $order->get_id();

    $booking = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1",
      $order_id
    ));

    if (!$booking) {
      $booking_id = $order->get_meta('_koopo_booking_ids', true);
      if (is_array($booking_id)) {
        $booking_id = reset($booking_id);
      }
      if (!$booking_id) {
        foreach ($order->get_items() as $item) {
          $item_booking_id = $item->get_meta('_koopo_booking_id', true);
          if ($item_booking_id) {
            $booking_id = $item_booking_id;
            break;
          }
        }
      }
      if ($booking_id) {
        $booking = $wpdb->get_row($wpdb->prepare(
          "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
          (int) $booking_id
        ));
      }
    }

    return $booking ?: null;
  }

  private static function order_has_booking($order_id): bool {
    if (!$order_id) {
      return false;
    }
    $booking = self::get_booking_for_order($order_id);
    return !empty($booking);
  }

  public static function locate_wc_template(string $template, string $template_name, string $template_path): string {
    if ($template_name !== 'checkout/thankyou.php') {
      return $template;
    }

    $order_id = absint(get_query_var('order-received'));
    if (!$order_id && !empty($_GET['key'])) {
      $order_id = wc_get_order_id_by_order_key(wc_clean(wp_unslash($_GET['key'])));
    }
    if (!$order_id || !self::order_has_booking($order_id)) {
      return $template;
    }

    $plugin_template = KOOPO_APPT_PATH . 'templates/woocommerce/checkout/thankyou.php';
    if (file_exists($plugin_template)) {
      return $plugin_template;
    }

    return $template;
  }

  public static function maybe_disable_order_again($order): void {
    if (is_numeric($order)) {
      $order = wc_get_order($order);
    }
    if (!$order) {
      return;
    }
    if (self::order_has_booking($order->get_id())) {
      remove_action('woocommerce_order_details_after_order_table', 'woocommerce_order_again_button', 10);
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

  /**
   * Display booking details in WooCommerce emails
   */
  public static function display_booking_details_in_email($order, $sent_to_admin = false, $plain_text = false, $email = null): void {

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

    // Load email template
    $template_path = KOOPO_APPT_PATH . 'templates/woocommerce/emails/booking-details.php';

    if (file_exists($template_path)) {
      include $template_path;
    }
  }
}
