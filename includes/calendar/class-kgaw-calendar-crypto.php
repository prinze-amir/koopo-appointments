<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Calendar_Crypto {
  private static function key(): string {
    $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
    $material .= defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '';
    if ($material === '') {
      throw new \RuntimeException('WordPress authentication keys are required for calendar token encryption.');
    }
    return hash('sha256', $material . '|koopo-appointments-calendar', true);
  }

  public static function encrypt(array $value): string {
    if (!function_exists('openssl_encrypt')) {
      throw new \RuntimeException('OpenSSL is required for calendar token encryption.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
      wp_json_encode($value),
      'aes-256-gcm',
      self::key(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      'koopo-calendar-v1'
    );
    if ($ciphertext === false) {
      throw new \RuntimeException('Calendar token encryption failed.');
    }
    return base64_encode($iv . $tag . $ciphertext);
  }

  public static function decrypt(string $value): array {
    if ($value === '' || !function_exists('openssl_decrypt')) return [];
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 29) return [];
    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $ciphertext = substr($decoded, 28);
    $plain = openssl_decrypt(
      $ciphertext,
      'aes-256-gcm',
      self::key(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      'koopo-calendar-v1'
    );
    if (!is_string($plain)) return [];
    $decoded_value = json_decode($plain, true);
    return is_array($decoded_value) ? $decoded_value : [];
  }
}
