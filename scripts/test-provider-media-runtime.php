<?php
/** Guarded beta acceptance harness for service-profile direct media. */
if ('1' !== getenv('KOOPO_APPT_RUN_MEDIA_TEST')) { fwrite(STDERR, "Set KOOPO_APPT_RUN_MEDIA_TEST=1.\n"); exit(2); }

$action = sanitize_key((string) getenv('KOOPO_APPT_MEDIA_TEST_ACTION'));
$user_id = absint(getenv('KOOPO_APPT_MEDIA_TEST_USER_ID'));
$test_mime = sanitize_mime_type((string) (getenv('KOOPO_APPT_MEDIA_TEST_MIME') ?: 'image/png'));
$test_filename = sanitize_file_name((string) (getenv('KOOPO_APPT_MEDIA_TEST_FILENAME') ?: ('image/jpeg' === $test_mime ? 'jordan-profile.jpg' : 'jordan-profile.png')));
if (!$user_id) { fwrite(STDERR, "A test user is required.\n"); exit(2); }
wp_set_current_user($user_id);

if ('prepare' === $action) {
  $provider_id = wp_insert_post([
    'post_type' => 'koopo_provider', 'post_status' => 'publish', 'post_title' => 'Jordan Ellis',
    'post_excerpt' => 'Independent barber · Precision cuts and grooming',
    'post_content' => 'Jordan blends classic barbering with an easy, unhurried appointment experience. Every service is tailored to the person in the chair.',
    'post_author' => $user_id, 'comment_status' => 'open',
  ]);
  update_post_meta($provider_id, '_koopo_provider_headline', 'Independent barber · Precision cuts and grooming');
  update_post_meta($provider_id, '_koopo_provider_service_modes', ['at_location', 'mobile']);
  update_post_meta($provider_id, '_koopo_appt_enabled', '1');
  \Koopo_Appointments\Resources::ensure_for_provider($provider_id);
  foreach ([['Signature Cut',45,45],['Cut & Beard Detail',65,60],['Fresh Lineup',25,25]] as $item) {
    $product = new WC_Product_Simple(); $product->set_name($item[0]); $product->set_regular_price((string) $item[1]); $product->set_virtual(true); $product->set_status('publish'); $product_id = $product->save();
    $service_id = wp_insert_post(['post_type'=>'koopo_service','post_status'=>'publish','post_title'=>$item[0],'post_author'=>$user_id]);
    update_post_meta($service_id, '_koopo_provider_id', $provider_id); update_post_meta($service_id, '_koopo_service_price', $item[1]); update_post_meta($service_id, '_koopo_service_duration_minutes', $item[2]); update_post_meta($service_id, '_koopo_service_status', 'active'); update_post_meta($service_id, '_koopo_wc_product_id', $product_id); update_post_meta($service_id, '_koopo_service_description', 'A focused, personalized appointment with time reserved just for you.');
  }
  global $wpdb;
  $wpdb->replace(\Koopo_Appointments\DB::affiliations_table(), ['provider_id'=>$provider_id,'listing_id'=>8497,'requested_by'=>$user_id,'relationship_type'=>'independent','status'=>'approved','public_display'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
  $request = new WP_REST_Request('POST', '/koopo-media-gateway/v1/appointments/service-profiles/' . $provider_id . '/image/upload-sessions');
  $request->set_header('Idempotency-Key', 'koopo-appt-media-test-' . $provider_id . '-' . time());
  $request->set_header('Content-Type', 'application/json');
  $request->set_body(wp_json_encode(['filename'=>$test_filename,'mimeType'=>$test_mime,'sizeBytes'=>absint(getenv('KOOPO_APPT_MEDIA_TEST_SIZE')),'kind'=>'image','clientRequestId'=>'koopo-appt-media-test-' . $provider_id]));
  $response = rest_do_request($request);
  echo wp_json_encode(['providerId'=>$provider_id,'responseStatus'=>$response->get_status(),'session'=>$response->get_data()]);
  exit($response->get_status() >= 400 ? 1 : 0);
}

$provider_id = absint(getenv('KOOPO_APPT_MEDIA_TEST_PROVIDER_ID'));
if ('session' === $action) {
  $segment = 'gallery' === sanitize_key((string) getenv('KOOPO_APPT_MEDIA_TEST_ROLE')) ? 'gallery' : 'image';
  $request = new WP_REST_Request('POST', '/koopo-media-gateway/v1/appointments/service-profiles/' . $provider_id . '/' . $segment . '/upload-sessions');
  $request->set_header('Idempotency-Key', 'koopo-appt-media-test-' . $provider_id . '-' . time());
  $request->set_header('Content-Type', 'application/json');
  $request->set_body(wp_json_encode(['filename'=>$test_filename,'mimeType'=>$test_mime,'sizeBytes'=>absint(getenv('KOOPO_APPT_MEDIA_TEST_SIZE')),'kind'=>'image','clientRequestId'=>'koopo-appt-media-test-' . $provider_id . '-' . time()]));
  $response = rest_do_request($request);
  echo wp_json_encode(['providerId'=>$provider_id,'responseStatus'=>$response->get_status(),'session'=>$response->get_data()]);
  return;
}
if ('complete' === $action) {
  $session_id = sanitize_text_field((string) getenv('KOOPO_APPT_MEDIA_TEST_SESSION_ID'));
  $segment = 'gallery' === sanitize_key((string) getenv('KOOPO_APPT_MEDIA_TEST_ROLE')) ? 'gallery' : 'image';
  $request = new WP_REST_Request('POST', '/koopo-media-gateway/v1/appointments/service-profiles/' . $provider_id . '/' . $segment . '/upload-sessions/' . $session_id . '/complete');
  $response = rest_do_request($request); $data = $response->get_data(); $attachment_id = absint($data['serviceProfileMedia']['attachmentId'] ?? 0);
  echo wp_json_encode(['responseStatus'=>$response->get_status(),'data'=>$data,'checks'=>['featured'=>(int)get_post_thumbnail_id($provider_id),'gallery'=>\Koopo_Appointments\Provider_Profiles::gallery_ids($provider_id),'remoteOnly'=>(string)get_post_meta($attachment_id,'_koopo_media_remote_only',true),'context'=>(string)get_post_meta($attachment_id,'_koopo_media_context',true),'role'=>(string)get_post_meta($attachment_id,'_koopo_media_role',true),'localFile'=>(bool)(get_attached_file($attachment_id) && file_exists(get_attached_file($attachment_id))),'url'=>(string)wp_get_attachment_url($attachment_id)]]);
  return;
}

if ('cleanup' === $action) {
  $services = get_posts(['post_type'=>'koopo_service','post_status'=>'any','posts_per_page'=>100,'fields'=>'ids','meta_key'=>'_koopo_provider_id','meta_value'=>$provider_id]);
  foreach ($services as $service_id) { $product_id = absint(get_post_meta($service_id, '_koopo_wc_product_id', true)); wp_delete_post($service_id, true); if ($product_id) wp_delete_post($product_id, true); }
  $attachment_id = (int) get_post_thumbnail_id($provider_id); if ($attachment_id) wp_delete_attachment($attachment_id, true);
  foreach (\Koopo_Appointments\Provider_Profiles::gallery_ids($provider_id) as $gallery_id) wp_delete_attachment($gallery_id, true);
  foreach (get_comments(['post_id'=>$provider_id,'status'=>'all','type'=>\Koopo_Appointments\Provider_Reviews::COMMENT_TYPE]) as $review) wp_delete_comment($review->comment_ID, true);
  global $wpdb; $wpdb->delete(\Koopo_Appointments\DB::affiliations_table(), ['provider_id'=>$provider_id], ['%d']); $wpdb->delete(\Koopo_Appointments\DB::resources_table(), ['subject_type'=>'provider','subject_id'=>$provider_id], ['%s','%d']); wp_delete_post($provider_id, true);
  require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($user_id);
  echo wp_json_encode(['cleaned'=>true,'providerId'=>$provider_id,'userId'=>$user_id]);
  return;
}

fwrite(STDERR, "Unknown media test action.\n"); exit(2);
