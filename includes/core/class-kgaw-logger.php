<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Small, privacy-safe logging boundary for operational diagnostics.
 */
final class Logger {
  private const PREFIX = '[koopo-appointments] ';
  private const REDACTED = '[redacted]';
  private const MAX_DEPTH = 3;
  private const MAX_ITEMS = 30;
  private const MAX_STRING_LENGTH = 500;

  private const LEVELS = [
    'debug' => 100,
    'info' => 200,
    'warning' => 300,
    'error' => 400,
  ];

  public static function debug(string $event, array $context = []): void {
    self::log('debug', $event, $context);
  }

  public static function info(string $event, array $context = []): void {
    self::log('info', $event, $context);
  }

  public static function warning(string $event, array $context = []): void {
    self::log('warning', $event, $context);
  }

  public static function error(string $event, array $context = []): void {
    self::log('error', $event, $context);
  }

  public static function log(string $level, string $event, array $context = []): void {
    try {
      $level = isset(self::LEVELS[$level]) ? $level : 'error';
      $event = sanitize_key($event);
      $safe_context = self::sanitize_context($context);

      /**
       * Fires for observability integrations whether or not PHP log output is enabled.
       * Context has already passed through the privacy scrubber.
       */
      do_action('koopo_appt_log', $level, $event, $safe_context);

      if (!self::should_emit($level)) return;

      $payload = [
        'timestamp' => gmdate('c'),
        'level' => $level,
        'event' => $event,
        'context' => $safe_context,
      ];
      $encoded = function_exists('wp_json_encode')
        ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES)
        : json_encode($payload, JSON_UNESCAPED_SLASHES);

      if (is_string($encoded)) error_log(self::PREFIX . $encoded);
    } catch (\Throwable $error) {
      // Logging must never interrupt an appointment request.
      unset($error);
    }
  }

  private static function should_emit(string $level): bool {
    $default = defined('WP_DEBUG') && WP_DEBUG ? 'warning' : 'error';
    $minimum = (string) apply_filters('koopo_appt_log_level', $default);
    if (!isset(self::LEVELS[$minimum])) $minimum = $default;

    return self::LEVELS[$level] >= self::LEVELS[$minimum];
  }

  private static function sanitize_context(array $context): array {
    return self::sanitize_array($context, 0);
  }

  private static function sanitize_array(array $values, int $depth): array {
    if ($depth >= self::MAX_DEPTH) return ['_truncated' => true];

    $safe = [];
    $count = 0;
    foreach ($values as $key => $value) {
      if ($count >= self::MAX_ITEMS) {
        $safe['_truncated'] = true;
        break;
      }
      $count++;

      $context_key = is_string($key) ? sanitize_key($key) : $key;
      if (is_string($key) && self::is_sensitive_key($key)) {
        $safe[$context_key] = self::REDACTED;
        continue;
      }

      if (is_array($value)) {
        $safe[$context_key] = self::sanitize_array($value, $depth + 1);
      } elseif (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
        $safe[$context_key] = $value;
      } elseif ($value instanceof \Throwable) {
        $safe[$context_key] = [
          'type' => get_class($value),
          'code' => $value->getCode(),
        ];
      } elseif (is_string($value)) {
        $safe[$context_key] = self::truncate($value);
      } elseif (is_object($value)) {
        $safe[$context_key] = ['type' => get_class($value)];
      } else {
        $safe[$context_key] = gettype($value);
      }
    }
    return $safe;
  }

  private static function is_sensitive_key(string $key): bool {
    return 1 === preg_match(
      '/(?:authorization|password|passwd|secret|token|cookie|session|phone|mobile|email|address|street|postal|zip|customer_name|client_name|notes?|content|message|signature|attachment|file)/i',
      $key
    );
  }

  private static function truncate(string $value): string {
    if (strlen($value) <= self::MAX_STRING_LENGTH) return $value;
    return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
  }
}
