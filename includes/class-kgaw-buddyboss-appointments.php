<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class BuddyBoss_Appointments {

  public static function init() {
    add_action('bp_setup_nav', [__CLASS__, 'add_nav'], 100);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
  }

  public static function add_nav() {
    if (!function_exists('bp_core_new_nav_item')) return;

    bp_core_new_nav_item([
      'name' => __('Appointments', 'koopo'),
      'slug' => 'appointments',
      'screen_function' => [__CLASS__, 'screen'],
      'position' => 55,
      'default_subnav_slug' => 'appointments',
      'show_for_displayed_user' => true,
    ]);
  }

  public static function screen() {
    add_action('bp_template_content', [__CLASS__, 'render']);
    bp_core_load_template('members/single/plugins');
  }

  public static function render() {
    $displayed_id = function_exists('bp_displayed_user_id') ? (int) bp_displayed_user_id() : 0;
    $viewer_id = get_current_user_id();

    if (!$displayed_id || $displayed_id !== $viewer_id) {
      echo '<p>You can only view your own appointments.</p>';
      return;
    }

    $rows = Bookings::get_bookings_for_customer($viewer_id, 50);

    echo '<div class="koopo-bb-appts">';
    echo '<h3>Your Appointments</h3>';

    if (!$rows) {
      echo '<p>No appointments yet.</p></div>';
      return;
    }

    echo '<table class="koopo-appts-table">';
    echo '<thead><tr>';
    echo '<th>Date & Time</th>';
    echo '<th>Business</th>';
    echo '<th>Service</th>';
    echo '<th>Duration</th>';
    echo '<th>Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $b) {
      $listing_title = get_the_title((int)$b->listing_id);
      $service_title = get_the_title((int)$b->service_id);

      // Format datetime with timezone
      $tz = !empty($b->timezone) ? (string)$b->timezone : '';
      $datetime_display = Date_Formatter::format((string)$b->start_datetime, $tz, 'relative');
      
      // Calculate duration
      $start_ts = strtotime((string)$b->start_datetime);
      $end_ts = strtotime((string)$b->end_datetime);
      $duration_mins = ($end_ts - $start_ts) / 60;
      $duration_display = Date_Formatter::format_duration((int)$duration_mins);

      // Status
      $status = (string)$b->status;
      $status_class = 'koopo-badge--' . sanitize_html_class($status);
      $status_label = ucfirst(str_replace('_', ' ', $status));

      // Actions
      $actions = '';
      if ($status === 'pending_payment') {
        $pay_url = MyAccount::pay_now_url((int)$b->id);
        $actions = '<a class="button" href="' . esc_url($pay_url) . '">' . esc_html__('Pay now', 'koopo') . '</a>';
      }

      if (Bookings::customer_can_cancel($b)) {
        $cancel_url = MyAccount::cancel_booking_url((int)$b->id);
        $cancel_btn = '<a class="button" href="' . esc_url($cancel_url) . '" onclick="return confirm(\'Cancel this booking?\');">' . esc_html__('Cancel', 'koopo') . '</a>';
        $actions = $actions ? ($actions . ' ' . $cancel_btn) : $cancel_btn;
      }

      echo '<tr>';
      echo '<td>' . esc_html($datetime_display) . '</td>';
      echo '<td>' . esc_html($listing_title) . '</td>';
      echo '<td>' . esc_html($service_title) . '</td>';
      echo '<td>' . esc_html($duration_display) . '</td>';
      echo '<td><span class="koopo-badge ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></td>';
      echo '<td>' . ($actions ?: '—') . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    if (Features::wc_subscriptions_active()) {
      echo '<p class="koopo-appts-links"><a href="' . esc_url(function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('subscriptions') : '#') . '">Manage subscriptions</a></p>';
    }

    echo '</div>';
  }

  public static function enqueue_styles() {
    wp_enqueue_style(
      'koopo-appt-badges',
      plugins_url('../assets/appointments-badges.css', __FILE__),
      [],
      '0.1.0'
    );
  }
}
