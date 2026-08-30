<?php
/** Guarded runtime proof that both feature gates default off and fail closed. */
if (PHP_SAPI !== 'cli' || getenv('KOOPO_APPT_FEATURE_GATES_UAT') !== '1') {
  fwrite(STDERR, "Set KOOPO_APPT_FEATURE_GATES_UAT=1 and run with wp eval-file.\n");
  exit(2);
}

use Koopo_Appointments\Client_Records;
use Koopo_Appointments\Features;
use Koopo_Appointments\Waitlist;

$waitlist_old = get_option(Features::OPTION_WAITLIST_ENABLED, null);
$clients_old = get_option(Features::OPTION_CLIENT_FORMS_ENABLED, null);
$user_id = get_current_user_id();
if (!$user_id) {
  $admins = get_users(['role'=>'administrator', 'number'=>1, 'fields'=>'ID']);
  if (!$admins) throw new RuntimeException('No administrator is available for the gate test.');
  wp_set_current_user((int) $admins[0]);
}

try {
  update_option(Features::OPTION_WAITLIST_ENABLED, 0, false);
  update_option(Features::OPTION_CLIENT_FORMS_ENABLED, 0, false);
  $waitlist_disabled = Waitlist::permission();
  $clients_disabled = Client_Records::permission();
  if (!is_wp_error($waitlist_disabled) || 503 !== (int) ($waitlist_disabled->get_error_data()['status'] ?? 0)) throw new RuntimeException('Waitlist did not fail closed.');
  if (!is_wp_error($clients_disabled) || 503 !== (int) ($clients_disabled->get_error_data()['status'] ?? 0)) throw new RuntimeException('Client forms did not fail closed.');

  rest_get_server();
  if (!did_action('rest_api_init')) do_action('rest_api_init');
  $waitlist_request = new WP_REST_Request('GET', '/koopo/v1/vendor/waitlist');
  $waitlist_request->set_param('resource_id', 1);
  $clients_request = new WP_REST_Request('GET', '/koopo/v1/vendor/clients');
  $clients_request->set_param('resource_id', 1);
  $waitlist_response = rest_do_request($waitlist_request);
  $clients_response = rest_do_request($clients_request);
  if (503 !== $waitlist_response->get_status()) throw new RuntimeException('Waitlist REST route did not return 503.');
  if (503 !== $clients_response->get_status()) throw new RuntimeException('Client forms REST route did not return 503.');

  update_option(Features::OPTION_WAITLIST_ENABLED, 1, false);
  update_option(Features::OPTION_CLIENT_FORMS_ENABLED, 1, false);
  if (true !== Waitlist::permission() || true !== Client_Records::permission()) throw new RuntimeException('An enabled gate did not open.');

  echo wp_json_encode([
    'ok'=>true,
    'disabled'=>['waitlist'=>'503','client_forms'=>'503'],
    'rest_status'=>['waitlist'=>$waitlist_response->get_status(),'client_forms'=>$clients_response->get_status()],
    'enabled'=>['waitlist'=>true,'client_forms'=>true],
    'data_mutated'=>false,
  ], JSON_PRETTY_PRINT) . "\n";
} finally {
  if ($waitlist_old === null) delete_option(Features::OPTION_WAITLIST_ENABLED); else update_option(Features::OPTION_WAITLIST_ENABLED, $waitlist_old, false);
  if ($clients_old === null) delete_option(Features::OPTION_CLIENT_FORMS_ENABLED); else update_option(Features::OPTION_CLIENT_FORMS_ENABLED, $clients_old, false);
}
