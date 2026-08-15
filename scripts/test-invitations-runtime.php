<?php

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_INVITATIONS_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_INVITATIONS_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\Appointment_Messaging;
use Koopo_Appointments\Booking_Invitations;
use Koopo_Appointments\Bookings;
use Koopo_Appointments\DB;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Resources;
use Koopo_Appointments\Services_API;
use Koopo_Appointments\Settings_API;

global $wpdb;

function invite_uat_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function invite_uat_request(string $method, array $url_params = [], array $payload = []): WP_REST_Request {
  $request = new WP_REST_Request($method);
  $request->set_url_params($url_params);
  $request->set_header('content-type', 'application/json');
  $request->set_body(wp_json_encode($payload));
  return $request;
}

function invite_uat_token(string $url): string {
  $path = (string) wp_parse_url($url, PHP_URL_PATH);
  return (string) basename(untrailingslashit($path));
}

$created = ['users'=>[], 'provider'=>0, 'resource'=>0, 'service'=>0, 'product'=>0, 'bookings'=>[], 'orders'=>[]];
$results = [];
add_filter('pre_wp_mail', static fn() => true, PHP_INT_MAX);

try {
  DB::maybe_upgrade();
  invite_uat_expect((string) get_option('koopo_appt_db_version') === DB::VERSION, 'Database migration did not reach ' . DB::VERSION . '.');
  invite_uat_expect(function_exists('messages_new_message'), 'BuddyBoss messages are unavailable in this runtime.');

  $stamp = gmdate('YmdHis') . wp_rand(100, 999);
  foreach (['provider', 'email_target', 'phone_target', 'wrong'] as $name) {
    $user_id = wp_insert_user([
      'user_login'=>'koopo_invite_uat_' . $name . '_' . $stamp,
      'user_pass'=>wp_generate_password(32, true, true),
      'user_email'=>'koopo-invite-uat-' . $name . '-' . $stamp . '@example.invalid',
      'display_name'=>'Koopo Invite UAT ' . ucwords(str_replace('_', ' ', $name)),
      'role'=>'subscriber',
    ]);
    invite_uat_expect(!is_wp_error($user_id), 'Test user creation failed for ' . $name . '.');
    $created['users'][$name] = (int) $user_id;
  }
  update_user_meta($created['users']['phone_target'], '_koopo_verified_phone_e164', '+13135550199');
  update_user_meta($created['users']['wrong'], 'billing_phone', '+13135550199');

  wp_set_current_user($created['users']['provider']);
  $provider_id = wp_insert_post([
    'post_type'=>Provider_Profiles::POST_TYPE,
    'post_status'=>'publish',
    'post_title'=>'Koopo Invitation UAT Profile',
    'post_author'=>$created['users']['provider'],
  ], true);
  invite_uat_expect(!is_wp_error($provider_id), 'Service profile creation failed.');
  $created['provider'] = (int) $provider_id;
  update_post_meta($created['provider'], '_koopo_appt_enabled', '1');
  update_post_meta($created['provider'], Provider_Profiles::META_SERVICE_MODES, ['at_location']);
  update_post_meta($created['provider'], Provider_Profiles::META_LOCATION_NAME, 'Koopo Invitation UAT Studio');
  update_post_meta($created['provider'], Provider_Profiles::META_ADDRESS, '123 Test Street');
  update_post_meta($created['provider'], Provider_Profiles::META_CITY, 'Detroit');
  $created['resource'] = Resources::ensure_for_provider($created['provider']);
  invite_uat_expect($created['resource'] > 0, 'Provider resource creation failed.');

  $hours = [];
  foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) $hours[$day] = [['00:00', '23:59']];
  $settings = Settings_API::resource_settings(invite_uat_request('POST', ['resource_id'=>$created['resource']], [
    'enabled'=>true, 'timezone'=>'UTC', 'hours'=>$hours, 'slot_interval'=>30,
  ]));
  invite_uat_expect($settings->get_status() === 200, 'Provider resource settings failed.');

  $service_request = new WP_REST_Request('POST');
  foreach ([
    'title'=>'Koopo Invitation UAT Service', 'provider_id'=>$created['provider'],
    'duration_minutes'=>30, 'price'=>0, 'status'=>'active', 'instant'=>1,
  ] as $key=>$value) $service_request->set_param($key, $value);
  $service_response = Services_API::create_service($service_request);
  invite_uat_expect($service_response->get_status() === 201, 'Service creation failed.');
  $service_data = (array) $service_response->get_data();
  $created['service'] = (int) $service_data['service_id'];
  $created['product'] = (int) $service_data['product_id'];

  $start = new DateTimeImmutable('+21 days 10:00:00', new DateTimeZone('UTC'));
  $make_booking = static function(string $email, string $phone, int $offset_hours) use (&$created, $start): int {
    $slot = $start->modify('+' . $offset_hours . ' hours');
    return Bookings::create_manual_booking([
      'provider_id'=>$created['provider'], 'resource_id'=>$created['resource'], 'service_id'=>$created['service'],
      'customer_id'=>0, 'customer_name'=>'Invited Guest', 'customer_email'=>$email, 'customer_phone'=>$phone,
      'start_datetime'=>$slot->format('Y-m-d H:i:s'), 'end_datetime'=>$slot->modify('+30 minutes')->format('Y-m-d H:i:s'),
      'timezone'=>'UTC', 'currency'=>'USD', 'status'=>Booking_Invitations::STATUS,
    ]);
  };

  $email_booking = $make_booking((string) get_userdata($created['users']['email_target'])->user_email, '', 0);
  $created['bookings'][] = $email_booking;
  $email_invite = Booking_Invitations::create($email_booking, $created['users']['provider'], ['email'], 120, false);
  invite_uat_expect(!is_wp_error($email_invite) && !empty($email_invite['delivery']['email']), 'Email invitation was not accepted by wp_mail.');
  $old_token = invite_uat_token((string) $email_invite['link']);
  invite_uat_expect(strlen($old_token) >= 32, 'Email invitation token is malformed.');

  $invite_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::booking_invites_table() . ' WHERE booking_id=%d', $email_booking));
  invite_uat_expect($invite_row && !hash_equals((string) $invite_row->token_hash, $old_token), 'Raw invitation token was persisted.');
  invite_uat_expect(empty($invite_row->sms_consent_at), 'Email-only invitation incorrectly recorded SMS consent.');

  $resend = Booking_Invitations::resend_route(invite_uat_request('POST', ['id'=>$email_booking]));
  invite_uat_expect(is_array($resend) && !empty($resend['delivery']['email']), 'Invitation resend failed.');
  $new_token = invite_uat_token((string) $resend['link']);
  invite_uat_expect(!hash_equals($old_token, $new_token), 'Invitation resend did not rotate the token.');
  $old_claim = Booking_Invitations::claim($old_token, $created['users']['email_target']);
  invite_uat_expect(is_wp_error($old_claim) && $old_claim->get_error_code() === 'invitation_unavailable', 'Rotated invitation token remained valid.');
  $wrong_claim = Booking_Invitations::claim($new_token, $created['users']['wrong']);
  invite_uat_expect(is_wp_error($wrong_claim) && $wrong_claim->get_error_code() === 'invitation_identity_mismatch', 'Wrong account claimed an email invitation.');
  $email_claim = Booking_Invitations::claim($new_token, $created['users']['email_target']);
  invite_uat_expect(is_array($email_claim) && !empty($email_claim['claimed']) && !empty($email_claim['confirmed']), 'Matching email account could not claim invitation.');
  $email_booking_row = Bookings::get_booking($email_booking);
  $created['orders'][] = (int) ($email_booking_row->wc_order_id ?? 0);
  invite_uat_expect((int) $email_booking_row->customer_id === $created['users']['email_target'], 'Claimed email booking has wrong owner.');

  $message_delivery = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM " . DB::notification_deliveries_table() . " WHERE booking_id=%d AND channel='inbox' AND event_name='appointment_claimed'",
    $email_booking
  ));
  invite_uat_expect($message_delivery && $message_delivery->status === 'sent' && (int) $message_delivery->provider_message_id > 0, 'Claim did not create a delivered BuddyBoss inbox message.');
  invite_uat_expect(Appointment_Messaging::send($email_booking, 'claimed'), 'Idempotent inbox replay was not treated as handled.');
  $message_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM " . DB::notification_deliveries_table() . " WHERE booking_id=%d AND channel='inbox' AND event_name='appointment_claimed'",
    $email_booking
  ));
  invite_uat_expect($message_count === 1, 'Inbox replay created a duplicate delivery record.');
  if (function_exists('bp_messages_get_meta')) {
    $linked = bp_messages_get_meta((int) $message_delivery->provider_message_id, 'linked_entity', true);
    invite_uat_expect(is_array($linked) && (int) ($linked['bookingId'] ?? 0) === $email_booking, 'Inbox message lacks standard linked_entity metadata.');
  }

  $phone_booking = $make_booking('', '(313) 555-0199', 2);
  $created['bookings'][] = $phone_booking;
  $sms_invite = Booking_Invitations::create($phone_booking, $created['users']['provider'], ['sms'], 120, true);
  invite_uat_expect(!is_wp_error($sms_invite), 'Consented SMS invitation could not be created.');
  invite_uat_expect(empty($sms_invite['delivery']['sms']) && in_array('sms_adapter_not_configured', $sms_invite['delivery']['warnings'], true), 'Missing SMS adapter was incorrectly reported as successful.');
  $sms_token = invite_uat_token((string) $sms_invite['link']);
  $sms_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::booking_invites_table() . ' WHERE booking_id=%d', $phone_booking));
  invite_uat_expect($sms_row && $sms_row->sms_consent_version === Booking_Invitations::SMS_CONSENT_VERSION, 'SMS consent evidence is incomplete.');
  invite_uat_expect($sms_row->sms_consent_phone === '+13135550199' && empty($sms_row->sms_sent_at), 'SMS consent phone or delivery result is incorrect.');
  $unverified_claim = Booking_Invitations::claim($sms_token, $created['users']['wrong']);
  invite_uat_expect(is_wp_error($unverified_claim) && $unverified_claim->get_error_code() === 'invitation_identity_mismatch', 'Unverified billing phone established invitation ownership.');
  $phone_claim = Booking_Invitations::claim($sms_token, $created['users']['phone_target']);
  invite_uat_expect(is_array($phone_claim) && !empty($phone_claim['claimed']), 'Verified phone account could not claim invitation.');
  $phone_booking_row = Bookings::get_booking($phone_booking);
  $created['orders'][] = (int) ($phone_booking_row->wc_order_id ?? 0);

  $results = [
    'schema'=>DB::VERSION, 'email_invitation'=>'claimed', 'token_rotation'=>'verified',
    'identity_binding'=>'email_and_verified_phone', 'unverified_billing_phone'=>'rejected',
    'buddyboss_inbox'=>'delivered_once', 'linked_entity'=>'verified',
    'sms_consent'=>'recorded', 'sms_without_adapter'=>'failed_closed', 'email_delivery'=>'suppressed',
  ];
} finally {
  foreach (array_filter($created['bookings']) as $booking_id) Bookings::delete_booking_data_by_id((int) $booking_id);
  foreach (array_filter(array_unique($created['orders'])) as $order_id) {
    $order = wc_get_order((int) $order_id);
    if ($order) $order->delete(true);
  }
  if ($created['product']) wp_delete_post($created['product'], true);
  if ($created['service']) wp_delete_post($created['service'], true);
  if ($created['resource']) $wpdb->delete(DB::resources_table(), ['id'=>$created['resource']], ['%d']);
  if ($created['provider']) wp_delete_post($created['provider'], true);
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach (array_filter($created['users']) as $user_id) wp_delete_user((int) $user_id);
}

echo wp_json_encode(['ok'=>true, 'results'=>$results], JSON_PRETTY_PRINT) . "\n";
