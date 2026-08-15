<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function source(string $relative): string {
  global $root;
  $contents = file_get_contents($root . '/' . $relative);
  if ($contents === false) throw new RuntimeException('Unable to read ' . $relative);
  return $contents;
}

function expect_source(string $relative, string $needle, string $message): void {
  if (strpos(source($relative), $needle) === false) throw new RuntimeException($message);
}

$db = source('includes/core/class-kgaw-db.php');
if (!preg_match('/CREATE TABLE \{\$table\}.*?listing_id BIGINT UNSIGNED NULL.*?provider_id BIGINT UNSIGNED NULL.*?resource_id BIGINT UNSIGNED NULL/s', $db)) {
  throw new RuntimeException('Booking schema is not provider/resource compatible.');
}
if (substr_count($db, 'UNIQUE KEY provider_listing (provider_id, listing_id)') !== 1) {
  throw new RuntimeException('Affiliation uniqueness contract is missing or duplicated.');
}

expect_source('includes/core/class-kgaw-bookings.php', 'WHERE resource_id = %d', 'Booking conflicts are not resource scoped.');
expect_source('includes/core/class-kgaw-bookings.php', "'payee_user_id'      =>", 'Booking payee is not persisted.');
expect_source('includes/services/class-kgaw-services-list.php', "'/services/by-listing/(?P<id>\\d+)'", 'Legacy place service route was removed.');
expect_source('includes/services/class-kgaw-services-list.php', "'/services/by-provider/(?P<id>\\d+)'", 'Professional service route is missing.');
expect_source('includes/settings/class-kgaw-settings-api.php', "'/appointments/settings/(?P<listing_id>\\d+)'", 'Legacy place settings route was removed.');
expect_source('includes/settings/class-kgaw-settings-api.php', "'/resources/(?P<resource_id>\\d+)/settings'", 'Resource settings route is missing.');
expect_source('includes/providers/class-kgaw-provider-profiles.php', "const POST_TYPE = 'koopo_provider';", 'Professional post type is missing.');
expect_source('includes/providers/class-kgaw-provider-profiles.php', "const META_GALLERY_IDS = '_koopo_provider_gallery_ids';", 'Ordered service-profile gallery contract is missing.');
expect_source('includes/providers/class-kgaw-provider-profiles.php', "'category_id'", 'Profile-level category persistence is missing.');
expect_source('includes/services/class-kgaw-service-categories.php', "[Provider_Profiles::POST_TYPE, Services_CPT::POST_TYPE]", 'Service category taxonomy is not registered on profiles.');
expect_source('includes/services/class-kgaw-service-categories.php', 'migrate_to_profiles', 'Legacy service-category migration is missing.');
expect_source('includes/providers/class-kgaw-provider-reviews.php', "const COMMENT_TYPE = 'koopo_provider_rev';", 'Service-profile review comment type is missing.');
expect_source('includes/media/class-kgaw-service-profile-media-adapter.php', "const GALLERY_ROLE = 'service_profile_gallery';", 'Direct-offload service-profile gallery role is missing.');
expect_source('includes/providers/class-kgaw-provider-affiliations.php', "'approved'", 'Professional/place affiliation approval is missing.');
expect_source('includes/providers/class-kgaw-service-areas.php', 'check_destination', 'Mobile service-area coverage validation is missing.');
expect_source('includes/providers/class-kgaw-service-areas.php', 'approximate_center', 'Public service areas do not protect the exact private origin.');
expect_source('includes/providers/class-kgaw-service-areas.php', 'delete_with_provider', 'Service-area rows are not cleaned up with deleted profiles.');
expect_source('includes/core/class-kgaw-bookings.php', "'fulfillment_mode'", 'Booking fulfillment mode is not persisted.');
expect_source('includes/waitlist/class-kgaw-waitlist.php', "'service_address'=>", 'Waitlist offers do not preserve mobile fulfillment details.');
expect_source('includes/services/class-kgaw-bookable-listings-api.php', 'archive_places', 'The Professionals archive does not include bookable places.');
expect_source('includes/core/class-kgaw-date-formatter.php', "'virtual_join_url'", 'Virtual meeting access is missing from customer calendar data.');
expect_source('includes/integrations/class-kgaw-buddyboss-appointments.php', "'services'", 'BuddyBoss services tab is missing.');
expect_source('includes/ui/class-kgaw-ui.php', "'provider_id'", 'Public professional booking UI is missing.');
expect_source('includes/calendar/class-kgaw-calendar-busy.php', "const REFRESH_HOOK = 'koopo_appt_calendar_refresh_busy';", 'Inbound availability refresh is missing.');
expect_source('includes/calendar/providers/class-kgaw-calendar-provider.php', 'list_busy_events', 'External calendar busy ingestion is missing.');
$calendar_provider = source('includes/calendar/providers/class-kgaw-calendar-provider.php');
if (!preg_match('/public function list_busy_events\(.*?\n  }\n\n  private function normalize_google_busy_event/s', $calendar_provider, $busy_method)) throw new RuntimeException('External busy ingestion method could not be inspected.');
if (preg_match('/summary|subject|attendees|description/i', $busy_method[0])) throw new RuntimeException('External busy ingestion requests private event content.');
expect_source('includes/core/class-kgaw-bookings.php', 'Calendar_Busy::has_conflict', 'Locked booking conflict checks do not include external busy periods.');
expect_source('includes/waitlist/class-kgaw-waitlist.php', "'first_to_confirm'", 'Waitlist first-to-confirm mode is missing.');
$waitlist = source('includes/waitlist/class-kgaw-waitlist.php');
if (strpos($waitlist, 'koopo_appt_send_transactional_sms') !== false || strpos($waitlist, "['email','push','sms']") !== false) {
  throw new RuntimeException('Registered-customer waitlists must not send SMS.');
}
expect_source('includes/clients/class-kgaw-client-records.php', 'requires_signature', 'Client consent signature contract is missing.');
expect_source('includes/clients/class-kgaw-client-records.php', 'send_hours_before', 'Automated form request scheduling is missing.');
expect_source('includes/media/class-kgaw-service-profile-media-adapter.php', "const CLIENT_FILE_ROLE = 'appointment_client_file';", 'Direct-offload private client file role is missing.');
expect_source('includes/media/class-kgaw-service-profile-media-adapter.php', 'add_reference', 'Private client files are not reference tracked.');

echo "provider architecture tests passed\n";
