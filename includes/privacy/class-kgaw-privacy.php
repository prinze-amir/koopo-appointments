<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** WordPress privacy export/erasure integration for appointment customer data. */
final class Privacy {
  const PAGE_SIZE = 100;

  public static function init(): void {
    add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
    add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_eraser']);
    add_action('admin_init', [__CLASS__, 'privacy_policy_content']);
  }

  public static function register_exporter(array $exporters): array {
    $exporters['koopo-appointments'] = [
      'exporter_friendly_name' => __('Koopo Appointments', 'koopo-appointments'),
      'callback' => [__CLASS__, 'export'],
    ];
    return $exporters;
  }

  public static function register_eraser(array $erasers): array {
    $erasers['koopo-appointments'] = [
      'eraser_friendly_name' => __('Koopo Appointments', 'koopo-appointments'),
      'callback' => [__CLASS__, 'erase'],
    ];
    return $erasers;
  }

  public static function export(string $email, int $page = 1): array {
    global $wpdb;
    $email = sanitize_email($email);
    $user = $email ? get_user_by('email', $email) : false;
    $user_id = $user ? (int) $user->ID : 0;
    $offset = (max(1, $page) - 1) * self::PAGE_SIZE;
    $where = $user_id > 0 ? '(customer_id = %d OR customer_email = %s)' : 'customer_email = %s';
    $args = $user_id > 0 ? [$user_id, $email, self::PAGE_SIZE, $offset] : [$email, self::PAGE_SIZE, $offset];
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . DB::table() . " WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d",
      $args
    )) ?: [];
    $data = [];
    foreach ($rows as $booking) {
      $data[] = [
        'group_id' => 'koopo-appointments',
        'group_label' => __('Appointments', 'koopo-appointments'),
        'item_id' => 'booking-' . (int) $booking->id,
        'data' => self::booking_export_fields($booking),
      ];
    }
    if (1 === max(1, $page)) {
      $client_where = $user_id > 0 ? '(wp_user_id = %d OR email = %s)' : 'email = %s';
      $client_args = $user_id > 0 ? [$user_id, $email] : [$email];
      $clients = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::clients_table() . " WHERE {$client_where} ORDER BY id ASC LIMIT 100", $client_args)) ?: [];
      foreach ($clients as $client) {
        $submissions = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::form_submissions_table() . ' WHERE client_id = %d ORDER BY id ASC LIMIT 200', (int) $client->id)) ?: [];
        $files = $wpdb->get_results($wpdb->prepare('SELECT filename,mime_type,size_bytes,created_at FROM ' . DB::client_files_table() . ' WHERE client_id = %d ORDER BY id ASC LIMIT 200', (int) $client->id), ARRAY_A) ?: [];
        $client_fields = [
          __('Provider client record ID', 'koopo-appointments') => (int) $client->id,
          __('Name', 'koopo-appointments') => (string) $client->name,
          __('Email', 'koopo-appointments') => (string) $client->email,
          __('Phone', 'koopo-appointments') => (string) $client->phone,
          __('Birthday', 'koopo-appointments') => (string) $client->birthday,
          __('Preferences', 'koopo-appointments') => (string) $client->preferences,
          __('Formulas/specifications', 'koopo-appointments') => (string) $client->formulas,
          __('Private provider notes', 'koopo-appointments') => (string) $client->private_notes,
          __('Intake submissions', 'koopo-appointments') => wp_json_encode(array_map(static fn($submission): array => ['form_id'=>(int)$submission->form_id,'booking_id'=>(int)$submission->booking_id,'answers'=>(array)json_decode((string)$submission->answers_json,true),'signature_name'=>(string)$submission->signature_name,'signed_at'=>(string)$submission->signed_at,'completed_at'=>(string)$submission->completed_at], $submissions)),
          __('Private attachment metadata', 'koopo-appointments') => wp_json_encode($files),
        ];
        $data[] = [
          'group_id' => 'koopo-appointment-client-records',
          'group_label' => __('Provider client records', 'koopo-appointments'),
          'item_id' => 'client-' . (int) $client->id,
          'data' => array_map(static fn($name, $value): array => ['name'=>$name,'value'=>$value], array_keys($client_fields), array_values($client_fields)),
        ];
      }
    }
    return ['data' => $data, 'done' => count($rows) < self::PAGE_SIZE];
  }

  public static function erase(string $email, int $page = 1): array {
    global $wpdb;
    $email = sanitize_email($email);
    $user = $email ? get_user_by('email', $email) : false;
    $user_id = $user ? (int) $user->ID : 0;
    if (!$email) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];

    $where = $user_id > 0 ? '(customer_id = %d OR customer_email = %s)' : 'customer_email = %s';
    $args = $user_id > 0 ? [$user_id, $email, self::PAGE_SIZE] : [$email, self::PAGE_SIZE];
    $booking_ids = array_map('absint', $wpdb->get_col($wpdb->prepare(
      'SELECT id FROM ' . DB::table() . " WHERE {$where} ORDER BY id ASC LIMIT %d",
      $args
    )) ?: []);
    $removed = false;
    foreach ($booking_ids as $booking_id) {
      $wpdb->delete(DB::notification_deliveries_table(), ['booking_id' => $booking_id], ['%d']);
      $wpdb->delete(DB::booking_invites_table(), ['booking_id' => $booking_id], ['%d']);
      $wpdb->update(DB::table(), [
        'customer_id' => 0,
        'customer_name' => __('Anonymous customer', 'koopo-appointments'),
        'customer_email' => '',
        'customer_phone' => '',
        'customer_notes' => '',
        'service_address_1' => '',
        'service_address_2' => '',
        'service_city' => '',
        'service_region' => '',
        'service_postal_code' => '',
        'service_country' => '',
        'service_latitude' => null,
        'service_longitude' => null,
        'booking_for_other' => 0,
        'updated_at' => current_time('mysql'),
      ], ['id' => $booking_id]);
      $removed = true;
    }

    $client_where = $user_id > 0 ? '(wp_user_id = %d OR email = %s)' : 'email = %s';
    $client_args = $user_id > 0 ? [$user_id, $email] : [$email];
    $client_ids = array_map('absint', $wpdb->get_col($wpdb->prepare(
      'SELECT id FROM ' . DB::clients_table() . " WHERE {$client_where}",
      $client_args
    )) ?: []);
    $retained_files = 0;
    foreach ($client_ids as $client_id) {
      $wpdb->update(DB::form_submissions_table(), [
        'answers_json' => '{}',
        'consent_text' => '',
        'signature_name' => '',
        'signature_hash' => '',
        'signer_ip_hash' => '',
        'user_agent_hash' => '',
        'signed_at' => null,
      ], ['client_id' => $client_id]);
      $retained_files += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::client_files_table() . ' WHERE client_id = %d', $client_id));
      $wpdb->update(DB::clients_table(), [
        'wp_user_id' => null,
        'name' => __('Anonymous customer', 'koopo-appointments'),
        'email' => '',
        'phone' => '',
        'birthday' => null,
        'preferences' => '',
        'formulas' => '',
        'private_notes' => '',
      ], ['id' => $client_id]);
      $removed = true;
    }

    $waitlist_where = $user_id > 0 ? '(customer_id = %d OR customer_email = %s)' : 'customer_email = %s';
    $waitlist_args = $user_id > 0 ? [$user_id, $email] : [$email];
    $waitlist_ids = array_map('absint', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . DB::waitlist_table() . " WHERE {$waitlist_where}", $waitlist_args)) ?: []);
    if ($waitlist_ids) {
      $placeholders = implode(',', array_fill(0, count($waitlist_ids), '%d'));
      $wpdb->query($wpdb->prepare('DELETE FROM ' . DB::waitlist_offers_table() . " WHERE waitlist_id IN ({$placeholders})", $waitlist_ids));
      $wpdb->query($wpdb->prepare('DELETE FROM ' . DB::waitlist_table() . " WHERE id IN ({$placeholders})", $waitlist_ids));
      $removed = true;
    }

    $messages = [];
    if ($retained_files > 0) {
      $messages[] = __('Private provider-held attachments were retained. They require removal through the provider client-file workflow so the remote Media Gateway reference can also be released.', 'koopo-appointments');
    }
    return [
      'items_removed' => $removed,
      'items_retained' => $retained_files > 0,
      'messages' => $messages,
      'done' => count($booking_ids) < self::PAGE_SIZE,
    ];
  }

  public static function privacy_policy_content(): void {
    if (!function_exists('wp_add_privacy_policy_content')) return;
    wp_add_privacy_policy_content('Koopo Appointments', wp_kses_post(
      '<p>' . __('Koopo Appointments stores appointment contact details, delivery addresses for mobile services, service history, provider-private notes, intake answers, signatures, calendar identifiers, and references to remotely stored client files. Configure a documented retention period and Media Gateway erasure procedure before accepting production appointments.', 'koopo-appointments') . '</p>'
    ));
  }

  private static function booking_export_fields(object $booking): array {
    $fields = [
      __('Appointment ID', 'koopo-appointments') => (int) $booking->id,
      __('Service', 'koopo-appointments') => get_the_title((int) $booking->service_id),
      __('Starts', 'koopo-appointments') => (string) $booking->start_datetime,
      __('Ends', 'koopo-appointments') => (string) $booking->end_datetime,
      __('Timezone', 'koopo-appointments') => (string) $booking->timezone,
      __('Status', 'koopo-appointments') => (string) $booking->status,
      __('Customer name', 'koopo-appointments') => (string) $booking->customer_name,
      __('Customer email', 'koopo-appointments') => (string) $booking->customer_email,
      __('Customer phone', 'koopo-appointments') => (string) $booking->customer_phone,
      __('Customer notes', 'koopo-appointments') => (string) $booking->customer_notes,
      __('Fulfillment mode', 'koopo-appointments') => (string) $booking->fulfillment_mode,
      __('Mobile service address', 'koopo-appointments') => implode(', ', array_filter([(string) $booking->service_address_1, (string) $booking->service_address_2, (string) $booking->service_city, (string) $booking->service_region, (string) $booking->service_postal_code, (string) $booking->service_country])),
    ];
    return array_map(static fn($name, $value): array => ['name' => $name, 'value' => $value], array_keys($fields), array_values($fields));
  }
}
