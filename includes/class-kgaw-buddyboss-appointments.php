<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class BuddyBoss_Appointments {

  public static function init() {
    // BuddyBoss runs on BuddyPress core APIs
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

    // Only allow user to view their own appointments (privacy)
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
    echo '<thead><tr><th>Date</th><th>Business</th><th>Service</th><th>Status</th><th>Type</th><th>Actions</th></tr></thead><tbody>';

    foreach ($rows as $b) {
      $listing_title = get_the_title((int)$b->listing_id);
      $service_title = get_the_title((int)$b->service_id);

      $status = (string)$b->status;
      $type = ($b->payment_type === 'membership') ? 'membership' : (($b->payment_type === 'free') ? 'free' : 'paid');

      
$actions = '';
if ($status === 'pending_payment') {
  $pay_url = MyAccount::pay_now_url((int) $b->id);
  $actions = '<a class="button" href="' . esc_url($pay_url) . '">' . esc_html__('Pay now', 'koopo') . '</a>';
}

if (Bookings::customer_can_cancel($b)) {
  $cancel_url = MyAccount::cancel_booking_url((int) $b->id);
  $cancel_btn = '<a class="button" href="' . esc_url($cancel_url) . '" onclick="return confirm(\'Cancel this booking?\');">' . esc_html__('Cancel', 'koopo') . '</a>';
  $actions = $actions ? ($actions . ' ' . $cancel_btn) : $cancel_btn;
}

      echo '<tr>';
      echo '<td>' . esc_html($b->start_datetime) . '</td>';
      echo '<td>' . esc_html($listing_title) . '</td>';
      echo '<td>' . esc_html($service_title) . '</td>';
      echo '<td><span class="koopo-badge koopo-badge--' . esc_attr($status) . '">' . esc_html($status) . '</span></td>';
      echo '<td><span class="koopo-badge koopo-badge--type-' . esc_attr($type) . '">' . esc_html(ucfirst($type)) . '</span></td>';
      echo '<td>' . $actions . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    if (Features::wc_subscriptions_active()) {
      echo '<p class="koopo-appts-links"><a href="' . esc_url(function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('subscriptions') : '#') . '">Manage subscriptions</a></p>';
    }

    echo '</div>';
  }

  public static function enqueue_styles() {
    // Reuse our badge styles or add small stylesheet
    wp_enqueue_style(
      'koopo-appt-badges',
      plugins_url('../assets/appointments-badges.css', __FILE__),
      [],
      '0.1.0'
    );
  }
}
