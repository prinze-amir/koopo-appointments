<?php

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_RUNTIME_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_RUNTIME_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\DB;
use Koopo_Appointments\Provider_Onboarding;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Resources;
use Koopo_Appointments\Service_Categories;

function provider_email_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$stamp = gmdate('YmdHis') . wp_rand(100, 999);
$email = 'koopo-provider-code-' . strtolower($stamp) . '@example.invalid';
$login = 'koopo_provider_code_' . strtolower($stamp);
$signup_id = 0;
$user_id = 0;
$provider_id = 0;
$resource_id = 0;
$pack_order_id = 0;
$mail = [];

try {
  $categories = Service_Categories::get_all_categories();
  provider_email_expect(!empty($categories[0]['id']), 'No service category is available.');
  $payload = [
    'first_name' => 'Email',
    'last_name' => 'Verifier',
    'phone' => '+13135550199',
    'store_name' => 'Verification UAT ' . $stamp,
    'store_slug' => 'verification-uat-' . strtolower($stamp),
    'profile_name' => 'Verification Profile ' . $stamp,
    'headline' => 'Email code acceptance test',
    'category_id' => (int) $categories[0]['id'],
    'service_modes' => ['virtual'],
    'bio' => 'Temporary verification fixture.',
  ];
  add_filter('pre_wp_mail', static function ($return, array $atts) use (&$mail) {
    $mail[] = $atts;
    return true;
  }, 999, 2);
  add_filter('bp_core_send_user_registration_admin_notification', '__return_false', 999);

  $created = bp_core_signup_user($login, wp_generate_password(32, true, true), $email, [
    'password' => wp_generate_password(32, true, true),
    'koopo_provider_onboarding_v1' => $payload,
  ]);
  provider_email_expect(!is_wp_error($created), 'BuddyBoss provider signup could not be created.');
  $result = \BP_Signup::get(['user_login' => $login, 'exclude_active' => false]);
  $signup = $result['signups'][0] ?? null;
  provider_email_expect($signup && !$signup->active, 'Pending BuddyBoss signup was not found.');
  $signup_id = (int) $signup->signup_id;
  $user_id = (int) username_exists($login);

  $method = new ReflectionMethod(Provider_Onboarding::class, 'verification_code');
  $method->setAccessible(true);
  $code = (string) $method->invoke(null, (string) $signup->activation_key);
  $rendered_mail = wp_json_encode($mail);
  provider_email_expect((bool) preg_match('/^[0-9]{6}$/', $code), 'Verification code is not six digits.');
  provider_email_expect(false !== strpos($rendered_mail, $code), 'Activation email did not contain the verification code.');

  $request = new \WP_REST_Request('POST', '/koopo/v1/provider-onboarding/verify-email');
  $request->set_param('email', $email);
  $request->set_param('code', $code);
  $response = Provider_Onboarding::verify_email_code($request);
  provider_email_expect($response instanceof \WP_REST_Response && 200 === $response->get_status(), 'Verification endpoint did not activate the account.');
  $data = $response->get_data();
  provider_email_expect(!empty($data['ok']) && !empty($data['next_url']), 'Verification response is incomplete.');
  $user = get_user_by('email', $email);
  provider_email_expect($user && 0 === (int) $user->user_status, 'Verified BuddyBoss user is not active.');
  $user_id = (int) $user->ID;
  provider_email_expect(function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id), 'Verified account was not converted to a seller.');
  $provider_id = Provider_Profiles::owned_profile_id($user_id);
  provider_email_expect($provider_id > 0, 'Verified account did not receive a service profile.');
  $resource_id = (int) get_post_meta($provider_id, Resources::META_RESOURCE_ID, true);
  $pack_order_id = (int) get_user_meta($user_id, 'product_order_id', true);

  echo wp_json_encode([
    'ok' => true,
    'email_code' => 'delivered_and_accepted',
    'code_length' => strlen($code),
    'account' => 'activated',
    'seller' => true,
    'provider_profile' => true,
    'next_url' => (string) $data['next_url'],
  ], JSON_PRETTY_PRINT) . "\n";
} finally {
  global $wpdb;
  wp_set_current_user(0);
  if ($provider_id) wp_delete_post($provider_id, true);
  if ($resource_id) $wpdb->delete(DB::resources_table(), ['id' => $resource_id], ['%d']);
  if ($pack_order_id) {
    $order = wc_get_order($pack_order_id);
    if ($order) $order->delete(true);
  }
  if ($user_id) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user_id);
  }
  if ($signup_id) $wpdb->delete(buddypress()->members->table_name_signups, ['signup_id' => $signup_id], ['%d']);
}
