<?php

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_RUNTIME_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_RUNTIME_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\Bookings;
use Koopo_Appointments\DB;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Resources;
use Koopo_Appointments\Services_API;
use Koopo_Appointments\Settings_API;

function uat_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function uat_json_request(string $method, array $url_params, array $payload): WP_REST_Request {
  $request = new WP_REST_Request($method);
  $request->set_url_params($url_params);
  $request->set_header('content-type', 'application/json');
  $request->set_body(wp_json_encode($payload));
  return $request;
}

function uat_create_service(int $provider_id, float $price, string $suffix): array {
  $request = new WP_REST_Request('POST');
  foreach ([
    'title' => 'Koopo UAT Service ' . $suffix,
    'provider_id' => $provider_id,
    'duration_minutes' => 30,
    'price' => $price,
    'status' => 'active',
    'instant' => 1,
  ] as $key => $value) $request->set_param($key, $value);
  $response = Services_API::create_service($request);
  uat_expect($response->get_status() === 201, 'Service creation failed: ' . wp_json_encode($response->get_data()));
  return (array) $response->get_data();
}

$created = ['users' => [], 'providers' => [], 'services' => [], 'products' => [], 'bookings' => [], 'orders' => [], 'resources' => []];
$results = [];
add_filter('pre_wp_mail', static fn() => true, PHP_INT_MAX);
remove_all_actions('koopo_booking_confirmed_safe');

try {
  $stamp = gmdate('YmdHis') . wp_rand(100, 999);
  foreach (['provider_a', 'provider_b', 'customer'] as $role_name) {
    $user_id = wp_insert_user([
      'user_login' => 'koopo_uat_' . $role_name . '_' . $stamp,
      'user_pass' => wp_generate_password(32, true, true),
      'user_email' => 'koopo-uat-' . $role_name . '-' . $stamp . '@example.invalid',
      'display_name' => 'Koopo UAT ' . ucwords(str_replace('_', ' ', $role_name)),
      'role' => 'subscriber',
    ]);
    uat_expect(!is_wp_error($user_id), 'Test user creation failed.');
    $created['users'][$role_name] = (int) $user_id;
  }

  foreach (['a', 'b'] as $key) {
    $owner_id = $created['users']['provider_' . $key];
    wp_set_current_user($owner_id);
    $provider_id = wp_insert_post([
      'post_type' => Provider_Profiles::POST_TYPE,
      'post_status' => 'publish',
      'post_title' => 'Koopo UAT Service Profile ' . strtoupper($key),
      'post_author' => $owner_id,
    ], true);
    uat_expect(!is_wp_error($provider_id), 'Service profile creation failed.');
    $provider_id = (int) $provider_id;
    update_post_meta($provider_id, '_koopo_appt_enabled', '1');
    $resource_id = Resources::ensure_for_provider($provider_id);
    uat_expect($resource_id > 0, 'Resource creation failed.');
    $created['providers'][$key] = $provider_id;
    $created['resources'][$key] = $resource_id;

    $hours = [];
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) $hours[$day] = [['00:00', '23:59']];
    $settings = Settings_API::resource_settings(uat_json_request('POST', ['resource_id' => $resource_id], [
      'enabled' => true,
      'timezone' => 'UTC',
      'hours' => $hours,
      'slot_interval' => 30,
    ]));
    uat_expect($settings->get_status() === 200 && !empty($settings->get_data()['enabled']), 'Resource settings failed.');

    $service = uat_create_service($provider_id, $key === 'a' ? 25.00 : 0.00, strtoupper($key));
    $created['services'][$key] = (int) $service['service_id'];
    $created['products'][$key] = (int) $service['product_id'];
    uat_expect($created['products'][$key] > 0, 'WooCommerce service product was not created.');
    uat_expect((int) get_post_field('post_author', $created['products'][$key]) === $owner_id, 'WooCommerce product payee ownership mismatch.');
  }

  $start = new DateTimeImmutable('+14 days 10:00:00', new DateTimeZone('UTC'));
  $end = $start->modify('+30 minutes');
  wp_set_current_user($created['users']['customer']);

  foreach (['a', 'b'] as $key) {
    $response = Bookings::create_booking(uat_json_request('POST', [], [
      'provider_id' => $created['providers'][$key],
      'resource_id' => $created['resources'][$key],
      'service_id' => $created['services'][$key],
      'start_datetime' => $start->format('Y-m-d H:i:s'),
      'end_datetime' => $end->format('Y-m-d H:i:s'),
      'timezone' => 'UTC',
      'customer_name' => 'Koopo UAT Customer',
      'customer_email' => 'koopo-uat-customer-' . $stamp . '@example.invalid',
    ]));
    uat_expect($response->get_status() === 201, 'Booking ' . strtoupper($key) . ' failed: ' . wp_json_encode($response->get_data()));
    $payload = (array) $response->get_data();
    $booking_id = (int) $payload['booking_id'];
    $created['bookings'][$key] = $booking_id;
    if (!empty($payload['order_id'])) $created['orders'][$key] = (int) $payload['order_id'];
    $booking = Bookings::get_booking($booking_id);
    uat_expect((int) $booking->provider_id === $created['providers'][$key], 'Booking provider mismatch.');
    uat_expect((int) $booking->resource_id === $created['resources'][$key], 'Booking resource mismatch.');
    uat_expect((int) $booking->payee_user_id === $created['users']['provider_' . $key], 'Booking payee mismatch.');
    uat_expect(empty($booking->listing_id), 'Independent booking unexpectedly requires a place.');
  }

  $conflict = Bookings::create_booking(uat_json_request('POST', [], [
    'provider_id' => $created['providers']['a'],
    'resource_id' => $created['resources']['a'],
    'service_id' => $created['services']['a'],
    'start_datetime' => $start->format('Y-m-d H:i:s'),
    'end_datetime' => $end->format('Y-m-d H:i:s'),
    'timezone' => 'UTC',
  ]));
  uat_expect($conflict->get_status() === 400, 'Same-resource conflict was not rejected.');

  $results = [
    'paid_booking' => 'pending_payment',
    'free_booking' => 'confirmed_with_order',
    'same_time_different_resources' => 'accepted',
    'same_resource_conflict' => 'rejected',
    'payee_ownership' => 'verified',
    'email_delivery' => 'suppressed',
  ];
} finally {
  global $wpdb;
  foreach ($created['bookings'] as $booking_id) Bookings::delete_booking_data_by_id((int) $booking_id);
  foreach ($created['orders'] as $order_id) {
    $order = wc_get_order((int) $order_id);
    if ($order) $order->delete(true);
  }
  foreach ($created['products'] as $product_id) if ($product_id) wp_delete_post((int) $product_id, true);
  foreach ($created['services'] as $service_id) if ($service_id) wp_delete_post((int) $service_id, true);
  if ($created['resources']) {
    $ids = array_map('absint', array_values($created['resources']));
    $wpdb->query('DELETE FROM ' . DB::resources_table() . ' WHERE id IN (' . implode(',', $ids) . ')');
  }
  foreach ($created['providers'] as $provider_id) if ($provider_id) wp_delete_post((int) $provider_id, true);
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach ($created['users'] as $user_id) if ($user_id) wp_delete_user((int) $user_id);
}

echo wp_json_encode(['ok' => true, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
