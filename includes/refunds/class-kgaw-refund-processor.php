<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 20: Refund Processor
 * Handles actual WooCommerce refund creation and payment gateway integration
 */
class Refund_Processor {
  private const STRIPE_FEE_META_KEYS = [
    '_stripe_fee',
    'stripe_fee',
    '_stripe_fee_amount',
    '_stripe_processing_fee',
    '_stripe_net',
    'stripe_net',
    '_dokan_stripe_fee',
    'dokan_stripe_fee',
    '_transaction_fee',
  ];

  /**
   * Process a refund through WooCommerce
   * 
   * @param int    $order_id     WooCommerce order ID
   * @param float  $amount       Refund amount
   * @param string $reason       Refund reason/note
   * @param int    $booking_id   Koopo booking ID (for reference)
   * @return array ['success' => bool, 'refund_id' => int, 'automatic' => bool, 'message' => string]
   */
  public static function process_refund(int $order_id, float $amount, string $reason = '', int $booking_id = 0): array {
    
    $order = wc_get_order($order_id);
    if (!$order) {
      return [
        'success' => false,
        'refund_id' => 0,
        'automatic' => false,
        'message' => 'Order not found',
      ];
    }

    $amount = self::maybe_adjust_refund_amount_for_stripe_fee($amount, $order);

    // Validate refund amount
    $order_total = (float) $order->get_total();
    $already_refunded = (float) $order->get_total_refunded();
    $available = $order_total - $already_refunded;

    if ($amount > $available) {
      return [
        'success' => false,
        'refund_id' => 0,
        'automatic' => false,
        'message' => sprintf('Refund amount ($%.2f) exceeds available amount ($%.2f)', $amount, $available),
      ];
    }

    if ($amount <= 0) {
      return [
        'success' => false,
        'refund_id' => 0,
        'automatic' => false,
        'message' => 'Refund amount must be greater than zero',
      ];
    }

    // Prepare refund reason
    $refund_reason = $reason ? wp_strip_all_tags($reason) : 'Koopo appointment refund';
    if ($booking_id) {
      $refund_reason = sprintf('[Booking #%d] %s', $booking_id, $refund_reason);
    }

    // Check if gateway supports automatic refunds
    $supports_refunds = self::gateway_supports_refunds($order);
    $api_refund = $supports_refunds;

    // Attempt to create WooCommerce refund
    try {
      $refund = wc_create_refund([
        'amount'         => $amount,
        'reason'         => $refund_reason,
        'order_id'       => $order_id,
        'refund_payment' => $api_refund, // true = automatic via gateway, false = manual
        'restock_items'  => false, // appointments are services, no inventory
      ]);

      if (is_wp_error($refund)) {
        return [
          'success' => false,
          'refund_id' => 0,
          'automatic' => false,
          'message' => $refund->get_error_message(),
        ];
      }

      $refund_id = $refund->get_id();

      // Add order note with details
      $note = sprintf(
        'Koopo refund processed: $%.2f%s. Reason: %s',
        $amount,
        $api_refund ? ' (automatic via payment gateway)' : ' (manual refund required)',
        $refund_reason
      );
      $fee_note = self::get_stripe_fee_note($order, $api_refund);
      if ($fee_note) {
        $note .= ' ' . $fee_note;
      }
      $order->add_order_note($note);

      return [
        'success' => true,
        'refund_id' => $refund_id,
        'automatic' => $api_refund,
        'amount' => $amount,
        'message' => $api_refund 
          ? 'Refund processed successfully via payment gateway'
          : 'Refund created. Please process manually in your payment gateway.',
      ];

    } catch (\Exception $e) {
      return [
        'success' => false,
        'refund_id' => 0,
        'automatic' => false,
        'message' => 'Refund creation failed: ' . $e->getMessage(),
      ];
    }
  }

  private static function maybe_adjust_refund_amount_for_stripe_fee(float $amount, \WC_Order $order): float {
    if ($amount <= 0) return $amount;
    $supports_refunds = self::gateway_supports_refunds($order);
    if (!$supports_refunds) return $amount;
    if (!self::is_stripe_gateway($order)) return $amount;
    if (!apply_filters('koopo_appt_exclude_stripe_fee_from_refunds', true, $order)) {
      return $amount;
    }

    $fee = self::get_stripe_fee_from_order($order);
    if ($fee <= 0) return $amount;

    $order_total = (float) $order->get_total();
    $already_refunded = (float) $order->get_total_refunded();
    $max_refundable = max(0.0, $order_total - $fee - $already_refunded);
    if ($max_refundable <= 0) return 0.0;
    return min($amount, $max_refundable);
  }

  private static function is_stripe_gateway(\WC_Order $order): bool {
    $method = (string) $order->get_payment_method();
    if ($method && stripos($method, 'stripe') !== false) return true;
    $gateway = WC()->payment_gateways()->payment_gateways()[$method] ?? null;
    $title = $gateway ? (string) $gateway->get_title() : '';
    if ($title && stripos($title, 'stripe') !== false) return true;
    return (bool) apply_filters('koopo_appt_is_stripe_gateway', false, $order);
  }

  private static function get_stripe_fee_from_order(\WC_Order $order): float {
    foreach (self::STRIPE_FEE_META_KEYS as $key) {
      $raw = $order->get_meta($key, true);
      if ($raw === '' || $raw === null) continue;
      $value = self::to_decimal_amount($raw);
      if ($value > 0) {
        if (in_array($key, ['_stripe_net', 'stripe_net'], true)) {
          $total = (float) $order->get_total();
          $fee = max(0.0, $total - $value);
          if ($fee > 0) return $fee;
          continue;
        }
        return $value;
      }
    }

    return (float) apply_filters('koopo_appt_stripe_fee_amount', 0.0, $order);
  }

  private static function to_decimal_amount($raw): float {
    if (is_numeric($raw)) return (float) $raw;
    if (!is_string($raw)) return 0.0;
    $clean = preg_replace('/[^0-9.\-]/', '', $raw);
    return is_numeric($clean) ? (float) $clean : 0.0;
  }

  private static function get_stripe_fee_note(\WC_Order $order, bool $api_refund): string {
    if (!$api_refund || !self::is_stripe_gateway($order)) return '';
    $fee = self::get_stripe_fee_from_order($order);
    if ($fee <= 0) return '';
    return sprintf('(Stripe fee $%.2f is non-refundable unless refunded manually by admin.)', $fee);
  }

  /**
   * Check if the order's payment gateway supports automatic refunds
   * 
   * @param \WC_Order $order
   * @return bool
   */
  public static function gateway_supports_refunds(\WC_Order $order): bool {
    $payment_method = $order->get_payment_method();
    
    if (!$payment_method) {
      return false;
    }

    $gateway = WC()->payment_gateways()->payment_gateways()[$payment_method] ?? null;
    
    if (!$gateway) {
      return false;
    }

    // Check if gateway supports refunds
    return $gateway->supports('refunds');
  }

  /**
   * Get gateway name for display
   * 
   * @param \WC_Order $order
   * @return string
   */
  public static function get_gateway_name(\WC_Order $order): string {
    $payment_method = $order->get_payment_method();
    
    if (!$payment_method) {
      return 'Unknown';
    }

    $gateway = WC()->payment_gateways()->payment_gateways()[$payment_method] ?? null;
    
    return $gateway ? $gateway->get_title() : ucfirst(str_replace('_', ' ', $payment_method));
  }

  /**
   * Get manual refund instructions for gateways that don't support automatic refunds
   * 
   * @param \WC_Order $order
   * @return string
   */
  public static function get_manual_refund_instructions(\WC_Order $order): string {
    $gateway_name = self::get_gateway_name($order);
    $transaction_id = $order->get_transaction_id();

    $instructions = sprintf(
      'This payment gateway (%s) does not support automatic refunds. ',
      esc_html($gateway_name)
    );

    $instructions .= 'Please log in to your payment gateway account and process the refund manually. ';

    if ($transaction_id) {
      $instructions .= sprintf('Transaction ID: %s', esc_html($transaction_id));
    }

    return $instructions;
  }

  /**
   * Get refund capability info for an order
   * Useful for UI to show vendor what to expect
   * 
   * @param int $order_id
   * @return array ['can_refund' => bool, 'automatic' => bool, 'gateway' => string, 'instructions' => string]
   */
  public static function get_refund_info(int $order_id): array {
    $order = wc_get_order($order_id);
    
    if (!$order) {
      return [
        'can_refund' => false,
        'automatic' => false,
        'gateway' => 'Unknown',
        'instructions' => 'Order not found',
      ];
    }

    $gateway_name = self::get_gateway_name($order);
    $supports_refunds = self::gateway_supports_refunds($order);

    $order_total = (float) $order->get_total();
    $already_refunded = (float) $order->get_total_refunded();
    $available = $order_total - $already_refunded;

    $can_refund = $available > 0 && in_array($order->get_status(), ['processing', 'completed', 'on-hold'], true);

    return [
      'can_refund' => $can_refund,
      'automatic' => $supports_refunds,
      'gateway' => $gateway_name,
      'available_amount' => $available,
      'already_refunded' => $already_refunded,
      'instructions' => $supports_refunds 
        ? 'Refund will be processed automatically via ' . $gateway_name
        : self::get_manual_refund_instructions($order),
    ];
  }

  /**
   * Validate if a refund can be processed
   * 
   * @param int   $order_id
   * @param float $amount
   * @return array ['valid' => bool, 'error' => string]
   */
  public static function validate_refund(int $order_id, float $amount): array {
    $order = wc_get_order($order_id);
    
    if (!$order) {
      return ['valid' => false, 'error' => 'Order not found'];
    }

    $order_status = $order->get_status();
    if (!in_array($order_status, ['processing', 'completed', 'on-hold', 'refunded'], true)) {
      return [
        'valid' => false,
        'error' => sprintf('Cannot refund order with status: %s', $order_status),
      ];
    }

    $order_total = (float) $order->get_total();
    $already_refunded = (float) $order->get_total_refunded();
    $available = $order_total - $already_refunded;

    if ($amount <= 0) {
      return ['valid' => false, 'error' => 'Refund amount must be greater than zero'];
    }

    if ($amount > $available) {
      return [
        'valid' => false,
        'error' => sprintf(
          'Refund amount ($%.2f) exceeds available amount ($%.2f)',
          $amount,
          $available
        ),
      ];
    }

    return ['valid' => true, 'error' => ''];
  }
}
