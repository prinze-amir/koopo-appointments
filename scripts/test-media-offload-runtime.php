<?php
/**
 * Beta acceptance harness for service-profile direct uploads.
 *
 * KOOPO_APPT_MEDIA_UAT=1 wp eval-file scripts/test-media-offload-runtime.php upload <provider_id> <absolute_image_path> <image|gallery>
 * KOOPO_APPT_MEDIA_UAT=1 wp eval-file scripts/test-media-offload-runtime.php delete-gallery <provider_id> <attachment_id>
 */

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_MEDIA_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_MEDIA_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

$mode = sanitize_key((string) ($args[0] ?? ''));
$provider_id = absint($args[1] ?? 0);
$provider = get_post($provider_id);
if (!$provider || $provider->post_type !== \Koopo_Appointments\Provider_Profiles::POST_TYPE || strpos((string) $provider->post_title, 'Koopo Offload UAT ') !== 0) {
  throw new RuntimeException('Refusing to use an unverified Koopo Offload UAT profile.');
}
wp_set_current_user((int) $provider->post_author);

$respond = static function(array $payload): void {
  echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
};

if ($mode === 'upload') {
  $file = (string) ($args[2] ?? '');
  $role = sanitize_key((string) ($args[3] ?? 'image'));
  if (!is_readable($file) || !in_array($role, ['image', 'gallery'], true)) {
    throw new RuntimeException('Provide a readable image and the image or gallery role.');
  }
  $filetype = wp_check_filetype(basename($file));
  $mime = sanitize_mime_type((string) ($filetype['type'] ?? ''));
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
    throw new RuntimeException('The fixture must be a supported image type.');
  }
  $dimensions = @getimagesize($file);
  $route = '/koopo-media-gateway/v1/appointments/service-profiles/' . $provider_id . '/' . $role . '/upload-sessions';
  $key = 'koopo-media-uat-' . $provider_id . '-' . wp_generate_uuid4();
  $create = new WP_REST_Request('POST', $route);
  $create->set_header('content-type', 'application/json');
  $create->set_header('idempotency-key', $key);
  $create->set_body(wp_json_encode([
    'filename' => basename($file),
    'mimeType' => $mime,
    'sizeBytes' => filesize($file),
    'kind' => 'image',
    'clientRequestId' => $key,
    'metadata' => [
      'width' => absint($dimensions[0] ?? 0),
      'height' => absint($dimensions[1] ?? 0),
    ],
  ]));
  $created = rest_do_request($create);
  $created_data = (array) $created->get_data();
  if ($created->is_error() || empty($created_data['session']['id']) || empty($created_data['upload']['url'])) {
    throw new RuntimeException('Upload session creation failed (' . $created->get_status() . '): ' . (string) ($created_data['message'] ?? $created_data['error'] ?? 'unknown error'));
  }

  $session_id = sanitize_text_field((string) $created_data['session']['id']);
  $upload = (array) $created_data['upload'];
  $put = wp_remote_request((string) $upload['url'], [
    'method' => sanitize_text_field((string) ($upload['method'] ?? 'PUT')),
    'headers' => is_array($upload['headers'] ?? null) ? $upload['headers'] : [],
    'body' => file_get_contents($file),
    'timeout' => 90,
  ]);
  if (is_wp_error($put) || wp_remote_retrieve_response_code($put) < 200 || wp_remote_retrieve_response_code($put) >= 300) {
    $cancel = new WP_REST_Request('DELETE', $route . '/' . $session_id);
    rest_do_request($cancel);
    throw new RuntimeException('Direct storage PUT failed: ' . (is_wp_error($put) ? $put->get_error_message() : (string) wp_remote_retrieve_response_code($put)));
  }

  $complete = new WP_REST_Request('POST', $route . '/' . $session_id . '/complete');
  $complete->set_header('content-type', 'application/json');
  $complete->set_body('{}');
  $completed = rest_do_request($complete);
  $completed_data = (array) $completed->get_data();
  $media = (array) ($completed_data['serviceProfileMedia'] ?? []);
  $attachment_id = absint($media['attachmentId'] ?? 0);
  if ($completed->is_error() || !$attachment_id) {
    throw new RuntimeException('Upload completion failed (' . $completed->get_status() . '): ' . (string) ($completed_data['message'] ?? $completed_data['error'] ?? 'unknown error'));
  }
  $url = wp_get_attachment_url($attachment_id);
  $head = $url ? wp_remote_head($url, ['timeout' => 30]) : new WP_Error('missing_url', 'Missing URL');
  $attached_file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
  $local_path = $attached_file ? wp_get_upload_dir()['basedir'] . '/' . ltrim($attached_file, '/') : '';
  $respond([
    'ok' => true,
    'provider_id' => $provider_id,
    'attachment_id' => $attachment_id,
    'asset_id' => (string) get_post_meta($attachment_id, '_koopo_media_asset_id', true),
    'role' => (string) get_post_meta($attachment_id, '_koopo_media_role', true),
    'context' => (string) get_post_meta($attachment_id, '_koopo_media_context', true),
    'remote_only' => (bool) get_post_meta($attachment_id, '_koopo_media_remote_only', true),
    'delivery_host' => (string) wp_parse_url((string) $url, PHP_URL_HOST),
    'delivery_status' => is_wp_error($head) ? 0 : wp_remote_retrieve_response_code($head),
    'local_file_exists' => $local_path ? file_exists($local_path) : false,
  ]);
  return;
}

if ($mode === 'delete-gallery') {
  $attachment_id = absint($args[2] ?? 0);
  if (!in_array($attachment_id, \Koopo_Appointments\Provider_Profiles::gallery_ids($provider_id), true)) {
    throw new RuntimeException('Refusing to delete an attachment outside the UAT profile gallery.');
  }
  $asset_id = (string) get_post_meta($attachment_id, '_koopo_media_asset_id', true);
  $request = new WP_REST_Request('DELETE');
  $request->set_param('id', $provider_id);
  $request->set_param('attachment_id', $attachment_id);
  $result = \Koopo_Appointments\Provider_Profiles::delete_gallery_image($request);
  if ($result->is_error()) throw new RuntimeException('Gallery deletion failed.');
  $respond([
    'ok' => true,
    'attachment_id' => $attachment_id,
    'asset_id' => $asset_id,
    'attachment_exists' => (bool) get_post($attachment_id),
    'still_in_gallery' => in_array($attachment_id, \Koopo_Appointments\Provider_Profiles::gallery_ids($provider_id), true),
  ]);
  return;
}

throw new RuntimeException('Unknown media UAT mode.');
