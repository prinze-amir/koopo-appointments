<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Configured Brevo/Twilio transport for consented guest-invitation SMS. */
final class SMS_Provider {
  const OPTION_ENABLED = 'koopo_appt_sms_enabled';
  const OPTION_PROVIDER = 'koopo_appt_sms_provider';
  const OPTION_BREVO_API_KEY = 'koopo_appt_brevo_sms_api_key';
  const OPTION_BREVO_SENDER = 'koopo_appt_brevo_sms_sender';
  const OPTION_TWILIO_ACCOUNT_SID = 'koopo_appt_twilio_account_sid';
  const OPTION_TWILIO_API_KEY_SID = 'koopo_appt_twilio_api_key_sid';
  const OPTION_TWILIO_API_KEY_SECRET = 'koopo_appt_twilio_api_key_secret';
  const OPTION_TWILIO_MESSAGING_SERVICE_SID = 'koopo_appt_twilio_messaging_service_sid';
  const OPTION_TWILIO_FROM_NUMBER = 'koopo_appt_twilio_from_number';

  public static function init(): void {
    add_filter('koopo_appt_send_transactional_sms_result', [__CLASS__, 'filter_send'], 10, 4);
    add_action('admin_post_koopo_appt_sms_test', [__CLASS__, 'test_send']);
  }

  public static function filter_send($result, string $phone, string $message, array $context): array {
    if (is_array($result)) return $result;
    return self::send($phone, $message, $context);
  }

  public static function send(string $phone, string $message, array $context = []): array {
    if (!self::enabled()) return self::failure('sms_disabled', false);
    $phone = Booking_Invitations::normalize_phone($phone);
    $message = trim(wp_strip_all_tags($message));
    if ($phone === '' || $message === '') return self::failure('invalid_sms_request', false);
    $max_length = sanitize_key((string) ($context['type'] ?? '')) === 'guest_appointment_invite' ? 160 : 1200;
    if (strlen($message) > $max_length) return self::failure('sms_message_too_long', false);

    $reservation = SMS_Usage::reserve();
    if (is_wp_error($reservation)) return self::failure($reservation->get_error_code(), false);
    $provider = self::provider();
    if ($provider === 'brevo') $result = self::send_brevo($phone, $message, $context);
    elseif ($provider === 'twilio') $result = self::send_twilio($phone, $message, $context);
    else $result = self::failure('sms_provider_not_configured', false);
    SMS_Usage::finish($reservation, !empty($result['accepted']), !empty($result['uncertain']));
    if (!empty($result['accepted']) && !empty($result['provider_message_id'])) SMS_Delivery_Receipts::accepted($provider, (string) $result['provider_message_id'], $context);
    unset($result['uncertain']);
    return $result;
  }

  public static function enabled(): bool {
    return '1' === (string) get_option(self::OPTION_ENABLED, '0');
  }

  public static function provider(): string {
    $provider = sanitize_key((string) get_option(self::OPTION_PROVIDER, 'brevo'));
    return in_array($provider, ['brevo', 'twilio'], true) ? $provider : 'brevo';
  }

  public static function status(): array {
    $provider = self::provider();
    $configured = $provider === 'brevo'
      ? self::secret(self::OPTION_BREVO_API_KEY) !== '' && self::brevo_sender() !== ''
      : self::twilio_account_sid() !== '' && self::twilio_api_key_sid() !== '' && self::secret(self::OPTION_TWILIO_API_KEY_SECRET) !== '' && (self::twilio_messaging_service_sid() !== '' || self::twilio_from_number() !== '');
    $enabled = self::enabled();
    $paused = SMS_Usage::paused();
    $limit_error = '';
    foreach (SMS_Usage::usage() as $type=>$meter) {
      if ($meter['enabled'] && $meter['used'] >= $meter['limit']) { $limit_error = 'sms_' . $type . '_limit_reached'; break; }
    }
    $ready = $enabled && $configured && !$paused && $limit_error === '';
    $reason = !$configured ? 'not_configured' : (!$enabled ? 'disabled' : ($paused ? 'paused' : ($limit_error ?: '')));
    return ['enabled'=>$enabled, 'provider'=>$provider, 'configured'=>$configured, 'ready'=>$ready, 'paused'=>$paused, 'limit_error'=>$limit_error, 'unavailable_reason'=>$ready?'':$reason];
  }

  /** Brevo-reported SMS credits from GET /v3/account, cached for five minutes. */
  public static function brevo_credits(bool $force = false): array {
    $api_key = self::secret(self::OPTION_BREVO_API_KEY);
    if ($api_key === '') return ['status'=>'unavailable', 'credits'=>null, 'fetched_at'=>null, 'error_code'=>'brevo_not_configured'];
    $cache_key = 'koopo_appt_brevo_sms_credits_' . substr(hash('sha256', $api_key), 0, 16);
    if (!$force) {
      $cached = get_transient($cache_key);
      if (is_array($cached)) return $cached;
    }
    $response = wp_remote_get('https://api.brevo.com/v3/account', [
      'timeout'=>10,
      'headers'=>['accept'=>'application/json', 'api-key'=>$api_key],
    ]);
    if (is_wp_error($response)) $result = ['status'=>'error', 'credits'=>null, 'fetched_at'=>time(), 'error_code'=>'brevo_account_network_error'];
    else {
      $status = (int) wp_remote_retrieve_response_code($response);
      $payload = json_decode((string) wp_remote_retrieve_body($response), true);
      $credits = 0;
      $found = false;
      foreach ((array) ($payload['plan'] ?? []) as $plan) {
        if (is_array($plan) && sanitize_key((string) ($plan['type'] ?? '')) === 'sms' && is_numeric($plan['credits'] ?? null)) {
          $credits += max(0, (int) $plan['credits']);
          $found = true;
        }
      }
      $result = $status === 200 && $found
        ? ['status'=>'available', 'credits'=>$credits, 'fetched_at'=>time(), 'error_code'=>'']
        : ['status'=>'error', 'credits'=>null, 'fetched_at'=>time(), 'error_code'=>'brevo_account_http_' . ($status ?: 0)];
    }
    set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
    return $result;
  }

  public static function sanitize_secret($value, string $option): string {
    if (!empty($_POST[$option . '_clear'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php validates the settings nonce.
      return '';
    }
    $value = trim((string) wp_unslash($value));
    if ($value === '') return (string) get_option($option, '');
    try {
      return Calendar_Crypto::encrypt(['secret'=>$value]);
    } catch (\Throwable $error) {
      add_settings_error($option, 'koopo_appt_sms_secret_error', __('The SMS credential could not be encrypted. The previous value was preserved.', 'koopo-appointments'));
      return (string) get_option($option, '');
    }
  }

  public static function secret(string $option): string {
    $stored = (string) get_option($option, '');
    if ($stored === '') return '';
    try {
      $decoded = Calendar_Crypto::decrypt($stored);
      return !empty($decoded['secret']) && is_string($decoded['secret']) ? $decoded['secret'] : '';
    } catch (\Throwable $error) {
      return '';
    }
  }

  public static function sanitize_brevo_sender($value): string {
    $value = preg_replace('/[^A-Za-z0-9]/', '', (string) $value);
    return substr($value, 0, preg_match('/[A-Za-z]/', $value) ? 11 : 15);
  }

  public static function sanitize_twilio_account_sid($value): string {
    $value = trim((string) $value);
    return preg_match('/^AC[0-9a-fA-F]{32}$/', $value) ? $value : '';
  }

  public static function sanitize_twilio_api_key_sid($value): string {
    $value = trim((string) $value);
    return preg_match('/^SK[0-9a-fA-F]{32}$/', $value) ? $value : '';
  }

  public static function sanitize_twilio_service_sid($value): string {
    $value = trim((string) $value);
    return preg_match('/^MG[0-9a-fA-F]{32}$/', $value) ? $value : '';
  }

  public static function sanitize_phone($value): string {
    return Booking_Invitations::normalize_phone((string) $value);
  }

  public static function brevo_sender(): string { return self::sanitize_brevo_sender(get_option(self::OPTION_BREVO_SENDER, 'Koopo')); }
  public static function twilio_account_sid(): string { return self::sanitize_twilio_account_sid(get_option(self::OPTION_TWILIO_ACCOUNT_SID, '')); }
  public static function twilio_api_key_sid(): string { return self::sanitize_twilio_api_key_sid(get_option(self::OPTION_TWILIO_API_KEY_SID, '')); }
  public static function twilio_messaging_service_sid(): string { return self::sanitize_twilio_service_sid(get_option(self::OPTION_TWILIO_MESSAGING_SERVICE_SID, '')); }
  public static function twilio_from_number(): string { return self::sanitize_phone(get_option(self::OPTION_TWILIO_FROM_NUMBER, '')); }

  public static function test_send(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You cannot test SMS delivery.', 'koopo-appointments'), '', ['response'=>403]);
    check_admin_referer('koopo_appt_sms_test');
    $phone = self::sanitize_phone($_POST['test_phone'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $result = $phone !== ''
      ? self::send($phone, __('Koopo SMS delivery test. No action is required.', 'koopo-appointments'), ['type'=>'admin_test'])
      : self::failure('invalid_test_phone', false);
    $status = !empty($result['accepted']) ? 'sent' : 'failed';
    $code = sanitize_key((string) ($result['error_code'] ?? ''));
    wp_safe_redirect(add_query_arg(['page'=>'koopo-appointments-settings','koopo_sms_test'=>$status,'koopo_sms_code'=>$code], admin_url('options-general.php')) . '#koopo-appt-sms-delivery');
    exit;
  }

  private static function send_brevo(string $phone, string $message, array $context): array {
    $api_key = self::secret(self::OPTION_BREVO_API_KEY);
    $sender = self::brevo_sender();
    if ($api_key === '' || $sender === '') return self::failure('brevo_not_configured', false);
    $body = ['sender'=>$sender, 'recipient'=>$phone, 'content'=>$message, 'type'=>'transactional', 'tag'=>'koopo_' . sanitize_key((string) ($context['type'] ?? 'appointment'))];
    $webhook_url = SMS_Delivery_Receipts::webhook_url();
    if ($webhook_url !== '') $body['webUrl'] = $webhook_url;
    $response = wp_remote_post('https://api.brevo.com/v3/transactionalSMS/send', [
      'timeout'=>15,
      'headers'=>['accept'=>'application/json', 'api-key'=>$api_key, 'content-type'=>'application/json'],
      'body'=>wp_json_encode($body),
      'data_format'=>'body',
    ]);
    return self::parse_response($response, 'brevo', 201, 'messageId');
  }

  private static function send_twilio(string $phone, string $message, array $context): array {
    unset($context);
    $account_sid = self::twilio_account_sid();
    $api_key_sid = self::twilio_api_key_sid();
    $api_key_secret = self::secret(self::OPTION_TWILIO_API_KEY_SECRET);
    $service_sid = self::twilio_messaging_service_sid();
    $from = self::twilio_from_number();
    if ($account_sid === '' || $api_key_sid === '' || $api_key_secret === '' || ($service_sid === '' && $from === '')) return self::failure('twilio_not_configured', false);
    $body = ['To'=>$phone, 'Body'=>$message];
    if ($service_sid !== '') $body['MessagingServiceSid'] = $service_sid;
    else $body['From'] = $from;
    $response = wp_remote_post('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($account_sid) . '/Messages.json', [
      'timeout'=>15,
      'headers'=>['Authorization'=>'Basic ' . base64_encode($api_key_sid . ':' . $api_key_secret), 'Accept'=>'application/json'],
      'body'=>$body,
    ]);
    return self::parse_response($response, 'twilio', 201, 'sid');
  }

  private static function parse_response($response, string $provider, int $success_code, string $id_key): array {
    if (is_wp_error($response)) return self::failure($provider . '_network_error', true, true);
    $status = (int) wp_remote_retrieve_response_code($response);
    $payload = json_decode((string) wp_remote_retrieve_body($response), true);
    $message_id = is_array($payload) ? sanitize_text_field((string) ($payload[$id_key] ?? '')) : '';
    if ($status === $success_code && $message_id !== '') return ['accepted'=>true, 'provider_message_id'=>$message_id, 'error_code'=>'', 'retryable'=>false];
    $retryable = $status === 408 || $status === 429 || $status >= 500;
    return self::failure($provider . '_http_' . ($status ?: 0), $retryable, $status === 408 || $status >= 500);
  }

  private static function failure(string $code, bool $retryable, bool $uncertain = false): array {
    $result = ['accepted'=>false, 'provider_message_id'=>'', 'error_code'=>sanitize_key($code), 'retryable'=>$retryable];
    if ($uncertain) $result['uncertain'] = true;
    return $result;
  }
}
