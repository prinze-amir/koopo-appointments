<?php
/** Guarded beta acceptance for onboarding pack allowlisting and Woo cart preparation. */

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_RUNTIME_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_RUNTIME_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\Admin_Settings;
use Koopo_Appointments\Provider_Onboarding;

function koopo_subscription_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$old_option = get_option(Admin_Settings::OPTION_ONBOARDING_PACK_IDS, []);
$user_id = 0;
$pack_id = 0;

try {
  $query = new WP_Query([
    'post_type' => 'product',
    'post_status' => 'publish',
    'posts_per_page' => 20,
    'fields' => 'ids',
    'tax_query' => [[ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => ['product_pack'] ]],
    'no_found_rows' => true,
  ]);
  foreach ($query->posts as $candidate) {
    if ('yes' === get_post_meta((int) $candidate, '_exclusive_for_admin_only', true)) continue;
    $product = wc_get_product((int) $candidate);
    if ($product && $product->is_purchasable()) { $pack_id = (int) $candidate; break; }
  }
  koopo_subscription_expect($pack_id > 0, 'No purchasable public Dokan subscription pack is available for the runtime test.');

  update_option(Admin_Settings::OPTION_ONBOARDING_PACK_IDS, [$pack_id], false);
  $packs = Provider_Onboarding::subscription_packs(false);
  koopo_subscription_expect(count($packs) === 1 && (int) $packs[0]['id'] === $pack_id, 'The configured onboarding pack allowlist was not applied.');
  koopo_subscription_expect(array_key_exists('features_html', $packs[0]), 'The selected pack does not expose its sanitized product feature description.');
  $all_packs = Provider_Onboarding::subscription_packs(true);
  koopo_subscription_expect(count($all_packs) >= count($packs), 'The dynamic admin pack catalog does not include all available public packs.');

  $stamp = strtolower(gmdate('YmdHis') . wp_rand(100, 999));
  $user_id = wp_create_user('koopo_pack_uat_' . $stamp, wp_generate_password(28, true, true), 'koopo-pack-uat-' . $stamp . '@example.invalid');
  koopo_subscription_expect(!is_wp_error($user_id), 'Could not create the temporary provider account.');
  $user = get_userdata((int) $user_id);
  koopo_subscription_expect($user instanceof WP_User, 'Temporary provider account could not be loaded.');
  dokan_user_update_to_seller($user, ['fname'=>'Pack','lname'=>'UAT','phone'=>'+13135550199','shopname'=>'Pack UAT','shopurl'=>'pack-uat-' . $stamp,'address'=>[]]);
  wp_set_current_user((int) $user_id);
  koopo_subscription_expect(Provider_Onboarding::can_prepare_subscription_checkout(), 'Verified seller cannot access onboarding checkout preparation.');

  $request = new WP_REST_Request('POST', '/koopo/v1/provider-onboarding/prepare-checkout');
  $request->set_param('pack_id', $pack_id);
  $response = Provider_Onboarding::prepare_subscription_checkout($request);
  koopo_subscription_expect($response instanceof WP_REST_Response && 200 === $response->get_status(), 'Onboarding checkout preparation failed.');
  $data = $response->get_data();
  koopo_subscription_expect(!empty($data['checkout_url']) && !empty($data['pack']['id']), 'Checkout preparation response is incomplete.');
  $found = false;
  foreach (WC()->cart->get_cart() as $item) if ((int) ($item['product_id'] ?? 0) === $pack_id) $found = true;
  koopo_subscription_expect($found, 'Selected subscription pack was not added to the WooCommerce cart.');

  echo wp_json_encode(['ok'=>true,'pack_id'=>$pack_id,'allowlist'=>'enforced','cart'=>'prepared','checkout_url'=>(string)$data['checkout_url']], JSON_PRETTY_PRINT) . "\n";
} finally {
  if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart(true);
  update_option(Admin_Settings::OPTION_ONBOARDING_PACK_IDS, $old_option, false);
  wp_set_current_user(0);
  if ($user_id && !is_wp_error($user_id)) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user((int) $user_id);
  }
}
