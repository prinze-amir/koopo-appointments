<?php

use Koopo_Appointments\Bookings;
use Koopo_Appointments\Checkout_Cart;
use Koopo_Appointments\DB;
use Koopo_Appointments\Refund_Processor;

if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_FINANCIAL_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_FINANCIAL_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

$mode = sanitize_key((string) ($args[0] ?? ''));
$json = static function(array $payload): void {
  echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
};

if ($mode === 'setup') {
  $admins = get_users(['role'=>'administrator','number'=>1,'fields'=>'ids']);
  $admin_id = (int) ($admins[0] ?? 0);
  if (!$admin_id) throw new RuntimeException('No administrator is available for the UAT fixture.');
  wp_set_current_user($admin_id);

  $suffix = strtolower(wp_generate_password(8, false, false));
  $login = 'koopo_fin_uat_' . $suffix;
  $user_id = wp_insert_user([
    'user_login'=>$login,
    'user_pass'=>wp_generate_password(32, true, true),
    'user_email'=>$login . '@example.invalid',
    'display_name'=>'Koopo Financial UAT',
    'role'=>'customer',
  ]);
  if (is_wp_error($user_id)) throw new RuntimeException($user_id->get_error_message());

  $service_id = wp_insert_post([
    'post_type'=>'koopo_service',
    'post_status'=>'publish',
    'post_title'=>'Koopo Financial UAT ' . $suffix,
    'post_author'=>$admin_id,
  ], true);
  if (is_wp_error($service_id)) throw new RuntimeException($service_id->get_error_message());

  $product = new WC_Product_Simple();
  $product->set_name('Koopo Financial UAT ' . $suffix);
  $product->set_status('publish');
  $product->set_catalog_visibility('hidden');
  $product->set_virtual(true);
  $product->set_regular_price('12.34');
  $product->set_price('12.34');
  $product_id = (int) $product->save();
  if (!$product_id) throw new RuntimeException('Unable to create the UAT WooCommerce product.');
  wp_update_post(['ID'=>$product_id,'post_author'=>$admin_id]);
  update_post_meta((int) $service_id, '_koopo_wc_product_id', $product_id);

  global $wpdb;
  $now = time();
  $inserted = $wpdb->insert(DB::table(), [
    'listing_id'=>null,
    'listing_author_id'=>$admin_id,
    'provider_id'=>null,
    'resource_id'=>null,
    'payee_user_id'=>$admin_id,
    'customer_id'=>(int) $user_id,
    'customer_name'=>'Koopo Financial UAT',
    'customer_email'=>$login . '@example.invalid',
    'service_id'=>(string) $service_id,
    'start_datetime'=>gmdate('Y-m-d H:i:s', $now + DAY_IN_SECONDS),
    'end_datetime'=>gmdate('Y-m-d H:i:s', $now + DAY_IN_SECONDS + HOUR_IN_SECONDS),
    'timezone'=>'UTC',
    'price'=>12.34,
    'currency'=>'USD',
    'status'=>'pending_payment',
    'hold_expires_at'=>gmdate('Y-m-d H:i:s', $now + HOUR_IN_SECONDS),
    'retention_class'=>'business_record',
    'created_at'=>current_time('mysql', true),
    'updated_at'=>current_time('mysql', true),
  ]);
  if (!$inserted) throw new RuntimeException('Unable to create the UAT booking: ' . $wpdb->last_error);
  $booking_id = (int) $wpdb->insert_id;
  $json(compact('admin_id','user_id','service_id','product_id','booking_id'));
  return;
}

if ($mode === 'checkout') {
  $booking_id = absint($args[1] ?? 0);
  $user_id = absint($args[2] ?? 0);
  $slow = !empty($args[3]);
  wp_set_current_user($user_id);
  if ($slow) add_action('koopo_appt_checkout_lock_acquired', static function(int $locked_booking_id) use ($booking_id): void {
    if ($locked_booking_id === $booking_id) usleep(1500000);
  });
  $started = microtime(true);
  $result = Checkout_Cart::prepare_order_for_booking($booking_id);
  $elapsed = round(microtime(true) - $started, 3);
  if (is_wp_error($result)) {
    $json(['ok'=>false,'code'=>$result->get_error_code(),'message'=>$result->get_error_message(),'elapsed'=>$elapsed]);
    return;
  }
  $json(['ok'=>true,'order_id'=>(int) ($result['order_id'] ?? 0),'reused'=>(bool) ($result['resume_order'] ?? false),'elapsed'=>$elapsed]);
  return;
}

if ($mode === 'refund') {
  $order_id = absint($args[1] ?? 0);
  $booking_id = absint($args[2] ?? 0);
  $slow = !empty($args[3]);
  if ($slow) add_action('koopo_appt_refund_lock_acquired', static function(int $locked_order_id) use ($order_id): void {
    if ($locked_order_id === $order_id) usleep(1500000);
  });
  $started = microtime(true);
  $result = Refund_Processor::process_refund($order_id, 12.34, 'Koopo financial UAT manual refund', $booking_id);
  $result['elapsed'] = round(microtime(true) - $started, 3);
  $json($result);
  return;
}

if ($mode === 'inspect') {
  $booking_id = absint($args[1] ?? 0);
  $booking = Bookings::get_booking($booking_id);
  if (!$booking) throw new RuntimeException('UAT booking not found.');
  $order_id = (int) ($booking->wc_order_id ?? 0);
  $order = $order_id ? wc_get_order($order_id) : null;
  global $wpdb;
  $order_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id=oi.order_item_id
     WHERE oim.meta_key='_koopo_booking_id' AND oim.meta_value=%s",
    (string) $booking_id
  ));
  $operations = $wpdb->get_results($wpdb->prepare('SELECT id,status,refund_id,attempt_count FROM '.DB::refund_operations_table().' WHERE booking_id=%d', $booking_id), ARRAY_A) ?: [];
  $json([
    'booking_order_id'=>$order_id,
    'order_ids'=>array_values(array_map('intval',$order_ids)),
    'refund_ids'=>$order ? array_values(array_map(static fn($refund)=>(int)$refund->get_id(),$order->get_refunds())) : [],
    'operations'=>$operations,
  ]);
  return;
}

if ($mode === 'retention') {
  $booking_id = absint($args[1] ?? 0);
  global $wpdb;
  $wpdb->update(DB::table(), [
    'status'=>'cancelled',
    'end_datetime'=>gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
    'archived_at'=>null,
    'retention_class'=>'business_record',
  ], ['id'=>$booking_id], ['%s','%s','%s','%s'], ['%d']);
  $cleanup = new ReflectionMethod(Bookings::class, 'cleanup_cancelled_past');
  $cleanup->invoke(null);
  $booking = Bookings::get_booking($booking_id);
  $json(['exists'=>(bool)$booking,'status'=>(string)($booking->status??''),'archived_at'=>(string)($booking->archived_at??''),'retention_class'=>(string)($booking->retention_class??'')]);
  return;
}

if ($mode === 'cleanup') {
  [$booking_id,$service_id,$product_id,$user_id] = array_map('absint', array_slice(array_pad($args, 5, 0), 1, 4));
  $service = get_post($service_id);
  $user = get_userdata($user_id);
  if (!$service || strpos((string)$service->post_title, 'Koopo Financial UAT ') !== 0 || !$user || strpos((string)$user->user_login, 'koopo_fin_uat_') !== 0) {
    throw new RuntimeException('Refusing to clean up an unverified fixture.');
  }
  $booking = Bookings::get_booking($booking_id);
  $order_id = (int)($booking->wc_order_id??0);
  if ($order_id) { $order=wc_get_order($order_id); if ($order) $order->delete(true); }
  global $wpdb;
  $wpdb->delete(DB::refund_operations_table(), ['booking_id'=>$booking_id], ['%d']);
  Bookings::delete_booking_data_by_id($booking_id);
  wp_delete_post($service_id, true);
  wp_delete_post($product_id, true);
  require_once ABSPATH . 'wp-admin/includes/user.php';
  wp_delete_user($user_id);
  $json(['cleaned'=>true,'booking_id'=>$booking_id,'order_id'=>$order_id]);
  return;
}

throw new RuntimeException('Unknown UAT mode.');
