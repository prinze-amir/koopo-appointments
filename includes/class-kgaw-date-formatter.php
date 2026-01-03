<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 22: Enhanced Date Formatter
 * Handles all date/time display formatting with timezone awareness
 *  * Centralized date/time formatting for appointments
 */
class Date_Formatter {

  /**
   * Format a datetime string for display
   * 
   * @param string $datetime MySQL datetime string
   * @param string $timezone Timezone identifier (e.g., 'America/New_York')
   * @param string $format   Format type: 'full', 'short', 'date', 'time'
   * @return string Formatted datetime
   */
  public static function format(string $datetime, string $timezone = '', string $format = 'full'): string {
    
    if (empty($datetime)) {
      return '';
    }

    try {
      $tz = !empty($timezone) ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
      $dt = new \DateTimeImmutable($datetime, $tz);
    } catch (\Exception $e) {
      return $datetime; // Fallback to original
    }

    switch ($format) {
      case 'full':
        // "Monday, January 5, 2026 at 2:00 PM"
        return $dt->format('l, F j, Y') . ' at ' . $dt->format('g:i A');
      
      case 'short':
        // "Jan 5, 2026 2:00 PM"
        return $dt->format('M j, Y g:i A');
      
      case 'date':
        // "January 5, 2026"
        return $dt->format('F j, Y');
      
      case 'time':
        // "2:00 PM"
        return $dt->format('g:i A');
      
      case 'datetime_short':
        // "1/5/26 2:00 PM"
        return $dt->format('n/j/y g:i A');
      
      case 'iso':
        // "2026-01-05T14:00:00-05:00"
        return $dt->format('c');
      
      default:
        return $dt->format('Y-m-d H:i:s');
    }
  }

  /**
   * Format a date range (start to end)
   * Smart collapsing: same day shows time range, different days show both dates
   * 
   * @param string $start_datetime
   * @param string $end_datetime
   * @param string $timezone
   * @return string Formatted range
   */
  public static function format_range(string $start_datetime, string $end_datetime, string $timezone = ''): string {
    
    if (empty($start_datetime) || empty($end_datetime)) {
      return '';
    }

    try {
      $tz = !empty($timezone) ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
      $start = new \DateTimeImmutable($start_datetime, $tz);
      $end = new \DateTimeImmutable($end_datetime, $tz);
    } catch (\Exception $e) {
      return $start_datetime . ' - ' . $end_datetime;
    }

    $start_date = $start->format('Y-m-d');
    $end_date = $end->format('Y-m-d');

    if ($start_date === $end_date) {
      // Same day: "Jan 5, 2026, 2:00 PM - 3:00 PM"
      return $start->format('M j, Y') . ', ' . $start->format('g:i A') . ' - ' . $end->format('g:i A');
    } else {
      // Different days: "Jan 5, 2:00 PM - Jan 6, 3:00 PM"
      return $start->format('M j, g:i A') . ' - ' . $end->format('M j, g:i A');
    }
  }

  /**
   * Format duration in minutes to human-readable string
   * 
   * @param int $minutes Duration in minutes
   * @param bool $compact Use compact format (1h 30m vs 1 hour 30 minutes)
   * @return string Formatted duration
   */
  public static function format_duration(int $minutes, bool $compact = false): string {
    
    if ($minutes < 1) {
      return '0 min';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($compact) {
      // Compact: "1h 30m" or "45m"
      if ($hours > 0 && $mins > 0) {
        return "{$hours}h {$mins}m";
      } elseif ($hours > 0) {
        return "{$hours}h";
      } else {
        return "{$mins}m";
      }
    } else {
      // Full: "1 hour 30 minutes" or "45 minutes"
      $parts = [];
      
      if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
      }
      
      if ($mins > 0) {
        $parts[] = $mins . ' ' . ($mins === 1 ? 'minute' : 'minutes');
      }
      
      return implode(' ', $parts);
    }
  }

  /**
   * Format relative time (e.g., "in 2 hours", "tomorrow at 3 PM")
   * 
   * @param string $datetime
   * @param string $timezone
   * @param bool   $include_time Include time in relative strings like "tomorrow"
   * @return string Relative time string
   */
  public static function relative(string $datetime, string $timezone = '', bool $include_time = true): string {
    
    if (empty($datetime)) {
      return '';
    }

    try {
      $tz = !empty($timezone) ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
      $target = new \DateTimeImmutable($datetime, $tz);
      $now = new \DateTimeImmutable('now', $tz);
    } catch (\Exception $e) {
      return $datetime;
    }

    $diff_seconds = $target->getTimestamp() - $now->getTimestamp();
    $is_future = $diff_seconds > 0;
    $diff_abs = abs($diff_seconds);

    // Less than 1 minute
    if ($diff_abs < 60) {
      return $is_future ? 'in a few seconds' : 'just now';
    }

    // Less than 1 hour
    if ($diff_abs < 3600) {
      $minutes = round($diff_abs / 60);
      $label = $minutes === 1 ? 'minute' : 'minutes';
      return $is_future ? "in {$minutes} {$label}" : "{$minutes} {$label} ago";
    }

    // Less than 24 hours
    if ($diff_abs < 86400) {
      $hours = round($diff_abs / 3600);
      $label = $hours === 1 ? 'hour' : 'hours';
      return $is_future ? "in {$hours} {$label}" : "{$hours} {$label} ago";
    }

    // Check if it's today, tomorrow, or yesterday
    $target_date = $target->format('Y-m-d');
    $today_date = $now->format('Y-m-d');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');

    $time_part = $include_time ? ' at ' . $target->format('g:i A') : '';

    if ($target_date === $today_date) {
      return 'today' . $time_part;
    } elseif ($target_date === $tomorrow) {
      return 'tomorrow' . $time_part;
    } elseif ($target_date === $yesterday) {
      return 'yesterday' . $time_part;
    }

    // Within this week
    $days_diff = abs((int) $now->diff($target)->format('%a'));
    
    if ($days_diff < 7 && $is_future) {
      return $target->format('l') . $time_part; // "Monday at 3:00 PM"
    }

    // Default: use absolute date
    if ($is_future) {
      return 'on ' . $target->format('M j, Y') . $time_part;
    } else {
      return $target->format('M j, Y') . $time_part;
    }
  }

  /**
   * Format for email display with timezone info
   * 
   * @param string $datetime
   * @param string $timezone
   * @return string Email-formatted datetime
   */
  public static function format_for_email(string $datetime, string $timezone = ''): string {
    
    $formatted = self::format($datetime, $timezone, 'full');
    
    if (!empty($timezone)) {
      try {
        $tz = new \DateTimeZone($timezone);
        $dt = new \DateTimeImmutable($datetime, $tz);
        $tz_abbr = $dt->format('T'); // EST, PST, etc.
        
        return $formatted . ' (' . $tz_abbr . ')';
      } catch (\Exception $e) {
        // Fallback without timezone abbreviation
      }
    }
    
    return $formatted;
  }

  /**
   * Get add-to-calendar links for a booking
   * 
   * @param object $booking Booking database row
   * @return array Array of calendar links
   */
  public static function get_calendar_links(object $booking): array {
    
    $start = (string) $booking->start_datetime;
    $end = (string) $booking->end_datetime;
    $timezone = !empty($booking->timezone) ? (string) $booking->timezone : 'UTC';
    
    $service_title = isset($booking->service_id) ? get_the_title((int) $booking->service_id) : 'Appointment';
    $listing_title = isset($booking->listing_id) ? get_the_title((int) $booking->listing_id) : '';
    
    $title = $service_title;
    if ($listing_title) {
      $title .= ' at ' . $listing_title;
    }
    
    $description = 'Booked via Koopo Appointments';
    $location = $listing_title;

    // Google Calendar
    $google = self::get_google_calendar_link($start, $end, $title, $description, $location, $timezone);
    
    // iCal/Apple Calendar
    $ical = self::get_ical_data($start, $end, $title, $description, $location, $timezone);
    
    // Outlook
    $outlook = self::get_outlook_calendar_link($start, $end, $title, $description, $location, $timezone);

    return [
      'google' => $google,
      'ical' => $ical,
      'outlook' => $outlook,
    ];
  }

  /**
   * Generate Google Calendar add link
   */
  private static function get_google_calendar_link(
    string $start,
    string $end,
    string $title,
    string $description,
    string $location,
    string $timezone
  ): string {
    
    try {
      $tz = new \DateTimeZone($timezone);
      $start_dt = new \DateTimeImmutable($start, $tz);
      $end_dt = new \DateTimeImmutable($end, $tz);
    } catch (\Exception $e) {
      return '';
    }

    $start_formatted = $start_dt->format('Ymd\THis');
    $end_formatted = $end_dt->format('Ymd\THis');

    $params = [
      'action' => 'TEMPLATE',
      'text' => $title,
      'dates' => $start_formatted . '/' . $end_formatted,
      'details' => $description,
      'location' => $location,
      'ctz' => $timezone,
    ];

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
  }

  /**
   * Generate Outlook calendar link
   */
  private static function get_outlook_calendar_link(
    string $start,
    string $end,
    string $title,
    string $description,
    string $location,
    string $timezone
  ): string {
    
    try {
      $tz = new \DateTimeZone($timezone);
      $start_dt = new \DateTimeImmutable($start, $tz);
      $end_dt = new \DateTimeImmutable($end, $tz);
    } catch (\Exception $e) {
      return '';
    }

    $start_formatted = $start_dt->format('Y-m-d\TH:i:s');
    $end_formatted = $end_dt->format('Y-m-d\TH:i:s');

    $params = [
      'path' => '/calendar/action/compose',
      'rru' => 'addevent',
      'subject' => $title,
      'startdt' => $start_formatted,
      'enddt' => $end_formatted,
      'body' => $description,
      'location' => $location,
    ];

    return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($params);
  }

  /**
   * Generate iCal data (for download)
   */
  private static function get_ical_data(
    string $start,
    string $end,
    string $title,
    string $description,
    string $location,
    string $timezone
  ): string {
    
    try {
      $tz = new \DateTimeZone($timezone);
      $start_dt = new \DateTimeImmutable($start, $tz);
      $end_dt = new \DateTimeImmutable($end, $tz);
      $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    } catch (\Exception $e) {
      return '';
    }

    $start_formatted = $start_dt->format('Ymd\THis');
    $end_formatted = $end_dt->format('Ymd\THis');
    $dtstamp = $now->format('Ymd\THis\Z');
    $uid = md5($start . $end . $title) . '@koopo.app';

    $ical = "BEGIN:VCALENDAR\r\n";
    $ical .= "VERSION:2.0\r\n";
    $ical .= "PRODID:-//Koopo Appointments//EN\r\n";
    $ical .= "BEGIN:VEVENT\r\n";
    $ical .= "UID:{$uid}\r\n";
    $ical .= "DTSTAMP:{$dtstamp}\r\n";
    $ical .= "DTSTART;TZID={$timezone}:{$start_formatted}\r\n";
    $ical .= "DTEND;TZID={$timezone}:{$end_formatted}\r\n";
    $ical .= "SUMMARY:" . self::escape_ical_text($title) . "\r\n";
    $ical .= "DESCRIPTION:" . self::escape_ical_text($description) . "\r\n";
    $ical .= "LOCATION:" . self::escape_ical_text($location) . "\r\n";
    $ical .= "END:VEVENT\r\n";
    $ical .= "END:VCALENDAR\r\n";

    // Return as data URL for download
    return 'data:text/calendar;charset=utf-8,' . rawurlencode($ical);
  }

  /**
   * Escape text for iCal format
   */
  private static function escape_ical_text(string $text): string {
    $text = str_replace(["\r\n", "\n", "\r"], '\n', $text);
    $text = str_replace(',', '\,', $text);
    $text = str_replace(';', '\;', $text);
    return $text;
  }
}
