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
    if (strlen($message) > 1200) return self::failure('sms_message_too_long', false);

    $provider = self::provider();
    if ($provider === 'brevo') return self::send_brevo($phone, $message, $context);
    if ($provider === 'twilio') return self::send_twilio($phone, $message, $context);
    return self::failure('sms_provider_not_configured', false);
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
    return ['enabled'=>self::enabled(), 'provider'=>$provider, 'configured'=>$configured, 'ready'=>self::enabled() && $configured];
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
    $response = wp_remote_post('https://api.brevo.com/v3/transactionalSMS/send', [
      'timeout'=>15,
      'headers'=>['accept'=>'application/json', 'api-key'=>$api_key, 'content-type'=>'application/json'],
      'body'=>wp_json_encode(['sender'=>$sender, 'recipient'=>$phone, 'content'=>$message, 'type'=>'transactional', 'tag'=>'koopo_' . sanitize_key((string) ($context['type'] ?? 'appointment'))]),
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
    if (is_wp_error($response)) return self::failure($provider . '_network_error', true);
    $status = (int) wp_remote_retrieve_response_code($response);
    $payload = json_decode((string) wp_remote_retrieve_body($response), true);
    $message_id = is_array($payload) ? sanitize_text_field((string) ($payload[$id_key] ?? '')) : '';
    if ($status === $success_code && $message_id !== '') return ['accepted'=>true, 'provider_message_id'=>$message_id, 'error_code'=>'', 'retryable'=>false];
    $retryable = $status === 408 || $status === 429 || $status >= 500;
    return self::failure($provider . '_http_' . ($status ?: 0), $retryable);
  }

  private static function failure(string $code, bool $retryable): array {
    return ['accepted'=>false, 'provider_message_id'=>'', 'error_code'=>sanitize_key($code), 'retryable'=>$retryable];
  }
}
