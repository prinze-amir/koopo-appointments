<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $file)use($root):string{$value=file_get_contents($root.'/'.$file);if($value===false)throw new RuntimeException('Unable to read '.$file);return $value;};
$expect=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$provider=$read('includes/notifications/class-kgaw-sms-provider.php');
$admin=$read('includes/admin/class-kgaw-admin-settings.php');
$plugin=$read('koopo-geo-appointments-wc.php');
$vendor_template=$read('templates/dokan/appointments.php');
$vendor_js=$read('assets/vendor-appointments.js');

$expect(strpos($plugin,"Version: 0.10.1")!==false&&strpos($plugin,'SMS_Provider::init()')!==false,'SMS release or provider initialization is missing.');
$expect(strpos($provider,'https://api.brevo.com/v3/transactionalSMS/send')!==false&&strpos($provider,'/transactionalSMS/sms')===false,'Brevo does not use the current transactional SMS endpoint.');
$expect(strpos($provider,'https://api.twilio.com/2010-04-01/Accounts/')!==false&&strpos($provider,"'Authorization'=>'Basic '")!==false,'Twilio message transport or authentication is missing.');
$expect(strpos($provider,"Calendar_Crypto::encrypt(['secret'=>\$value])")!==false&&strpos($provider,"autocomplete=\"new-password\"")===false,'SMS secret encryption contract is missing.');
$expect(strpos($provider,"\$status === 408 || \$status === 429 || \$status >= 500")!==false,'Retryable provider failures are not classified.');
$expect(strpos($provider,"'accepted'=>true")!==false&&strpos($provider,"'provider_message_id'=>\$message_id")!==false,'Provider acceptance requires no message ID.');
$expect(strpos($admin,'SMS Delivery')!==false&&strpos($admin,'koopo_appt_sms_test')!==false,'SMS settings or test-send UI is missing.');
$expect(strpos($admin,'Remove saved key')!==false&&strpos($admin,'Remove saved secret')!==false,'Credential removal controls are missing.');
$expect(strpos($admin,'Guest')===false||strpos($admin,"guest's consent")!==false,'SMS settings do not explain the consent boundary.');
$expect(strpos($vendor_template,"SMS_Provider::status()")!==false&&strpos($vendor_template,"disabled(empty(\$koopo_sms_status['ready']))")!==false,'Vendors can select SMS while the transport is not ready.');
$expect(strpos($vendor_js,"smsRequested && !smsAccepted")!==false,'Vendor UI does not surface provider-rejected SMS delivery.');

echo "sms provider tests passed\n";
