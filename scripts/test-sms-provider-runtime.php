<?php

if(PHP_SAPI!=='cli'||getenv('KOOPO_APPT_SMS_UAT')!=='1'){fwrite(STDERR,"Set KOOPO_APPT_SMS_UAT=1 and run with wp eval-file.\n");exit(2);}

use Koopo_Appointments\Calendar_Crypto;
use Koopo_Appointments\SMS_Provider;

function sms_uat_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$options=[
  SMS_Provider::OPTION_ENABLED,SMS_Provider::OPTION_PROVIDER,SMS_Provider::OPTION_BREVO_API_KEY,SMS_Provider::OPTION_BREVO_SENDER,
  SMS_Provider::OPTION_TWILIO_ACCOUNT_SID,SMS_Provider::OPTION_TWILIO_API_KEY_SID,SMS_Provider::OPTION_TWILIO_API_KEY_SECRET,
  SMS_Provider::OPTION_TWILIO_MESSAGING_SERVICE_SID,SMS_Provider::OPTION_TWILIO_FROM_NUMBER,
];
$original=[];foreach($options as $option)$original[$option]=['exists'=>get_option($option,null)!==null,'value'=>get_option($option)];
$requests=[];
$mock=static function($preempt,$args,$url)use(&$requests){
  $requests[]=['url'=>$url,'headers'=>$args['headers']??[],'body'=>$args['body']??null];
  if(strpos($url,'api.brevo.com')!==false)return ['headers'=>[],'body'=>wp_json_encode(['messageId'=>'1511882900176220']),'response'=>['code'=>201,'message'=>'Created'],'cookies'=>[],'filename'=>null];
  if(strpos($url,'api.twilio.com')!==false)return ['headers'=>[],'body'=>wp_json_encode(['sid'=>'SM'.str_repeat('a',32),'status'=>'queued']),'response'=>['code'=>201,'message'=>'Created'],'cookies'=>[],'filename'=>null];
  return new WP_Error('unexpected_sms_url','Unexpected SMS URL.');
};
add_filter('pre_http_request',$mock,10,3);

try{
  update_option(SMS_Provider::OPTION_ENABLED,0,false);
  $disabled=SMS_Provider::send('+13135550199','Test');sms_uat_expect(!$disabled['accepted']&&$disabled['error_code']==='sms_disabled','Disabled transport did not fail closed.');

  update_option(SMS_Provider::OPTION_ENABLED,1,false);update_option(SMS_Provider::OPTION_PROVIDER,'brevo',false);
  update_option(SMS_Provider::OPTION_BREVO_API_KEY,Calendar_Crypto::encrypt(['secret'=>'xkeysib-uat-secret']),false);update_option(SMS_Provider::OPTION_BREVO_SENDER,'Koopo',false);
  $brevo=SMS_Provider::send('+13135550199','Koopo UAT',['type'=>'admin_test']);sms_uat_expect($brevo['accepted']&&$brevo['provider_message_id']==='1511882900176220','Brevo acceptance parsing failed.');
  $brevo_request=$requests[count($requests)-1];sms_uat_expect($brevo_request['url']==='https://api.brevo.com/v3/transactionalSMS/send','Brevo used an unexpected endpoint.');
  sms_uat_expect(($brevo_request['headers']['api-key']??'')==='xkeysib-uat-secret','Brevo credential was not decrypted only for the server request.');

  update_option(SMS_Provider::OPTION_PROVIDER,'twilio',false);update_option(SMS_Provider::OPTION_TWILIO_ACCOUNT_SID,'AC'.str_repeat('1',32),false);
  update_option(SMS_Provider::OPTION_TWILIO_API_KEY_SID,'SK'.str_repeat('2',32),false);update_option(SMS_Provider::OPTION_TWILIO_API_KEY_SECRET,Calendar_Crypto::encrypt(['secret'=>'twilio-uat-secret']),false);
  update_option(SMS_Provider::OPTION_TWILIO_MESSAGING_SERVICE_SID,'MG'.str_repeat('3',32),false);update_option(SMS_Provider::OPTION_TWILIO_FROM_NUMBER,'',false);
  $twilio=SMS_Provider::send('+13135550199','Koopo UAT',['type'=>'admin_test']);sms_uat_expect($twilio['accepted']&&strpos($twilio['provider_message_id'],'SM')===0,'Twilio acceptance parsing failed.');
  $twilio_request=$requests[count($requests)-1];sms_uat_expect(strpos($twilio_request['url'],'/2010-04-01/Accounts/AC')!==false,'Twilio used an unexpected endpoint.');
  sms_uat_expect(($twilio_request['body']['MessagingServiceSid']??'')==='MG'.str_repeat('3',32)&&empty($twilio_request['body']['From']),'Twilio Messaging Service routing failed.');
  sms_uat_expect(strpos((string)($twilio_request['headers']['Authorization']??''),'Basic ')===0,'Twilio Basic authentication header is missing.');

  echo wp_json_encode(['ok'=>true,'disabled'=>'failed_closed','brevo'=>'accepted','twilio'=>'accepted','requests'=>count($requests)])."\n";
}finally{
  remove_filter('pre_http_request',$mock,10);
  foreach($original as $option=>$state){if($state['exists'])update_option($option,$state['value'],false);else delete_option($option);}
}
