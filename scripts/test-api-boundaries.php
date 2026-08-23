<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function boundary_source(string $relative): string {
  global $root;
  $contents = file_get_contents($root . '/' . $relative);
  if ($contents === false) throw new RuntimeException('Unable to read ' . $relative);
  return $contents;
}

function expect_boundary(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$calendar = boundary_source('includes/calendar/providers/class-kgaw-calendar-provider.php');
expect_boundary(strpos($calendar, "host !== 'graph.microsoft.com'") !== false, 'Microsoft continuation host allowlist is missing.');
expect_boundary(strpos($calendar, 'DEFAULT_MAX_PAGES') !== false, 'External calendar pagination cap is missing.');
expect_boundary(substr_count($calendar, 'microsoft_next_link($payload)') >= 2, 'Microsoft list and busy pagination do not share continuation validation.');

$index = boundary_source('includes/services/class-kgaw-bookable-listings-api.php');
expect_boundary(strpos($index, "const SYNC_SERVICE_HOOK = 'koopo_appt_sync_bookable_service';") !== false, 'Service index coalescing hook is missing.');
expect_boundary(strpos($index, 'as_has_scheduled_action') !== false, 'Index scheduling does not suppress duplicate actions.');
expect_boundary(strpos($index, "'maximum' => 24") !== false, 'Public directory page-size schema bound is missing.');
expect_boundary(strpos($index, "'validate_callback'=>'rest_validate_request_arg'") !== false, 'Public directory bounds are not enforced by WordPress REST validation.');

$reviews = boundary_source('includes/providers/class-kgaw-provider-reviews.php');
expect_boundary(strpos($reviews, 'AVG(CAST(cm.meta_value AS DECIMAL(10,2)))') !== false, 'Review aggregates still load every comment.');
expect_boundary(strpos($reviews, 'SELECT GET_LOCK') !== false && strpos($reviews, 'SELECT RELEASE_LOCK') !== false, 'Concurrent duplicate review guard is missing.');

$resources = boundary_source('includes/core/class-kgaw-resources.php');
expect_boundary(strpos($resources, "'posts_per_page' => \$batch_size") !== false, 'Resource discovery is not batched.');
expect_boundary(strpos($resources, "'no_found_rows' => true") !== false, 'Resource discovery performs unnecessary total counts.');

$availability = boundary_source('includes/core/class-kgaw-availability.php');
expect_boundary(strpos($availability, "'maximum'=>1440") !== false, 'Availability duration override is not bounded.');

$clients = boundary_source('includes/clients/class-kgaw-client-records.php');
expect_boundary(strpos($clients, "'X-WP-Total'") !== false && strpos($clients, "'X-WP-TotalPages'") !== false, 'Client pagination metadata is missing.');
expect_boundary(strpos($clients, "'per_page'=>['type'=>'integer','default'=>25") !== false, 'Client page-size schema is missing.');
expect_boundary(strpos($clients, '$wpdb->esc_like($search)') !== false, 'Client search is not safely escaped.');

$vendor_core = boundary_source('assets/vendor-core.js');
$vendor_clients = boundary_source('assets/vendor-clients.js');
expect_boundary(strpos($vendor_core, 'utils.apiWithMeta = apiWithMeta;') !== false, 'Vendor API pagination metadata adapter is missing.');
expect_boundary(strpos($vendor_clients, 'data-client-search-form') !== false && strpos($vendor_clients, 'data-client-page') !== false, 'Client pagination/search controls are missing.');

$services = boundary_source('includes/services/class-kgaw-services-api.php');
$waitlist = boundary_source('includes/waitlist/class-kgaw-waitlist.php');
expect_boundary(strpos($services, 'private static function service_args') !== false && strpos($services, "'maximum'=>1000000") !== false, 'Service mutation bounds are missing.');
expect_boundary(strpos($waitlist, 'private static function join_args') !== false && strpos($waitlist, "'maxItems'=>7") !== false, 'Waitlist request bounds are missing.');

$my_account = boundary_source('includes/customer/class-kgaw-myaccount.php');
expect_boundary(strpos($my_account, "wc_get_account_endpoint_url('appointments')") !== false, 'Customer action URLs must use the active WooCommerce appointments endpoint.');
expect_boundary(strpos($my_account, "wc_get_account_endpoint_url('koopo-appointments')") === false, 'Disabled legacy customer endpoint must not be used for action URLs.');

echo "api boundary tests passed\n";
