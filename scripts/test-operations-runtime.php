<?php
/** Guarded beta fixture for external busy, waitlist, clients, forms, and signatures. */
if ('1' !== getenv('KOOPO_APPT_OPERATIONS_UAT')) { fwrite(STDERR, "Set KOOPO_APPT_OPERATIONS_UAT=1 and run with wp eval-file.\n"); exit(2); }

use Koopo_Appointments\Bookings;
use Koopo_Appointments\Calendar_Busy;
use Koopo_Appointments\Calendar_Repository;
use Koopo_Appointments\Client_Records;
use Koopo_Appointments\DB;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Resources;
use Koopo_Appointments\Services_API;
use Koopo_Appointments\Waitlist;

function operations_expect($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function operations_request(string $method, array $params = [], array $body = []): WP_REST_Request { $r=new WP_REST_Request($method);$r->set_url_params($params);$r->set_query_params($params);$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));return $r; }

$created=['users'=>[],'provider'=>0,'resource'=>0,'service'=>0,'booking'=>0,'waitlist'=>0,'form'=>0,'source'=>0];
add_filter('pre_wp_mail', static fn()=>true, PHP_INT_MAX);
try {
  $stamp=gmdate('YmdHis').wp_rand(100,999);
  foreach(['provider','customer'] as $role){$id=wp_insert_user(['user_login'=>'koopo_ops_'.$role.'_'.$stamp,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'koopo-ops-'.$role.'-'.$stamp.'@example.invalid','display_name'=>'Koopo Ops '.ucfirst($role),'role'=>'subscriber']);operations_expect(!is_wp_error($id),'User fixture failed.');$created['users'][$role]=(int)$id;}
  wp_set_current_user($created['users']['provider']);
  $created['provider']=(int)wp_insert_post(['post_type'=>Provider_Profiles::POST_TYPE,'post_status'=>'publish','post_title'=>'Koopo Operations UAT','post_author'=>$created['users']['provider']]);
  update_post_meta($created['provider'],Provider_Profiles::META_SERVICE_MODES,['at_location']);
  update_post_meta($created['provider'],Provider_Profiles::META_CITY,'Detroit');
  $created['resource']=Resources::ensure_for_provider($created['provider']);operations_expect($created['resource']>0,'Resource fixture failed.');
  $created['service']=(int)wp_insert_post(['post_type'=>'koopo_service','post_status'=>'publish','post_title'=>'Operations UAT Service','post_author'=>$created['users']['provider']]);
  update_post_meta($created['service'],Services_API::META_PROVIDER_ID,$created['provider']);update_post_meta($created['service'],Services_API::META_DURATION,60);update_post_meta($created['service'],Services_API::META_PRICE,0);update_post_meta($created['service'],Services_API::META_STATUS,'active');update_post_meta($created['service'],Resources::META_RESOURCE_ID,$created['resource']);

  $start=(new DateTimeImmutable('+9 days 15:00:00',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');$end=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');
  global $wpdb;
  $wpdb->insert(Calendar_Repository::busy_sources_table(),['resource_id'=>$created['resource'],'connection_id'=>999999,'user_id'=>$created['users']['provider'],'provider'=>'microsoft','external_calendar_id'=>'operations-uat','external_calendar_name'=>'Private calendar title not retained in blocks','external_timezone'=>'UTC','busy_mode'=>'respect_provider','refresh_minutes'=>15,'enabled'=>1]);$created['source']=(int)$wpdb->insert_id;
  $wpdb->insert(Calendar_Repository::busy_blocks_table(),['source_id'=>$created['source'],'resource_id'=>$created['resource'],'external_event_key'=>hash('sha256','external-private-event-id'),'starts_at_utc'=>$start,'ends_at_utc'=>$end,'is_all_day'=>0]);
  operations_expect(Calendar_Busy::has_conflict($created['resource'],$start,$end,'UTC'),'External busy conflict was not enforced.');$ranges=Calendar_Busy::ranges_for_local_date($created['resource'],substr($start,0,10),'UTC');operations_expect(($ranges[0]['label']??'')==='Unavailable','External busy privacy label changed.');operations_expect(strpos(wp_json_encode($ranges),'Private calendar')===false,'External calendar content leaked into availability.');

  wp_set_current_user($created['users']['customer']);
  $join=Waitlist::join(operations_request('POST',[],['service_id'=>$created['service'],'date_from'=>substr($start,0,10),'date_to'=>substr($start,0,10),'preferred_days'=>[strtolower(substr(date('D',strtotime($start)),0,3))],'earliest_time'=>'14:00','latest_time'=>'17:00','channels'=>['email','push']]));operations_expect($join instanceof WP_REST_Response&&$join->get_status()===201,'Waitlist join failed.');$created['waitlist']=(int)$join->get_data()['id'];

  $wpdb->insert(DB::table(),['listing_id'=>null,'listing_author_id'=>$created['users']['provider'],'provider_id'=>$created['provider'],'resource_id'=>$created['resource'],'payee_user_id'=>$created['users']['provider'],'service_id'=>(string)$created['service'],'customer_id'=>$created['users']['customer'],'customer_name'=>'Koopo Ops Customer','customer_email'=>'koopo-ops-customer-'.$stamp.'@example.invalid','customer_phone'=>'5555550100','start_datetime'=>$start,'end_datetime'=>$end,'timezone'=>'UTC','price'=>0,'currency'=>'USD','status'=>'cancelled']);$created['booking']=(int)$wpdb->insert_id;$opening=Bookings::get_booking($created['booking']);Waitlist::handle_opening($created['booking'],'cancelled',$opening);
  $offer=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_offers_table().' WHERE waitlist_id=%d',$created['waitlist']));operations_expect($offer&&$offer->status==='offered','Cancellation did not create an expiring offer.');operations_expect(strtotime($offer->expires_at.' UTC')>time(),'Waitlist offer did not receive an expiry.');
  $offer_delivery=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".DB::notification_deliveries_table()." WHERE booking_id=%d AND event_name=%s AND channel='inbox'",$created['booking'],'waitlist_offer_'.(int)$offer->id));operations_expect($offer_delivery&&$offer_delivery->status==='sent'&&(int)$offer_delivery->provider_message_id>0,'Waitlist offer did not create a BuddyBoss inbox delivery.');
  if(function_exists('bp_messages_get_meta')){$linked=bp_messages_get_meta((int)$offer_delivery->provider_message_id,'linked_entity',true);operations_expect(is_array($linked)&&(int)($linked['offerId']??0)===(int)$offer->id,'Waitlist inbox message metadata is incomplete.');}

  wp_set_current_user($created['users']['provider']);
  $form=Client_Records::save_form(operations_request('POST',[],['resource_id'=>$created['resource'],'service_id'=>$created['service'],'title'=>'UAT Consent','fields'=>[['id'=>'allergies','label'=>'Allergies?','type'=>'textarea','required'=>true]],'requires_signature'=>true,'send_hours_before'=>24]));operations_expect($form instanceof WP_REST_Response&&$form->get_status()===201,'Client form creation failed.');$created['form']=(int)$form->get_data()['id'];
  $wpdb->update(DB::table(),['status'=>'confirmed'],['id'=>$created['booking']]);$confirmed=Bookings::get_booking($created['booking']);Client_Records::capture_booking($created['booking'],$confirmed);
  $client_id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::clients_table().' WHERE resource_id=%d AND wp_user_id=%d',$created['resource'],$created['users']['customer']));operations_expect($client_id>0,'Confirmed booking did not create a client record.');
  wp_set_current_user($created['users']['customer']);$forms=Client_Records::booking_forms(operations_request('GET',['booking_id'=>$created['booking']]));operations_expect(count($forms->get_data())===1&&$forms->get_data()[0]['submission_status']==='pending','Required customer form was not assigned.');
  $submitted=Client_Records::submit_form(operations_request('POST',['booking_id'=>$created['booking'],'form_id'=>$created['form']],['answers'=>['allergies'=>'None'],'signature_name'=>'Koopo Ops Customer','consent_text'=>'Customer-controlled replacement']));operations_expect($submitted instanceof WP_REST_Response&&$submitted->get_status()===200,'Signed form submission failed.');$signature=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::form_submissions_table().' WHERE booking_id=%d AND form_id=%d',$created['booking'],$created['form']));operations_expect($signature&&strlen((string)$signature->signature_hash)===64&&$signature->signed_at,'Electronic signature integrity record is incomplete.');operations_expect((string)$signature->consent_text===Client_Records::CONSENT_DISCLOSURE,'Customer-controlled consent text replaced the immutable disclosure.');
  $duplicate=Client_Records::submit_form(operations_request('POST',['booking_id'=>$created['booking'],'form_id'=>$created['form']],['answers'=>['allergies'=>'Changed'],'signature_name'=>'Koopo Ops Customer']));operations_expect(is_wp_error($duplicate)&&$duplicate->get_error_code()==='form_already_completed','Duplicate intake completion was not rejected.');
  echo wp_json_encode(['ok'=>true,'external_busy'=>'blocked_as_unavailable','waitlist'=>'expiring_offer_created','waitlist_inbox'=>'delivered','client'=>'captured','form'=>'assigned_and_signed']);
} finally {
  global $wpdb;
  if($created['source'])$wpdb->delete(Calendar_Repository::busy_blocks_table(),['source_id'=>$created['source']]);if($created['source'])$wpdb->delete(Calendar_Repository::busy_sources_table(),['id'=>$created['source']]);
  if($created['waitlist']){$wpdb->delete(DB::waitlist_offers_table(),['waitlist_id'=>$created['waitlist']]);$wpdb->delete(DB::waitlist_table(),['id'=>$created['waitlist']]);}
  if($created['booking']){$wpdb->delete(DB::notification_deliveries_table(),['booking_id'=>$created['booking']]);$wpdb->delete(DB::form_submissions_table(),['booking_id'=>$created['booking']]);$wpdb->delete(DB::table(),['id'=>$created['booking']]);}
  if($created['form'])$wpdb->delete(DB::client_forms_table(),['id'=>$created['form']]);if($created['resource'])$wpdb->delete(DB::clients_table(),['resource_id'=>$created['resource']]);
  if($created['service'])wp_delete_post($created['service'],true);if($created['resource'])$wpdb->delete(DB::resources_table(),['id'=>$created['resource']]);if($created['provider'])wp_delete_post($created['provider'],true);
  require_once ABSPATH.'wp-admin/includes/user.php';foreach($created['users'] as $id)if($id)wp_delete_user($id);
}
