<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 20: Refund Policy
 * Manages refund eligibility, cancellation fees, and policy rules
 */
class Refund_Policy {

  /**
   * Check if a booking is refundable based on status and timing
   * 
   * @param object $booking Booking row from database
   * @return array ['allowed' => bool, 'reason' => string, 'fee_percent' => int]
   */
  public static function is_refundable(object $booking): array {
    
    // Check booking status
    $status = (string) $booking->status;
    
    // Only these statuses can be refunded
    $refundable_statuses = ['pending_payment', 'confirmed', 'conflict'];
    if (!in_array($status, $refundable_statuses, true)) {
      return [
        'allowed' => false,
        'reason' => sprintf('Bookings with status "%s" cannot be refunded', ucfirst(str_replace('_', ' ', $status))),
        'fee_percent' => 100,
      ];
    }

    // If already refunded, can't refund again
    if ($status === 'refunded') {
      return [
        'allowed' => false,
        'reason' => 'This booking has already been refunded',
        'fee_percent' => 100,
      ];
    }

    // Get time until appointment
    $start_datetime = (string) $booking->start_datetime;
    $timezone = !empty($booking->timezone) ? (string) $booking->timezone : 'UTC';
    
    try {
      $tz = new \DateTimeZone($timezone);
      $now = new \DateTimeImmutable('now', $tz);
      $start = new \DateTimeImmutable($start_datetime, $tz);
    } catch (\Exception $e) {
      // If timezone/date parsing fails, allow refund with no fee (safe default)
      return [
        'allowed' => true,
        'reason' => 'Allowed',
        'fee_percent' => 0,
      ];
    }

    $hours_until = ($start->getTimestamp() - $now->getTimestamp()) / 3600;

    // Get refund policy rules (filterable per listing)
    $listing_id = (int) $booking->listing_id;
    $policy_rules = self::get_policy_rules($listing_id);

    // Apply policy rules
    foreach ($policy_rules as $rule) {
      if ($hours_until >= $rule['hours_before']) {
        return [
          'allowed' => true,
          'reason' => $rule['reason'],
          'fee_percent' => $rule['fee_percent'],
        ];
      }
    }

    // Default: appointment has passed or is too close
    return [
      'allowed' => false,
      'reason' => 'Refund window has closed. Cancellations must be made at least ' . 
                  $policy_rules[count($policy_rules) - 1]['hours_before'] . ' hours in advance.',
      'fee_percent' => 100,
    ];
  }

  /**
   * Get refund policy rules for a listing
   * Rules are checked in order - first matching rule applies
   * 
   * @param int $listing_id
   * @return array Array of rules: [['hours_before' => int, 'fee_percent' => int, 'reason' => string], ...]
   */
  public static function get_policy_rules(int $listing_id): array {
    
    // Default policy (can be overridden per listing)
    $default_rules = [
      [
        'hours_before' => 48,
        'fee_percent' => 0,
        'reason' => 'Full refund (48+ hours notice)',
      ],
      [
        'hours_before' => 24,
        'fee_percent' => 25,
        'reason' => '75% refund (24-48 hours notice)',
      ],
      [
        'hours_before' => 12,
        'fee_percent' => 50,
        'reason' => '50% refund (12-24 hours notice)',
      ],
      [
        'hours_before' => 0,
        'fee_percent' => 100,
        'reason' => 'No refund (less than 12 hours notice)',
      ],
    ];

    // Allow per-listing override via post meta
    $custom_rules_json = get_post_meta($listing_id, '_koopo_appt_refund_policy', true);
    if ($custom_rules_json) {
      $custom_rules = json_decode($custom_rules_json, true);
      if (is_array($custom_rules) && count($custom_rules) > 0) {
        return $custom_rules;
      }
    }

    // Allow global override via filter
    return apply_filters('koopo_appt_refund_policy_rules', $default_rules, $listing_id);
  }

  /**
   * Calculate refund amount after applying cancellation fees
   * 
   * @param float  $total   Original booking price
   * @param object $booking Booking object
   * @return array ['amount' => float, 'fee' => float, 'fee_percent' => int, 'reason' => string]
   */
  public static function calculate_refund_amount(float $total, object $booking): array {
    
    $check = self::is_refundable($booking);
    
    if (!$check['allowed']) {
      return [
        'amount' => 0.00,
        'fee' => $total,
        'fee_percent' => 100,
        'reason' => $check['reason'],
      ];
    }

    $fee_percent = $check['fee_percent'];
    $fee = ($total * $fee_percent) / 100;
    $refund_amount = $total - $fee;

    return [
      'amount' => round($refund_amount, 2),
      'fee' => round($fee, 2),
      'fee_percent' => $fee_percent,
      'reason' => $check['reason'],
    ];
  }

  /**
   * Get refund policy text for display to vendor or customer
   * 
   * @param int $listing_id
   * @return string HTML formatted policy text
   */
  public static function get_policy_text(int $listing_id): string {
    
    $rules = self::get_policy_rules($listing_id);
    
    $html = '<div class="koopo-refund-policy">';
    $html .= '<h4>Cancellation & Refund Policy</h4>';
    $html .= '<ul>';
    
    foreach ($rules as $rule) {
      $hours = (int) $rule['hours_before'];
      $fee_percent = (int) $rule['fee_percent'];
      
      if ($fee_percent === 0) {
        $text = sprintf('Cancel %d+ hours before: <strong>Full refund</strong>', $hours);
      } elseif ($fee_percent === 100) {
        $text = $hours > 0
          ? sprintf('Cancel less than %d hours before: <strong>No refund</strong>', $hours)
          : '<strong>No refund</strong> for cancellations after appointment time';
      } else {
        $refund_percent = 100 - $fee_percent;
        $text = sprintf(
          'Cancel %d+ hours before: <strong>%d%% refund</strong> (%d%% cancellation fee)',
          $hours,
          $refund_percent,
          $fee_percent
        );
      }
      
      $html .= '<li>' . $text . '</li>';
    }
    
    $html .= '</ul>';
    $html .= '</div>';
    
    return $html;
  }

  /**
   * Get refund policy summary for a specific booking (shows what will happen)
   * 
   * @param object $booking
   * @return array ['refundable' => bool, 'amount' => float, 'fee' => float, 'message' => string]
   */
  public static function get_booking_refund_summary(object $booking): array {
    
    $total = (float) $booking->price;
    $check = self::is_refundable($booking);
    
    if (!$check['allowed']) {
      return [
        'refundable' => false,
        'amount' => 0.00,
        'fee' => $total,
        'message' => $check['reason'],
      ];
    }

    $calc = self::calculate_refund_amount($total, $booking);
    
    $message = sprintf(
      '%s. Customer will receive $%.2f',
      $calc['reason'],
      $calc['amount']
    );
    
    if ($calc['fee'] > 0) {
      $message .= sprintf(' (Cancellation fee: $%.2f)', $calc['fee']);
    }

    return [
      'refundable' => true,
      'amount' => $calc['amount'],
      'fee' => $calc['fee'],
      'message' => $message,
    ];
  }
}
