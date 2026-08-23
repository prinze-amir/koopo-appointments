<?php
/** Guarded beta fixture for reference-aware Media Gateway privacy erasure. */
if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_PRIVACY_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_PRIVACY_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\DB;
use Koopo_Appointments\Privacy;

function privacy_uat_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

global $wpdb;
$stamp = gmdate('YmdHis') . wp_rand(100, 999);
$email = 'koopo-privacy-' . $stamp . '@example.invalid';
$user_id = wp_insert_user([
  'user_login'=>'koopo_privacy_' . $stamp,
  'user_pass'=>wp_generate_password(32, true, true),
  'user_email'=>$email,
  'display_name'=>'Koopo Privacy UAT',
  'role'=>'subscriber',
]);
if (is_wp_error($user_id)) throw new RuntimeException($user_id->get_error_message());
$created_client_ids = [];

try {
  $wpdb->insert(DB::clients_table(), ['resource_id'=>999998, 'owner_user_id'=>1, 'wp_user_id'=>$user_id, 'name'=>'Privacy Customer', 'email'=>$email]);
  $client_id = (int) $wpdb->insert_id;
  $created_client_ids[] = $client_id;
  $wpdb->insert(DB::client_files_table(), ['resource_id'=>999998, 'client_id'=>$client_id, 'owner_user_id'=>1, 'asset_id'=>'00000000-0000-4000-8000-000000000001', 'filename'=>'privacy-uat.pdf', 'mime_type'=>'application/pdf', 'size_bytes'=>128]);
  $release = static fn($result, $file) => (int)$file->client_id === $client_id ? true : $result;
  add_filter('koopo_appt_privacy_release_client_file', $release, 100, 2);
  $erased = Privacy::erase($email, 1);
  remove_filter('koopo_appt_privacy_release_client_file', $release, 100);
  $client = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::clients_table() . ' WHERE id=%d', $client_id));
  privacy_uat_expect(!empty($erased['done']) && empty($erased['items_retained']), 'Successful remote release did not complete erasure.');
  privacy_uat_expect($client && $client->email === '' && empty($client->wp_user_id), 'Client identity was not anonymized after remote release.');
  privacy_uat_expect(!(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::client_files_table() . ' WHERE client_id=%d', $client_id)), 'Released client-file reference remains in the database.');

  $wpdb->insert(DB::clients_table(), ['resource_id'=>999999, 'owner_user_id'=>1, 'wp_user_id'=>$user_id, 'name'=>'Privacy Retry', 'email'=>$email]);
  $retry_client_id = (int) $wpdb->insert_id;
  $created_client_ids[] = $retry_client_id;
  $wpdb->insert(DB::client_files_table(), ['resource_id'=>999999, 'client_id'=>$retry_client_id, 'owner_user_id'=>1, 'asset_id'=>'00000000-0000-4000-8000-000000000002', 'filename'=>'privacy-retry.pdf', 'mime_type'=>'application/pdf', 'size_bytes'=>128]);
  $retain = static fn($result, $file) => (int)$file->client_id === $retry_client_id ? new WP_Error('gateway_unavailable', 'Retry later.') : $result;
  add_filter('koopo_appt_privacy_release_client_file', $retain, 100, 2);
  $retained = Privacy::erase($email, 1);
  remove_filter('koopo_appt_privacy_release_client_file', $retain, 100);
  $retry_client = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::clients_table() . ' WHERE id=%d', $retry_client_id));
  privacy_uat_expect(empty($retained['done']) && !empty($retained['items_retained']), 'Failed remote release was not reported as retryable retention.');
  privacy_uat_expect($retry_client && $retry_client->email === $email && (int)$retry_client->wp_user_id === (int)$user_id, 'Retry lookup was destroyed after a failed remote release.');

  echo wp_json_encode(['ok'=>true, 'released'=>'anonymized', 'gateway_failure'=>'retained_for_retry']) . "\n";
} finally {
  foreach ($created_client_ids as $client_id) {
    $wpdb->delete(DB::client_files_table(), ['client_id'=>$client_id]);
    $wpdb->delete(DB::form_submissions_table(), ['client_id'=>$client_id]);
    $wpdb->delete(DB::clients_table(), ['id'=>$client_id]);
  }
  require_once ABSPATH . 'wp-admin/includes/user.php';
  wp_delete_user((int)$user_id);
}
