<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Automated Reminders System
 * Email reminders to reduce no-shows
 */
class Automated_Reminders {

  public static function init(): void {
    // Schedule reminder check cron
    add_action('koopo_appt_send_reminders', [__CLASS__, 'send_reminders']);
    
    // Register cron schedules
    add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
    
    // Activate cron on init
    add_action('init', [__CLASS__, 'schedule_cron']);
    
    // Admin settings
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  /**
   * Add custom cron schedules
   */
  public static function add_cron_schedules($schedules): array {
    
    if (!isset($schedules['koopo_hourly'])) {
      $schedules['koopo_hourly'] = [
        'interval' => 3600,
        'display' => __('Every Hour (Koopo Reminders)', 'koopo-appointments'),
      ];
    }

    return $schedules;
  }

  /**
   * Schedule the cron job
   */
  public static function schedule_cron(): void {
    
    if (!wp_next_scheduled('koopo_appt_send_reminders')) {
      wp_schedule_event(time(), 'koopo_hourly', 'koopo_appt_send_reminders');
    }
  }

  /**
   * Send all due reminders
   */
  public static function send_reminders(): void {
    global $wpdb;
    $table = DB::table();

    // Get reminder settings
    $enabled = get_option('koopo_reminders_enabled', '1');
    if ($enabled !== '1') {
      return;
    }

    $reminder_hours = self::get_reminder_windows();
    
    foreach ($reminder_hours as $hours) {
      self::send_reminders_for_window($hours);
    }
  }

  /**
   * Get configured reminder windows
   */
  private static function get_reminder_windows(): array {
    
    $defaults = [24, 2]; // 24 hours before, 2 hours before
    $configured = get_option('koopo_reminder_windows', '');
    
    if ($configured) {
      $windows = array_map('intval', explode(',', $configured));
      return array_filter($windows, function($w) { return $w > 0; });
    }

    return $defaults;
  }

  /**
   * Send reminders for a specific time window
   */
  private static function send_reminders_for_window(int $hours_before): void {
    global $wpdb;
    $table = DB::table();

    // Calculate time window (with 30-minute buffer to avoid duplicates)
    $target_time = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours"));
    $buffer_start = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours - 15 minutes"));
    $buffer_end = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours + 15 minutes"));

    // Get bookings in this window
    $bookings = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$table} 
       WHERE status = 'confirmed' 
       AND start_datetime BETWEEN %s AND %s
       ORDER BY start_datetime ASC
       LIMIT 100",
      $buffer_start,
      $buffer_end
    ));

    foreach ($bookings as $booking) {
      // Check if reminder already sent for this window
      $reminder_key = "koopo_reminder_{$hours_before}h";
      $already_sent = get_post_meta($booking->wc_order_id, $reminder_key, true);
      
      if ($already_sent) {
        continue;
      }

      // Send reminder
      $sent = self::send_reminder_email($booking, $hours_before);
      
      if ($sent) {
        // Mark as sent
        if ($booking->wc_order_id) {
          update_post_meta($booking->wc_order_id, $reminder_key, current_time('mysql'));
        }
        
        // Log reminder
        do_action('koopo_reminder_sent', $booking->id, $hours_before);
      }
    }
  }

  /**
   * Send reminder email
   */
  private static function send_reminder_email(object $booking, int $hours_before): bool {
    
    $customer_id = (int) $booking->customer_id;
    $order_id = (int) ($booking->wc_order_id ?? 0);
    
    // Get customer email
    $customer_email = '';
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order) {
        $customer_email = $order->get_billing_email();
      }
    }
    
    if (!$customer_email) {
      $customer = get_userdata($customer_id);
      if ($customer) {
        $customer_email = $customer->user_email;
      }
    }

    if (!$customer_email) {
      return false;
    }

    // Format booking details
    $service_title = get_the_title((int) $booking->service_id);
    $listing_title = get_the_title((int) $booking->listing_id);
    $tz = $booking->timezone ?? '';
    
    $start_formatted = Date_Formatter::format($booking->start_datetime, $tz, 'full');
    $duration_mins = (strtotime($booking->end_datetime) - strtotime($booking->start_datetime)) / 60;
    $duration_formatted = Date_Formatter::format_duration((int) $duration_mins);

    // Get reminder template
    $template = self::get_reminder_template($hours_before);
    
    $subject = sprintf($template['subject'], $hours_before);
    
    $body = self::render_reminder_email([
      'hours_before' => $hours_before,
      'booking_id' => $booking->id,
      'service_title' => $service_title,
      'listing_title' => $listing_title,
      'start_formatted' => $start_formatted,
      'duration_formatted' => $duration_formatted,
      'order_id' => $order_id,
      'timezone' => $tz,
      'template' => $template,
    ]);

    // Send email
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $sent = wp_mail($customer_email, $subject, $body, $headers);

    return $sent;
  }

  /**
   * Get reminder email template
   */
  private static function get_reminder_template(int $hours_before): array {
    
    // Allow customization via filter
    $template = apply_filters('koopo_reminder_template', [
      'subject' => 'Reminder: Your appointment is in %d hours',
      'title' => 'Upcoming Appointment Reminder',
      'message' => 'This is a friendly reminder about your upcoming appointment.',
      'cta' => 'View Details',
    ], $hours_before);

    return $template;
  }

  /**
   * Render reminder email HTML
   */
  private static function render_reminder_email(array $data): string {
    
    $title = esc_html($data['template']['title']);
    $message = esc_html($data['template']['message']);
    $service = esc_html($data['service_title']);
    $listing = esc_html($data['listing_title']);
    $when = esc_html($data['start_formatted']);
    $duration = esc_html($data['duration_formatted']);
    $hours = (int) $data['hours_before'];
    
    $cta_url = '';
    if ($data['order_id']) {
      $cta_url = wc_get_endpoint_url('view-order', $data['order_id'], wc_get_page_permalink('myaccount'));
    }

    ob_start();
    ?>
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
      
      <!-- Header -->
      <div style="background: #2c7a3c; color: #fff; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0; font-size: 24px;">⏰ <?php echo $title; ?></h1>
      </div>

      <!-- Body -->
      <div style="background: #fff; padding: 30px 20px; border: 1px solid #e5e5e5; border-top: none;">
        
        <p style="font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
          <?php echo $message; ?>
        </p>

        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
          <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #333;">Appointment Details</h2>
          
          <table style="width: 100%; border-collapse: collapse;">
            <tr>
              <td style="padding: 8px 0; font-weight: 600; color: #666; width: 30%;">Service:</td>
              <td style="padding: 8px 0;"><?php echo $service; ?></td>
            </tr>
            <?php if ($listing): ?>
            <tr>
              <td style="padding: 8px 0; font-weight: 600; color: #666;">Location:</td>
              <td style="padding: 8px 0;"><?php echo $listing; ?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <td style="padding: 8px 0; font-weight: 600; color: #666;">When:</td>
              <td style="padding: 8px 0;"><strong><?php echo $when; ?></strong></td>
            </tr>
            <tr>
              <td style="padding: 8px 0; font-weight: 600; color: #666;">Duration:</td>
              <td style="padding: 8px 0;"><?php echo $duration; ?></td>
            </tr>
          </table>
        </div>

        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #f4b400; margin: 20px 0;">
          <p style="margin: 0; font-size: 14px; line-height: 1.5;">
            <strong>⏱️ In <?php echo $hours; ?> hour<?php echo $hours !== 1 ? 's' : ''; ?></strong><br>
            Please arrive on time. If you need to cancel or reschedule, please do so as soon as possible.
          </p>
        </div>

        <?php if ($cta_url): ?>
        <div style="text-align: center; margin: 30px 0;">
          <a href="<?php echo esc_url($cta_url); ?>" 
             style="display: inline-block; padding: 14px 30px; background: #2c7a3c; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
            <?php echo esc_html($data['template']['cta']); ?>
          </a>
        </div>
        <?php endif; ?>

      </div>

      <!-- Footer -->
      <div style="background: #f7f7f7; padding: 20px; text-align: center; border-radius: 0 0 8px 8px; border: 1px solid #e5e5e5; border-top: none;">
        <p style="margin: 0; font-size: 12px; color: #666; line-height: 1.5;">
          This is an automated reminder from Koopo Appointments.<br>
          Please do not reply to this email.
        </p>
      </div>

    </div>
    <?php
    return ob_get_clean();
  }

  /**
   * Register settings
   */
  public static function register_settings(): void {
    
    register_setting('koopo_appt_settings', 'koopo_reminders_enabled', [
      'type' => 'string',
      'default' => '1',
    ]);

    register_setting('koopo_appt_settings', 'koopo_reminder_windows', [
      'type' => 'string',
      'default' => '24,2',
    ]);

    add_settings_field(
      'koopo_reminders_enabled',
      __('Enable Reminders', 'koopo-appointments'),
      function() {
        $enabled = get_option('koopo_reminders_enabled', '1');
        ?>
        <label>
          <input type="checkbox" name="koopo_reminders_enabled" value="1" <?php checked($enabled, '1'); ?> />
          <?php _e('Send automatic email reminders before appointments', 'koopo-appointments'); ?>
        </label>
        <?php
      },
      'koopo-appointments-settings',
      'koopo_appt_general'
    );

    add_settings_field(
      'koopo_reminder_windows',
      __('Reminder Windows', 'koopo-appointments'),
      function() {
        $windows = get_option('koopo_reminder_windows', '24,2');
        ?>
        <input type="text" 
               name="koopo_reminder_windows" 
               value="<?php echo esc_attr($windows); ?>" 
               class="regular-text" 
               placeholder="24,2" />
        <p class="description">
          <?php _e('Hours before appointment to send reminders (comma-separated). Example: 24,2 sends reminders 24 hours and 2 hours before.', 'koopo-appointments'); ?>
        </p>
        <?php
      },
      'koopo-appointments-settings',
      'koopo_appt_general'
    );
  }
}
