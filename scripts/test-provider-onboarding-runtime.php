<?php

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_RUNTIME_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_RUNTIME_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\DB;
use Koopo_Appointments\Dokan_Dashboard;
use Koopo_Appointments\Provider_Onboarding;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Resources;
use Koopo_Appointments\Service_Categories;

function onboarding_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$stamp = gmdate('YmdHis') . wp_rand(100, 999);
$user_id = 0;
$provider_id = 0;
$resource_id = 0;
$pack_order_id = 0;

try {
  $categories = Service_Categories::get_all_categories();
  onboarding_expect(!empty($categories[0]['id']), 'No service category is available for onboarding.');
  $user_id = wp_insert_user([
    'user_login' => 'koopo_onboard_uat_' . $stamp,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'koopo-onboard-uat-' . $stamp . '@example.invalid',
    'display_name' => 'Koopo Onboarding UAT',
    'role' => 'subscriber',
  ]);
  onboarding_expect(!is_wp_error($user_id), 'Unable to create onboarding UAT user.');
  $user_id = (int) $user_id;

  $payload = [
    'first_name' => 'Koopo',
    'last_name' => 'Tester',
    'phone' => '+13135550199',
    'store_name' => 'Koopo Onboarding UAT ' . $stamp,
    'store_slug' => 'koopo-onboarding-uat-' . strtolower($stamp),
    'profile_name' => 'Koopo Service Profile ' . $stamp,
    'headline' => 'Verification-gated provider setup test',
    'category_id' => (int) $categories[0]['id'],
    'service_modes' => ['mobile', 'virtual'],
    'bio' => 'Temporary profile created by the Koopo provider onboarding acceptance test.',
  ];
  Provider_Onboarding::activate_provider($user_id, 'uat-activation-key', ['meta' => ['koopo_provider_onboarding_v1' => $payload]]);

  onboarding_expect(function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id), 'Activated account was not converted to a Dokan seller.');
  $starter_pack_id = (int) get_user_meta($user_id, 'product_package_id', true);
  $starter_pack = $starter_pack_id ? wc_get_product($starter_pack_id) : null;
  $pack_order_id = (int) get_user_meta($user_id, 'product_order_id', true);
  onboarding_expect($starter_pack && 'starter' === sanitize_title($starter_pack->get_name()), 'Verified provider was not enrolled in the Starter plan.');
  onboarding_expect('yes' !== get_post_meta($starter_pack_id, '_exclusive_for_admin_only', true), 'Provider received an admin-only subscription pack.');
  $selling_enabled = function_exists('dokan_is_seller_enabled') && dokan_is_seller_enabled($user_id);
  $selling_allowed = (bool) apply_filters('dokan_can_enable_selling', true, $user_id);
  onboarding_expect($selling_enabled || !$selling_allowed, 'Selling remained disabled even though Dokan policy allows activation.');
  if (!$selling_enabled) {
    wp_set_current_user($user_id);
    ob_start();
    Dokan_Dashboard::render_verification_notice();
    $verification_notice = (string) ob_get_clean();
    onboarding_expect(strpos($verification_notice, 'Verify your seller account') !== false, 'Seller dashboard verification notice was not rendered.');
    onboarding_expect(strpos($verification_notice, 'settings/verification') !== false, 'Seller verification notice does not link to the Dokan verification workflow.');
  }
  $provider_id = Provider_Profiles::owned_profile_id($user_id);
  onboarding_expect($provider_id > 0 && 'publish' === get_post_status($provider_id), 'Verified activation did not publish a service profile.');
  onboarding_expect((int) get_post_field('post_author', $provider_id) === $user_id, 'Service profile ownership is incorrect.');
  onboarding_expect((string) get_post_meta($provider_id, Provider_Profiles::META_HEADLINE, true) === $payload['headline'], 'Service profile metadata was not persisted.');
  $terms = wp_get_object_terms($provider_id, Service_Categories::TAXONOMY, ['fields' => 'ids']);
  onboarding_expect(!is_wp_error($terms) && in_array($payload['category_id'], array_map('intval', $terms), true), 'Profile category was not persisted.');
  $resource_id = (int) get_post_meta($provider_id, Resources::META_RESOURCE_ID, true);
  onboarding_expect($resource_id > 0, 'Provider scheduling resource was not created.');

  echo wp_json_encode(['ok' => true, 'seller' => true, 'starter_pack_id' => $starter_pack_id, 'starter_pack_name' => $starter_pack->get_name(), 'selling_status' => $selling_enabled ? 'enabled' : 'blocked_by_dokan_policy', 'provider_id' => $provider_id, 'resource_id' => $resource_id], JSON_PRETTY_PRINT) . "\n";
} finally {
  global $wpdb;
  wp_set_current_user(0);
  if ($provider_id) wp_delete_post($provider_id, true);
  if ($resource_id) $wpdb->delete(DB::resources_table(), ['id' => $resource_id], ['%d']);
  if ($pack_order_id) {
    $pack_order = wc_get_order($pack_order_id);
    if ($pack_order) $pack_order->delete(true);
  }
  if ($user_id) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user_id);
  }
}
