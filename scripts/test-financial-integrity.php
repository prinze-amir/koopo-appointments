<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function koopo_integrity_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function koopo_integrity_source(string $root, string $path): string {
  $source = file_get_contents($root . '/' . $path);
  if (!is_string($source)) throw new RuntimeException('Unable to read ' . $path);
  return $source;
}

$checkout = koopo_integrity_source($root, 'includes/woocommerce/class-kgaw-checkout-cart.php');
$bookings = koopo_integrity_source($root, 'includes/core/class-kgaw-bookings.php');
$refunds = koopo_integrity_source($root, 'includes/refunds/class-kgaw-refund-processor.php');
$db = koopo_integrity_source($root, 'includes/core/class-kgaw-db.php');

koopo_integrity_expect(strpos($checkout, 'acquire_checkout_lock($booking_id, 5)') !== false, 'Checkout creation is not serialized.');
koopo_integrity_expect(strpos($checkout, 'finally {') !== false && strpos($checkout, 'release_checkout_lock($booking_id)') !== false, 'Checkout lock is not released with finally.');
koopo_integrity_expect(strpos($checkout, 'Bookings::assign_order_id_if_empty') !== false, 'Checkout does not use atomic order assignment.');
koopo_integrity_expect(strpos($bookings, 'AND (wc_order_id IS NULL OR wc_order_id = 0)') !== false, 'Order assignment can overwrite an existing order.');

koopo_integrity_expect(strpos($refunds, 'acquire_order_lock($order_id, 5)') !== false, 'Refund creation is not serialized by order.');
koopo_integrity_expect(strpos($refunds, 'existing_woocommerce_refund') !== false, 'Refund retry reconciliation is missing.');
koopo_integrity_expect(strpos($refunds, "'_koopo_refund_operation_key'") !== false, 'WooCommerce refund identity metadata is missing.');
koopo_integrity_expect(strpos($db, 'koopo_appt_refund_operations') !== false, 'Durable refund operation storage is missing.');
koopo_integrity_expect(strpos($db, 'UNIQUE KEY idempotency_key') !== false, 'Refund operation idempotency is not enforced by the database.');
koopo_integrity_expect(strpos($db, "'koopo_appt_db_upgrade'") !== false && strpos($db, 'SELECT GET_LOCK(%s, %d)') !== false, 'Schema upgrades are not serialized.');
koopo_integrity_expect(strpos($db, 'SELECT option_value FROM {$wpdb->options}') !== false && strpos($db, "wp_cache_delete('alloptions', 'options')") !== false && strpos($db, 'reconcile_version_cache') !== false, 'Schema version checks are not persistent-cache safe.');

koopo_integrity_expect(strpos($db, 'archived_at DATETIME NULL') !== false, 'Booking archive timestamp is missing.');
koopo_integrity_expect(strpos($bookings, "retention_class = 'business_record'") !== false, 'Cancelled bookings are not retained as business records.');
koopo_integrity_expect(strpos($bookings, "apply_filters('koopo_appt_delete_expired_booking', false") !== false, 'Abandoned holds still hard-delete by default.');
$cancel_cleanup_start = strpos($bookings, 'private static function cleanup_cancelled_past');
$cancel_cleanup_end = strpos($bookings, 'private static function record_cancelled_archive', $cancel_cleanup_start ?: 0);
koopo_integrity_expect($cancel_cleanup_start !== false && $cancel_cleanup_end !== false, 'Cancelled cleanup method could not be inspected.');
$cancel_cleanup = substr($bookings, $cancel_cleanup_start, $cancel_cleanup_end - $cancel_cleanup_start);
koopo_integrity_expect(strpos($cancel_cleanup, 'delete_booking_data') === false, 'Cancelled booking cleanup still destroys appointment records.');

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!function_exists('wc_get_price_decimals')) { function wc_get_price_decimals(): int { return 2; } }
if (!function_exists('wc_format_decimal')) { function wc_format_decimal($amount, $decimals = 2): string { return number_format((float) $amount, (int) $decimals, '.', ''); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($value): string { return strip_tags((string) $value); } }
require_once $root . '/includes/refunds/class-kgaw-refund-processor.php';

$key_method = new ReflectionMethod(\Koopo_Appointments\Refund_Processor::class, 'idempotency_key');
$booking_key_a = $key_method->invoke(null, 501, 25.00, 'Customer cancellation', 77);
$booking_key_b = $key_method->invoke(null, 501, 50.00, 'Vendor cancellation', 77);
$other_booking_key = $key_method->invoke(null, 501, 25.00, 'Customer cancellation', 78);
koopo_integrity_expect(hash_equals($booking_key_a, $booking_key_b), 'A booking retry does not keep one refund identity.');
koopo_integrity_expect(!hash_equals($booking_key_a, $other_booking_key), 'Different bookings share a refund identity.');

$directory = koopo_integrity_source($root, 'includes/services/class-kgaw-bookable-listings-api.php');
koopo_integrity_expect(strpos($directory, "function_exists('geodir_get_post_rating')") !== false, 'GeoDirectory rating integration does not use its public API.');
koopo_integrity_expect(strpos($directory, "function_exists('geodir_get_review_count_total')") !== false, 'GeoDirectory review-count integration does not use its public API.');

echo "financial integrity tests passed\n";
