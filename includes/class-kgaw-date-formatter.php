<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 22: Human-Readable Date/Time Formatting
 * Location: includes/class-kgaw-date-formatter.php
 * 
 * Centralized date/time formatting for appointments
 */
class Date_Formatter {

  /**
   * Format datetime for customer/vendor display
   * 
   * @param string $datetime MySQL datetime (YYYY-MM-DD HH:MM:SS)
   * @param string $timezone Timezone string (e.g., 'America/Detroit')
   * @param string $format 'full'|'date'|'time'|'short'|'relative'
   * @return string Human-readable datetime
   */
  public static function format(string $datetime, string $timezone = '', string $format = 'full'): string {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
      return '—';
    }

    // Determine timezone
    if (!$timezone) {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    }

    try {
      $tz = new \DateTimeZone($timezone);
      $dt = new \DateTimeImmutable($datetime, $tz);
    } catch (\Exception $e) {
      return esc_html($datetime); // Fallback to raw if timezone invalid
    }

    switch ($format) {
      case 'full':
        // Monday, January 5, 2026 at 2:00 PM
        return $dt->format('l, F j, Y \a\t g:i A');

      case 'date':
        // Monday, January 5, 2026
        return $dt->format('l, F j, Y');

      case 'time':
        // 2:00 PM
        return $dt->format('g:i A');

      case 'short':
        // Jan 5, 2026 • 2:00 PM
        return $dt->format('M j, Y') . ' • ' . $dt->format('g:i A');

      case 'relative':
        // Today at 2:00 PM | Tomorrow at 2:00 PM | Jan 5 at 2:00 PM
        return self::format_relative($dt);

      case 'admin':
        // 2026-01-05 14:00 (for admin/debugging)
        return $dt->format('Y-m-d H:i');

      default:
        return $dt->format('l, F j, Y \a\t g:i A');
    }
  }

  /**
   * Format datetime range (start → end)
   * 
   * @param string $start_datetime
   * @param string $end_datetime
   * @param string $timezone
   * @param string $format
   * @return string Formatted range
   */
  public static function format_range(string $start, string $end, string $timezone = '', string $format = 'full'): string {
    if (!$start || !$end) return '—';

    if (!$timezone) {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    }

    try {
      $tz = new \DateTimeZone($timezone);
      $dt_start = new \DateTimeImmutable($start, $tz);
      $dt_end = new \DateTimeImmutable($end, $tz);
    } catch (\Exception $e) {
      return esc_html($start . ' – ' . $end);
    }

    // Same day? Show date once
    if ($dt_start->format('Y-m-d') === $dt_end->format('Y-m-d')) {
      if ($format === 'short') {
        // Jan 5, 2026 • 2:00 PM – 3:00 PM
        return $dt_start->format('M j, Y') . ' • ' . 
               $dt_start->format('g:i A') . ' – ' . 
               $dt_end->format('g:i A');
      } else {
        // Monday, January 5, 2026 at 2:00 PM – 3:00 PM
        return $dt_start->format('l, F j, Y \a\t g:i A') . ' – ' . $dt_end->format('g:i A');
      }
    }

    // Different days
    if ($format === 'short') {
      return $dt_start->format('M j, Y \a\t g:i A') . ' – ' . $dt_end->format('M j, Y \a\t g:i A');
    } else {
      return $dt_start->format('l, F j, Y \a\t g:i A') . ' – ' . $dt_end->format('l, F j, Y \a\t g:i A');
    }
  }

  /**
   * Format relative datetime (Today, Tomorrow, etc.)
   * 
   * @param \DateTimeImmutable $dt
   * @return string
   */
  private static function format_relative(\DateTimeImmutable $dt): string {
    $now = new \DateTimeImmutable('now', $dt->getTimezone());
    $today = $now->format('Y-m-d');
    $target = $dt->format('Y-m-d');

    $diff_days = (int) $now->diff($dt)->format('%r%a');

    if ($target === $today) {
      return 'Today at ' . $dt->format('g:i A');
    }

    if ($diff_days === 1) {
      return 'Tomorrow at ' . $dt->format('g:i A');
    }

    if ($diff_days === -1) {
      return 'Yesterday at ' . $dt->format('g:i A');
    }

    // Within 7 days? Show day name
    if ($diff_days > 0 && $diff_days <= 7) {
      return $dt->format('l \a\t g:i A'); // "Monday at 2:00 PM"
    }

    // Otherwise show short date
    return $dt->format('M j \a\t g:i A'); // "Jan 5 at 2:00 PM"
  }

  /**
   * Get timezone abbreviation (EST, PST, etc.)
   * 
   * @param string $timezone
   * @param string $datetime Reference datetime for DST
   * @return string Timezone abbreviation
   */
  public static function get_timezone_abbr(string $timezone, string $datetime = ''): string {
    if (!$timezone) {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    }

    try {
      $tz = new \DateTimeZone($timezone);
      $dt = $datetime ? new \DateTime($datetime, $tz) : new \DateTime('now', $tz);
      return $dt->format('T'); // Returns abbreviation like "EST" or "PDT"
    } catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Format duration in minutes to human-readable
   * 
   * @param int $minutes
   * @return string "1 hour 30 minutes" or "45 minutes"
   */
  public static function format_duration(int $minutes): string {
    if ($minutes <= 0) return '—';

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    $parts = [];
    if ($hours > 0) {
      $parts[] = $hours . ' ' . _n('hour', 'hours', $hours, 'koopo-geo-appointments');
    }
    if ($mins > 0) {
      $parts[] = $mins . ' ' . _n('minute', 'minutes', $mins, 'koopo-geo-appointments');
    }

    return implode(' ', $parts);
  }

  /**
   * Format for JavaScript/API consumption
   * Returns ISO 8601 format with timezone
   * 
   * @param string $datetime
   * @param string $timezone
   * @return string ISO 8601 format
   */
  public static function format_iso(string $datetime, string $timezone = ''): string {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
      return '';
    }

    if (!$timezone) {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    }

    try {
      $tz = new \DateTimeZone($timezone);
      $dt = new \DateTimeImmutable($datetime, $tz);
      return $dt->format('c'); // 2026-01-05T14:00:00-05:00
    } catch (\Exception $e) {
      return $datetime;
    }
  }

  /**
   * Parse and validate datetime input
   * Accepts multiple formats and normalizes to MySQL format
   * 
   * @param string $input User input
   * @param string $timezone
   * @return string|false MySQL datetime or false on error
   */
  public static function parse_input(string $input, string $timezone = '') {
    if (!$timezone) {
      $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    }

    try {
      $tz = new \DateTimeZone($timezone);
      $dt = new \DateTimeImmutable($input, $tz);
      return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
      return false;
    }
  }
}
