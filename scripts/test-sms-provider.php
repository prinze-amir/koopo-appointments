<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $file)use($root):string{$value=file_get_contents($root.'/'.$file);if($value===false)throw new RuntimeException('Unable to read '.$file);return $value;};
$expect=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$provider=$read('includes/notifications/class-kgaw-sms-provider.php');
$usage=$read('includes/notifications/class-kgaw-sms-usage.php');
$db=$read('includes/core/class-kgaw-db.php');
$admin=$read('includes/admin/class-kgaw-admin-settings.php');
$plugin=$read('koopo-geo-appointments-wc.php');
$loader=$read('includes/core/class-kgaw-module-loader.php');
$vendor_template=$read('templates/dokan/appointments.php');
$vendor_js=$read('assets/vendor-appointments.js');
$compliance=$read('includes/notifications/class-kgaw-sms-compliance.php');
$transactional=$read('includes/notifications/class-kgaw-transactional-sms.php');

$receipts=$read('includes/notifications/class-kgaw-sms-delivery-receipts.php');
$expect(strpos($plugin,"Version: 0.13.0")!==false&&strpos($loader,"[SMS_Provider::class, 'init']")!==false&&strpos($loader,"[SMS_Delivery_Receipts::class, 'init']")!==false,'SMS release or provider initialization is missing.');
$expect(strpos($provider,'https://api.brevo.com/v3/transactionalSMS/send')!==false&&strpos($provider,'/transactionalSMS/sms')===false,'Brevo does not use the current transactional SMS endpoint.');
$expect(strpos($provider,'https://api.brevo.com/v3/account')!==false&&strpos($provider,"['type'] ?? '')) === 'sms'")!==false,'Brevo SMS credit lookup is missing.');
$expect(strpos($provider,"const SUPPORTED_PROVIDERS = ['brevo']")!==false&&strpos($admin,'<option value="twilio"')===false,'Incomplete Twilio support remains selectable in production.');
$expect(strpos($provider,"Calendar_Crypto::encrypt(['secret'=>\$value])")!==false&&strpos($provider,"autocomplete=\"new-password\"")===false,'SMS secret encryption contract is missing.');
$expect(strpos($provider,"\$status === 408 || \$status === 429 || \$status >= 500")!==false,'Retryable provider failures are not classified.');
$expect(strpos($provider,"'accepted'=>true")!==false&&strpos($provider,"'provider_message_id'=>\$message_id")!==false,'Provider acceptance requires no message ID.');
$expect(strpos($admin,'SMS Delivery')!==false&&strpos($admin,'koopo_appt_sms_test')!==false,'SMS settings or test-send UI is missing.');
$expect(strpos($admin,'Brevo SMS credits')!==false&&strpos($admin,'Enforce daily limit')!==false&&strpos($admin,'Enforce monthly limit')!==false,'SMS usage meters or hard-limit controls are missing.');
$expect(strpos($usage,'START TRANSACTION')!==false&&strpos($usage,'FOR UPDATE')!==false&&strpos($usage,"sms_' . \$bucket['type'] . '_limit_reached")!==false,'SMS limits are not reserved atomically.');
$expect(strpos($db,"const VERSION = '4.8'")!==false&&strpos($db,'koopo_appt_sms_usage')!==false&&strpos($db,'koopo_appt_sms_receipts')!==false&&strpos($db,'koopo_appt_sms_suppressions')!==false,'SMS usage, receipt, or suppression schema is missing.');
$expect(strpos($provider,"\$body['webUrl'] = \$webhook_url")!==false&&strpos($receipts,'hash_equals($expected, $provided)')!==false,'Secured Brevo delivery receipts are missing.');
$expect(strpos($receipts,"'delivered'")!==false&&strpos($receipts,"'skipped'")!==false&&strpos($receipts,"'hard_bounce'")!==false,'SMS receipt lifecycle coverage is incomplete.');
$expect(strpos($receipts,"event['content']")===false&&strpos($db,'reply_content')===false,'SMS receipt handler persists private webhook content.');
$expect(strpos($receipts,"'unsubscribe'=>'unsubscribed'")!==false&&strpos($receipts,'SMS_Compliance::record_reply')!==false,'Brevo STOP/HELP webhook processing is missing.');
$expect(strpos($compliance,"hash_hmac('sha256'")!==false&&strpos($db,'phone_hash CHAR(64)')!==false&&strpos($db,'phone_number VARCHAR')===false,'Suppression storage is not privacy safe.');
$expect(strpos($compliance,"const HELP_KEYWORDS = ['help', 'info']")!==false&&strpos($compliance,"const STOP_KEYWORDS")!==false,'STOP and HELP keywords are not classified.');
$expect(strpos($transactional,'SMS_Compliance::is_suppressed')!==false&&strpos($transactional,'sms_recipient_suppressed')!==false,'Transactional SMS does not fail closed for suppressed phones.');
$expect(strpos($provider,'SMS_Compliance::is_suppressed($phone)')!==false&&strpos($provider,"self::failure('sms_recipient_suppressed'")!==false,'SMS suppression is not enforced at the transport boundary.');
$expect(strpos($receipts,'phone_has_consent_history')!==false&&strpos($receipts,'INSERT IGNORE INTO')===false,'Inbound SMS events are not correlated to Koopo consent or known receipts.');
$expect(strpos($admin,'Remove saved key')!==false&&strpos($admin,'Remove saved secret')!==false,'Credential removal controls are missing.');
$expect(strpos($admin,'Guest')===false||strpos($admin,"guest's consent")!==false,'SMS settings do not explain the consent boundary.');
$expect(strpos($vendor_template,"SMS_Provider::status()")!==false&&strpos($vendor_template,"disabled(empty(\$koopo_sms_status['ready']))")!==false,'Vendors can select SMS while the transport is not ready.');
$expect(strpos($vendor_js,"smsRequested && !smsAccepted")!==false,'Vendor UI does not surface provider-rejected SMS delivery.');

echo "sms provider tests passed\n";
