<?php
/**
 * Guarded beta acceptance harness for the service-profile portfolio lifecycle.
 *
 * KOOPO_APPT_PORTFOLIO_UAT=1 wp eval-file scripts/test-provider-portfolio-runtime.php create <username>
 * KOOPO_APPT_PORTFOLIO_UAT=1 wp eval-file scripts/test-provider-portfolio-runtime.php status <provider_id>
 * KOOPO_APPT_PORTFOLIO_UAT=1 wp eval-file scripts/test-provider-portfolio-runtime.php reverse <provider_id>
 * KOOPO_APPT_PORTFOLIO_UAT=1 wp eval-file scripts/test-provider-portfolio-runtime.php cleanup <provider_id>
 */

use Koopo_Appointments\Provider_Profiles;

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_PORTFOLIO_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_PORTFOLIO_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

$mode = sanitize_key((string) ($args[0] ?? ''));
$respond = static function(array $payload): void {
  echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
};

if ($mode === 'create') {
  $username = sanitize_user((string) ($args[1] ?? ''));
  $user = $username ? get_user_by('login', $username) : false;
  if (!$user) throw new RuntimeException('Provide an existing beta username.');
  $provider_id = Provider_Profiles::create_for_user((int) $user->ID, [
    'name' => 'Koopo Offload UAT Portfolio ' . gmdate('YmdHis'),
    'headline' => 'Temporary portfolio acceptance profile',
    'bio' => 'Temporary beta-only profile used to verify the portfolio upload, ordering, and public presentation flow.',
    'service_modes' => ['virtual'],
  ]);
  if (is_wp_error($provider_id)) throw new RuntimeException($provider_id->get_error_message());
  $respond([
    'ok' => true,
    'provider_id' => (int) $provider_id,
    'owner_id' => (int) $user->ID,
    'permalink' => get_permalink((int) $provider_id),
  ]);
  return;
}

$provider_id = absint($args[1] ?? 0);
$provider = get_post($provider_id);
if (!$provider || $provider->post_type !== Provider_Profiles::POST_TYPE || strpos((string) $provider->post_title, 'Koopo Offload UAT Portfolio ') !== 0) {
  throw new RuntimeException('Refusing to use an unverified Koopo portfolio UAT profile.');
}
wp_set_current_user((int) $provider->post_author);

if ($mode === 'status') {
  $respond([
    'ok' => true,
    'provider_id' => $provider_id,
    'permalink' => get_permalink($provider_id),
    'gallery_ids' => Provider_Profiles::gallery_ids($provider_id),
    'gallery' => Provider_Profiles::gallery($provider_id),
  ]);
  return;
}

if ($mode === 'reverse') {
  $before = Provider_Profiles::gallery_ids($provider_id);
  if (count($before) < 2) throw new RuntimeException('Upload at least two portfolio images before testing order.');
  $request = new WP_REST_Request('PUT');
  $request->set_param('id', $provider_id);
  $request->set_header('content-type', 'application/json');
  $request->set_body(wp_json_encode(['attachment_ids' => array_reverse($before)]));
  $result = Provider_Profiles::reorder_gallery($request);
  if ($result->is_error()) throw new RuntimeException('Portfolio reorder failed.');
  $after = Provider_Profiles::gallery_ids($provider_id);
  if ($after !== array_reverse($before)) throw new RuntimeException('The persisted portfolio order did not match the request.');
  $respond(['ok' => true, 'provider_id' => $provider_id, 'before' => $before, 'after' => $after]);
  return;
}

if ($mode === 'cleanup') {
  $deleted = [];
  foreach (Provider_Profiles::gallery_ids($provider_id) as $attachment_id) {
    $request = new WP_REST_Request('DELETE');
    $request->set_param('id', $provider_id);
    $request->set_param('attachment_id', $attachment_id);
    $result = Provider_Profiles::delete_gallery_image($request);
    if ($result->is_error() || get_post($attachment_id)) throw new RuntimeException('Could not delete portfolio attachment ' . $attachment_id . '.');
    $deleted[] = $attachment_id;
  }
  if (!wp_delete_post($provider_id, true)) throw new RuntimeException('Could not delete the temporary portfolio profile.');
  $respond(['ok' => true, 'provider_id' => $provider_id, 'deleted_attachments' => $deleted, 'profile_exists' => (bool) get_post($provider_id)]);
  return;
}

throw new RuntimeException('Unknown portfolio UAT mode.');
