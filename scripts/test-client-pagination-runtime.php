<?php
/**
 * Beta acceptance harness. Run with: wp eval-file scripts/test-client-pagination-runtime.php
 * Creates and removes only clearly marked Koopo UAT client rows.
 */

if (!defined('ABSPATH') || !class_exists(\Koopo_Appointments\Client_Records::class)) {
  fwrite(STDERR, "Load this file through WordPress WP-CLI.\n");
  exit(1);
}

global $wpdb;
wp_set_current_user(3);
$resource = $wpdb->get_row('SELECT * FROM ' . \Koopo_Appointments\DB::resources_table() . " WHERE status='active' ORDER BY id LIMIT 1");
if (!$resource) {
  fwrite(STDERR, "No active booking resource is available for the client pagination fixture.\n");
  exit(1);
}

$marker = 'koopo-uat-client-' . strtolower(wp_generate_password(8, false, false));
$table = \Koopo_Appointments\DB::clients_table();
$created = [];
try {
  foreach (['Alpha','Beta','Gamma'] as $name) {
    $wpdb->insert($table, [
      'resource_id'=>(int)$resource->id,
      'owner_user_id'=>(int)$resource->owner_user_id,
      'name'=>$marker . ' ' . $name,
      'email'=>$marker . '-' . strtolower($name) . '@example.invalid',
      'phone'=>'',
    ]);
    if (!$wpdb->insert_id) throw new RuntimeException('Unable to create a client pagination fixture.');
    $created[] = (int)$wpdb->insert_id;
  }

  $request = new WP_REST_Request('GET');
  $request->set_query_params(['resource_id'=>(int)$resource->id,'page'=>1,'per_page'=>2,'search'=>$marker]);
  $first = \Koopo_Appointments\Client_Records::list_clients($request);
  $first_headers = $first->get_headers();

  $request->set_query_params(['resource_id'=>(int)$resource->id,'page'=>2,'per_page'=>2,'search'=>$marker]);
  $second = \Koopo_Appointments\Client_Records::list_clients($request);

  $request->set_query_params(['resource_id'=>(int)$resource->id,'page'=>1,'per_page'=>25,'search'=>$marker . ' Gamma']);
  $searched = \Koopo_Appointments\Client_Records::list_clients($request);

  $result = [
    'resource_id'=>(int)$resource->id,
    'first_count'=>count((array)$first->get_data()),
    'second_count'=>count((array)$second->get_data()),
    'search_count'=>count((array)$searched->get_data()),
    'total'=>(int)($first_headers['X-WP-Total'] ?? -1),
    'total_pages'=>(int)($first_headers['X-WP-TotalPages'] ?? -1),
  ];
  if ($result['first_count']!==2 || $result['second_count']!==1 || $result['search_count']!==1 || $result['total']!==3 || $result['total_pages']!==2) {
    throw new RuntimeException('Client pagination acceptance failed: ' . wp_json_encode($result));
  }
  echo wp_json_encode($result) . PHP_EOL;
} finally {
  if ($created) {
    $placeholders = implode(',', array_fill(0, count($created), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $created));
  }
}
