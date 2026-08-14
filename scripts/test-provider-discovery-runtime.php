<?php
/** Guarded beta fixture for provider discovery, category, and BuddyBoss UI acceptance. */
if ('1' !== getenv('KOOPO_APPT_DISCOVERY_UAT')) { fwrite(STDERR, "Set KOOPO_APPT_DISCOVERY_UAT=1.\n"); return; }
$action = sanitize_key((string) getenv('KOOPO_APPT_DISCOVERY_ACTION'));
if ('prepare' === $action) {
  $stamp = gmdate('YmdHis');
  $user_id = wp_insert_user(['user_login'=>'koopo_discovery_uat_'.$stamp,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'koopo-discovery-'.$stamp.'@example.invalid','display_name'=>'Alex Morgan','role'=>'subscriber']);
  if (is_wp_error($user_id)) throw new RuntimeException($user_id->get_error_message());
  update_user_meta($user_id, '_koopo_date_of_birth', '1990-01-01'); update_user_meta($user_id, '_koopo_age_status', 'adult');
  $provider_id = wp_insert_post(['post_type'=>'koopo_provider','post_status'=>'publish','post_title'=>'Alex Morgan','post_excerpt'=>'Independent barber and grooming specialist','post_content'=>'Focused cuts, grooming, and appointment-only service in downtown Detroit.','post_author'=>$user_id]);
  $profile_category=get_term_by('slug','barbering-grooming','koopo_service_category');
  \Koopo_Appointments\Provider_Profiles::save_meta($provider_id, ['headline'=>'Independent barber and grooming specialist','category_id'=>$profile_category?(int)$profile_category->term_id:0,'service_modes'=>['at_location','mobile'],'location_name'=>'Morgan Grooming Studio','address'=>'1 Campus Martius','city'=>'Detroit','region'=>'Michigan','postal_code'=>'48226','country'=>'United States','latitude'=>'42.3314','longitude'=>'-83.0458','location_public'=>true]);
  update_post_meta($provider_id, '_koopo_appt_enabled', '1'); \Koopo_Appointments\Resources::ensure_for_provider($provider_id);
  $definitions = [['Signature Cut',45,45],['Fresh Lineup',25,25],['Scalp Care Session',60,50]];
  $service_ids=[]; $product_ids=[];
  foreach ($definitions as [$title,$price,$duration]) {
    $product=new WC_Product_Simple();$product->set_name($title);$product->set_regular_price((string)$price);$product->set_virtual(true);$product->set_status('publish');$product_id=$product->save();
    $service_id=wp_insert_post(['post_type'=>'koopo_service','post_status'=>'publish','post_title'=>$title,'post_author'=>$user_id]);
    update_post_meta($service_id,'_koopo_provider_id',$provider_id);update_post_meta($service_id,'_koopo_service_price',$price);update_post_meta($service_id,'_koopo_service_duration_minutes',$duration);update_post_meta($service_id,'_koopo_service_status','active');update_post_meta($service_id,'_koopo_service_description','A focused appointment tailored to your needs.');update_post_meta($service_id,'_koopo_wc_product_id',$product_id);
    \Koopo_Appointments\Bookable_Listings_API::sync_service($service_id);
    $service_ids[]=$service_id;$product_ids[]=$product_id;
  }
  update_user_meta($user_id,'_koopo_discovery_fixture',['provider'=>$provider_id,'services'=>$service_ids,'products'=>$product_ids]);
  echo wp_json_encode(['userId'=>$user_id,'providerId'=>$provider_id,'archive'=>get_post_type_archive_link('koopo_provider'),'profile'=>get_permalink($provider_id),'member'=>function_exists('bp_core_get_user_domain')?bp_core_get_user_domain($user_id).'services/':'']);
  return;
}
if ('verify' === $action) {
  $user_id=absint(getenv('KOOPO_APPT_DISCOVERY_USER_ID'));$fixture=(array)get_user_meta($user_id,'_koopo_discovery_fixture',true);$provider_id=absint($fixture['provider']??0);$service_ids=array_map('absint',(array)($fixture['services']??[]));
  if(!$provider_id||!$service_ids)throw new RuntimeException('Discovery fixture not found.');
  $barber=get_term_by('slug','barbering-grooming','koopo_service_category');$wellness=get_term_by('slug','wellness','koopo_service_category');
  if(!$barber||!$wellness)throw new RuntimeException('Expected seeded categories are missing.');
  $assert=static function($condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
  $provider_terms=wp_get_object_terms($provider_id,'koopo_service_category',['fields'=>'ids']);
  $assert($provider_terms===[(int)$barber->term_id],'Profile does not own its selected category.');
  foreach($service_ids as $service_id)$assert(wp_get_object_terms($service_id,'koopo_service_category',['fields'=>'ids'])===[],'A service still owns an individual category.');
  $public=\Koopo_Appointments\Provider_Profiles::public_services($provider_id);$assert(count($public)===count($service_ids),'Public provider services are incomplete.');
  foreach($public as $service)$assert((int)($service['categories'][0]['id']??0)===(int)$barber->term_id,'Public service did not inherit the profile category.');
  $request=new WP_REST_Request('GET','/koopo/v1/services/by-provider/'.$provider_id);$request->set_url_params(['id'=>$provider_id]);$response=\Koopo_Appointments\Services_List::get_services_for_provider($request);$rest=(array)$response->get_data();
  foreach($rest as $service)$assert(($service['category_ids']??[])===[(int)$barber->term_id],'Provider service REST output did not inherit the profile category.');
  $query=new WP_Query(['post_type'=>'koopo_provider','post_status'=>'publish','fields'=>'ids','tax_query'=>[['taxonomy'=>'koopo_service_category','field'=>'slug','terms'=>'barbering-grooming']]]);$assert(in_array($provider_id,array_map('intval',$query->posts),true),'Category archive query does not include the profile.');
  \Koopo_Appointments\Provider_Profiles::save_meta($provider_id,['category_id'=>(int)$wellness->term_id]);
  global $wpdb;$rows=$wpdb->get_col($wpdb->prepare('SELECT category_ids FROM '.\Koopo_Appointments\DB::service_index_table().' WHERE provider_id = %d ORDER BY service_id',$provider_id));
  $assert(count($rows)===count($service_ids),'Indexed provider services are incomplete.');foreach($rows as $category_ids)$assert(json_decode($category_ids,true)===[(int)$wellness->term_id],'Service index did not follow a profile category change.');
  \Koopo_Appointments\Provider_Profiles::save_meta($provider_id,['category_id'=>(int)$barber->term_id]);
  echo wp_json_encode(['verified'=>true,'userId'=>$user_id,'providerId'=>$provider_id,'serviceCount'=>count($service_ids),'category'=>'barbering-grooming','legacyServiceTermsPreserved'=>true]);return;
}
if ('cleanup' === $action) {
  $user_id=absint(getenv('KOOPO_APPT_DISCOVERY_USER_ID'));$fixture=(array)get_user_meta($user_id,'_koopo_discovery_fixture',true);$provider_id=absint($fixture['provider']??0);
  foreach ((array)($fixture['services']??[]) as $id) wp_delete_post(absint($id),true);foreach ((array)($fixture['products']??[]) as $id) wp_delete_post(absint($id),true);
  global $wpdb;if($provider_id){$wpdb->delete(\Koopo_Appointments\DB::resources_table(),['subject_type'=>'provider','subject_id'=>$provider_id],['%s','%d']);wp_delete_post($provider_id,true);}require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user_id);
  echo wp_json_encode(['cleaned'=>true,'userId'=>$user_id,'providerId'=>$provider_id]);return;
}
fwrite(STDERR,"Unknown discovery action.\n");
