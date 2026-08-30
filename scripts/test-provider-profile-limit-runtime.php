<?php
/** Guarded beta acceptance for fixed and unlimited service-profile entitlements. */
if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_RUNTIME_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_RUNTIME_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\Provider_Profiles;

function koopo_profile_limit_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$user_id = 0;
$pack_id = 0;
$profile_ids = [];
try {
  $stamp = strtolower(gmdate('YmdHis') . wp_rand(100, 999));
  $user_id = wp_create_user('koopo_limit_' . $stamp, wp_generate_password(28, true, true), 'koopo-limit-' . $stamp . '@example.invalid');
  koopo_profile_limit_expect(!is_wp_error($user_id), 'Could not create the temporary vendor.');
  $user = get_userdata((int) $user_id);
  dokan_user_update_to_seller($user, ['fname'=>'Limit','lname'=>'UAT','phone'=>'+13135550199','shopname'=>'Limit UAT','shopurl'=>'limit-uat-' . $stamp,'address'=>[]]);
  $pack_id = wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'Koopo Profile Limit UAT'], true);
  koopo_profile_limit_expect(!is_wp_error($pack_id), 'Could not create the temporary entitlement product.');
  update_post_meta((int) $pack_id, '_koopo_features', wp_json_encode(['appointments'=>true,'service_profiles'=>1]));
  update_user_meta((int) $user_id, 'product_package_id', (int) $pack_id);

  $payload = ['name'=>'Limit One','headline'=>'Runtime profile','category_id'=>0,'service_modes'=>['virtual']];
  $first = Provider_Profiles::create_for_user((int) $user_id, $payload);
  koopo_profile_limit_expect(!is_wp_error($first), 'The first included profile was rejected.');
  $profile_ids[] = (int) $first;
  $second = Provider_Profiles::create_for_user((int) $user_id, array_merge($payload, ['name'=>'Limit Two']));
  koopo_profile_limit_expect(is_wp_error($second) && 'profile_limit_reached' === $second->get_error_code(), 'The fixed profile limit was not enforced.');

  update_post_meta((int) $pack_id, '_koopo_features', wp_json_encode(['appointments'=>false,'service_profiles'=>'unlimited']));
  $disabled = Provider_Profiles::profile_entitlement((int) $user_id);
  koopo_profile_limit_expect(0 === $disabled['limit'] && false === $disabled['can_create'], 'Disabling Appointments did not disable the service-profile entitlement.');

  update_post_meta((int) $pack_id, '_koopo_features', wp_json_encode(['appointments'=>true,'service_profiles'=>'unlimited']));
  $unlimited = Provider_Profiles::create_for_user((int) $user_id, array_merge($payload, ['name'=>'Unlimited Two']));
  koopo_profile_limit_expect(!is_wp_error($unlimited), 'The unlimited entitlement rejected an additional profile.');
  $profile_ids[] = (int) $unlimited;
  $entitlement = Provider_Profiles::profile_entitlement((int) $user_id);
  koopo_profile_limit_expect(null === $entitlement['limit'] && true === $entitlement['can_create'] && 2 === $entitlement['used'], 'Unlimited entitlement state is incorrect.');
  echo wp_json_encode(['ok'=>true,'fixed_limit'=>'enforced','appointments_disabled'=>'zero_profiles','unlimited'=>'accepted','profiles'=>$entitlement['used']], JSON_PRETTY_PRINT) . "\n";
} finally {
  foreach ($profile_ids as $profile_id) if ($profile_id) wp_delete_post($profile_id, true);
  if ($pack_id && !is_wp_error($pack_id)) wp_delete_post((int) $pack_id, true);
  if ($user_id && !is_wp_error($user_id)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $user_id); }
}
