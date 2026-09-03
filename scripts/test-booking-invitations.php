<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = static function(string $relative) use ($root): string {
  $contents = file_get_contents($root . '/' . $relative);
  if ($contents === false) throw new RuntimeException('Unable to read ' . $relative);
  return $contents;
};
$expect = static function(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$db = $source('includes/core/class-kgaw-db.php');
$invites = $source('includes/invitations/class-kgaw-booking-invitations.php');
$bookings = $source('includes/core/class-kgaw-bookings.php');
$public_js = $source('assets/appointments.js');
$public_ui = $source('includes/ui/class-kgaw-ui.php');
$waitlist = $source('includes/waitlist/class-kgaw-waitlist.php');
$messaging = $source('includes/notifications/class-kgaw-appointment-messaging.php');

$expect(strpos($db, "const VERSION = '4.9'") !== false, 'Invitation and notification schema version is not active.');
$expect(strpos($db, 'UNIQUE KEY token_hash (token_hash)') !== false, 'Invitation tokens are not uniquely hashed.');
$expect(strpos($db, 'token VARCHAR') === false, 'A raw invitation token appears in the database schema.');
$expect(strpos($invites, "hash('sha256', \$token)") !== false, 'Invitation token hashing is missing.');
$expect(strpos($invites, "add_rewrite_rule('^i/") !== false && strpos($invites, "add_rewrite_rule('^appointment-invite/") !== false, 'Short invitation route or legacy route compatibility is missing.');
$expect(strpos($invites, "home_url('/i/") !== false && strpos($invites, 'Koopo: Appointment invitation from ') !== false, 'Registered invitation copy or short URL is missing.');
$single_segment_fixture = 'Koopo: Appointment invitation from Provider. Review and checkout: https://koopo.us/i/' . str_repeat('A', 22) . '/. Reply STOP to opt out or HELP for help.';
$expect(strlen($single_segment_fixture) <= 160, 'Koopo invitation fixture exceeds one GSM-7 SMS segment.');
$expect(strpos($invites, 'random_bytes(16)') !== false, 'New invitation tokens are not sized for the registered one-segment SMS copy.');
$expect(strpos($invites, "'guest_only'=>true") !== false && strpos($invites, "'consent_recorded'=>true") !== false, 'Guest-only consented SMS metadata is missing.');
$expect(strpos($db, 'sms_consent_version') !== false && strpos($db, 'sms_consent_phone') !== false, 'Durable SMS consent evidence schema is missing.');
$compliance = $source('includes/notifications/class-kgaw-sms-compliance.php');
$vendor_template = $source('templates/dokan/appointments.php');
$vendor_api = $source('includes/vendor/class-kgaw-vendor-bookings-api.php');
$expect(strpos($compliance, "const CONSENT_METHOD = 'voice'") !== false && strpos($compliance, 'Reply STOP to opt out or HELP for assistance') !== false, 'Exact voice consent disclosure is missing.');
$expect(strpos($vendor_template, 'SMS_Compliance::DISCLOSURE') !== false && strpos($vendor_template, 'SMS_Compliance::CONFIRMATION') !== false, 'Provider voice-consent UI is missing.');
$expect(strpos($db, 'sms_consent_disclosure TEXT') !== false && strpos($db, 'sms_consent_revoked_at DATETIME') !== false, 'Immutable consent snapshot or revocation schema is missing.');
$expect(strpos($vendor_api, "'sms_consent_evidence'") !== false && strpos($invites, 'consent_evidence') !== false, 'Visible provider consent evidence is missing.');
$expect(strpos($invites, "'sms_consent_status'] = 'consumed'") !== false && strpos($invites, 'sms_consent_scope_exhausted') !== false, 'Single-invitation consent scope is not enforced.');
$expect(strpos($invites, "['id'=>\$invitation_id, 'sms_consent_status'=>'active', 'sms_sent_at'=>null]") !== false && strpos($invites, "'sms_consent_status'=>'sending'") !== false, 'SMS consent is not atomically reserved before delivery.');
$expect(strpos($invites, "'_koopo_verified_phone_e164'") !== false && strpos($invites, "A phone-only invitation is a high-entropy bearer link") === false, 'Phone-only claims do not require verified phone ownership.');
$expect(strpos($invites, "'meta_key'=>'billing_phone'") === false, 'Unverified billing phone metadata must not establish account ownership.');
$expect(strpos($invites, "is_user_logged_in()") !== false && strpos($invites, "START TRANSACTION") !== false, 'Authenticated atomic claim flow is missing.');
$expect(strpos($invites, 'identity_matches') !== false && strpos($invites, "'customer_id'=>\$user_id") !== false, 'Invitation claim does not bind the appointment to the account.');
$expect(strpos($invites, 'Checkout_Cart::prepare_order_for_booking') !== false, 'Claim flow does not continue to checkout.');
$expect(strpos($bookings, 'Customer-facing bookings always belong to the authenticated account.') !== false, 'Public account ownership guard is missing.');
$expect(strpos($public_js, 'booking_for_other') === false, 'Public JavaScript still offers booking for someone else.');
$expect(strpos($public_js, "$(document).on('click', '.koopo-appt__service-card', function(e){") !== false, 'The full customer service card is not clickable.');
$expect(strpos($public_js, 'class="koopo-appt__service-card-btn" aria-pressed="false"') !== false, 'Service selection does not expose its pressed state.');
$expect(substr_count($public_ui, 'readonly aria-readonly="true"') >= 2, 'Public account identity fields are editable.');
$expect(strpos($waitlist, 'koopo_appt_send_transactional_sms') === false, 'Registered waitlists can still send SMS.');
$expect(strpos($messaging, "messages_new_message") !== false && strpos($messaging, "'linked_entity'") !== false && strpos($messaging, "'_koopo_linked_entity'") === false, 'Koopo inbox appointment metadata does not match the mobile message contract.');
$expect(strpos($db, 'notification_deliveries_table') !== false && strpos($messaging, 'Notification_Delivery::claim') !== false, 'Appointment notification idempotency is missing.');
$sms = $source('includes/notifications/class-kgaw-transactional-sms.php');
$provider = $source('includes/notifications/class-kgaw-sms-provider.php');
$expect(strpos($sms, 'koopo_appt_send_transactional_sms_result') !== false && strpos($sms, 'sms_adapter_unverified') !== false, 'SMS delivery does not require a structured adapter result.');
$expect(strpos($provider, "'guest_appointment_invite' ? 160 : 1200") !== false, 'Invitation SMS lacks a provider-boundary 160-character guard.');

echo "booking invitation tests passed\n";
