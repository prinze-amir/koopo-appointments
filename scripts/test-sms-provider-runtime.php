<?php

if(PHP_SAPI!=='cli'||getenv('KOOPO_APPT_SMS_UAT')!=='1'){fwrite(STDERR,"Set KOOPO_APPT_SMS_UAT=1 and run with wp eval-file.\n");exit(2);}

use Koopo_Appointments\Calendar_Crypto;
use Koopo_Appointments\DB;
use Koopo_Appointments\SMS_Provider;
use Koopo_Appointments\SMS_Delivery_Receipts;
use Koopo_Appointments\SMS_Usage;
use Koopo_Appointments\SMS_Compliance;
use Koopo_Appointments\Transactional_SMS;

function sms_uat_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
global $wpdb;

$options=[
  SMS_Provider::OPTION_ENABLED,SMS_Provider::OPTION_PROVIDER,SMS_Provider::OPTION_BREVO_API_KEY,SMS_Provider::OPTION_BREVO_SENDER,
  SMS_Provider::OPTION_TWILIO_ACCOUNT_SID,SMS_Provider::OPTION_TWILIO_API_KEY_SID,SMS_Provider::OPTION_TWILIO_API_KEY_SECRET,
  SMS_Provider::OPTION_TWILIO_MESSAGING_SERVICE_SID,SMS_Provider::OPTION_TWILIO_FROM_NUMBER,
  SMS_Usage::OPTION_PAUSED,SMS_Usage::OPTION_DAILY_LIMIT_ENABLED,SMS_Usage::OPTION_DAILY_LIMIT,
  SMS_Usage::OPTION_MONTHLY_LIMIT_ENABLED,SMS_Usage::OPTION_MONTHLY_LIMIT,
  SMS_Delivery_Receipts::OPTION_WEBHOOK_TOKEN,
];
$original=[];foreach($options as $option)$original[$option]=['exists'=>get_option($option,null)!==null,'value'=>get_option($option)];
$requests=[];
$usage_time=static fn()=>strtotime('2099-06-15 12:00:00 UTC');
$mock=static function($preempt,$args,$url)use(&$requests){
  $requests[]=['url'=>$url,'headers'=>$args['headers']??[],'body'=>$args['body']??null];
  if(strpos($url,'api.brevo.com/v3/account')!==false)return ['headers'=>[],'body'=>wp_json_encode(['plan'=>[['type'=>'sms','credits'=>73,'creditsType'=>'sendLimit']]]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
  if(strpos($url,'api.brevo.com')!==false)return ['headers'=>[],'body'=>wp_json_encode(['messageId'=>'1511882900176220']),'response'=>['code'=>201,'message'=>'Created'],'cookies'=>[],'filename'=>null];
  if(strpos($url,'api.twilio.com')!==false)return ['headers'=>[],'body'=>wp_json_encode(['sid'=>'SM'.str_repeat('a',32),'status'=>'queued']),'response'=>['code'=>201,'message'=>'Created'],'cookies'=>[],'filename'=>null];
  return new WP_Error('unexpected_sms_url','Unexpected SMS URL.');
};
add_filter('pre_http_request',$mock,10,3);

try{
  DB::maybe_upgrade();
  add_filter('koopo_appt_sms_usage_timestamp',$usage_time);
  update_option(SMS_Usage::OPTION_PAUSED,0,false);update_option(SMS_Usage::OPTION_DAILY_LIMIT_ENABLED,1,false);update_option(SMS_Usage::OPTION_DAILY_LIMIT,1,false);
  update_option(SMS_Usage::OPTION_MONTHLY_LIMIT_ENABLED,1,false);update_option(SMS_Usage::OPTION_MONTHLY_LIMIT,2,false);
  update_option(SMS_Provider::OPTION_ENABLED,0,false);
  $disabled=SMS_Provider::send('+13135550199','Test');sms_uat_expect(!$disabled['accepted']&&$disabled['error_code']==='sms_disabled','Disabled transport did not fail closed.');

  update_option(SMS_Provider::OPTION_ENABLED,1,false);update_option(SMS_Provider::OPTION_PROVIDER,'brevo',false);
  update_option(SMS_Provider::OPTION_BREVO_API_KEY,Calendar_Crypto::encrypt(['secret'=>'xkeysib-uat-secret']),false);update_option(SMS_Provider::OPTION_BREVO_SENDER,'Koopo',false);
  $brevo=SMS_Provider::send('+13135550199','Koopo UAT',['type'=>'admin_test']);sms_uat_expect($brevo['accepted']&&$brevo['provider_message_id']==='1511882900176220','Brevo acceptance parsing failed.');
  $brevo_request=$requests[count($requests)-1];sms_uat_expect($brevo_request['url']==='https://api.brevo.com/v3/transactionalSMS/send','Brevo used an unexpected endpoint.');
  sms_uat_expect(($brevo_request['headers']['api-key']??'')==='xkeysib-uat-secret','Brevo credential was not decrypted only for the server request.');
  $brevo_body=json_decode((string)$brevo_request['body'],true);sms_uat_expect(!empty($brevo_body['webUrl'])&&strpos((string)$brevo_body['webUrl'],'/appointments/sms/brevo/')!==false,'Brevo receipt URL is missing.');
  SMS_Delivery_Receipts::apply_event(['messageId'=>'1511882900176220','event'=>'delivered','ts_event'=>time()]);
  $receipt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::sms_receipts_table().' WHERE provider=%s AND provider_message_id=%s','brevo','1511882900176220'));
  sms_uat_expect($receipt&&$receipt->status==='delivered'&&!empty($receipt->delivered_at),'Brevo delivered receipt was not persisted.');
  $usage=SMS_Usage::usage();sms_uat_expect($usage['daily']['sent']===1&&$usage['daily']['remaining']===0,'Accepted SMS was not counted against the daily limit.');
  $limited=SMS_Provider::send('+13135550199','Koopo UAT 2',['type'=>'admin_test']);sms_uat_expect(!$limited['accepted']&&$limited['error_code']==='sms_daily_limit_reached','Daily SMS hard limit was not enforced.');
  $credits=SMS_Provider::brevo_credits(true);sms_uat_expect($credits['status']==='available'&&$credits['credits']===73,'Brevo SMS credits were not parsed.');

  $reply_phone='+13135550998';
  $wpdb->insert(DB::booking_invites_table(),['booking_id'=>999998,'created_by'=>1,'token_hash'=>hash('sha256','sms-uat-consent'),'channels'=>'sms','status'=>'pending','sms_consent_at'=>current_time('mysql',true),'sms_consent_by'=>1,'sms_consent_version'=>SMS_Compliance::DISCLOSURE_VERSION,'sms_consent_phone'=>$reply_phone,'sms_consent_method'=>SMS_Compliance::CONSENT_METHOD,'sms_consent_status'=>'active','sms_consent_disclosure'=>SMS_Compliance::DISCLOSURE,'expires_at'=>gmdate('Y-m-d H:i:s',time()+HOUR_IN_SECONDS)]);
  $reply_invitation_id=(int)$wpdb->insert_id;sms_uat_expect($reply_invitation_id>0,'SMS reply consent fixture failed.');
  SMS_Delivery_Receipts::apply_event(['messageId'=>'uat-help-reply','event'=>'reply','to'=>$reply_phone,'reply'=>'HELP','ts_event'=>time()]);
  sms_uat_expect(!SMS_Compliance::is_suppressed($reply_phone),'HELP incorrectly suppressed the recipient.');
  SMS_Delivery_Receipts::apply_event(['messageId'=>'uat-stop-reply','event'=>'reply','to'=>$reply_phone,'reply'=>'STOP','ts_event'=>time()]);
  sms_uat_expect(SMS_Compliance::is_suppressed($reply_phone),'STOP did not create a permanent suppression.');
  SMS_Delivery_Receipts::apply_event(['messageId'=>'uat-start-reply','event'=>'reply','to'=>$reply_phone,'reply'=>'START','ts_event'=>time()]);
  sms_uat_expect(SMS_Compliance::is_suppressed($reply_phone),'START silently erased Koopo suppression.');
  $suppressed=Transactional_SMS::send($reply_phone,'Should not send',['booking_id'=>999999,'invitation_id'=>999999,'attempt'=>1,'guest_only'=>true,'consent_recorded'=>true]);
  sms_uat_expect(!$suppressed['accepted']&&$suppressed['error_code']==='sms_recipient_suppressed','Suppressed recipient did not fail closed before provider delivery.');

  update_option(SMS_Usage::OPTION_DAILY_LIMIT,10,false);
  update_option(SMS_Provider::OPTION_PROVIDER,'twilio',false);
  sms_uat_expect(SMS_Provider::provider()==='brevo','Unsupported Twilio selection did not fail closed to Brevo.');
  update_option(SMS_Usage::OPTION_PAUSED,1,false);$paused=SMS_Provider::send('+13135550199','Paused');sms_uat_expect(!$paused['accepted']&&$paused['error_code']==='sms_globally_paused','Emergency SMS pause was not enforced.');

  echo wp_json_encode(['ok'=>true,'disabled'=>'failed_closed','brevo'=>'accepted','brevo_receipt'=>'delivered','brevo_credits'=>73,'daily_limit'=>'enforced','pause'=>'enforced','suppression'=>'stop_help_start_verified','twilio'=>'disabled_until_webhooks','requests'=>count($requests)])."\n";
}finally{
  remove_filter('pre_http_request',$mock,10);
  remove_filter('koopo_appt_sms_usage_timestamp',$usage_time);
  global $wpdb;$wpdb->query("DELETE FROM ".DB::sms_usage_table()." WHERE period_start>='2099-01-01 00:00:00'");
  $wpdb->query("DELETE FROM ".DB::sms_receipts_table()." WHERE provider_message_id IN ('1511882900176220','uat-help-reply','uat-stop-reply','uat-start-reply','SM".str_repeat('a',32)."')");
  $wpdb->delete(DB::sms_suppressions_table(),['phone_hash'=>SMS_Compliance::phone_hash('+13135550998')]);
  $wpdb->delete(DB::booking_invites_table(),['booking_id'=>999998]);
  foreach($original as $option=>$state){if($state['exists'])update_option($option,$state['value'],false);else delete_option($option);}
}
