<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Commit 20: Refund Processor
 * Handles actual WooCommerce refund creation and payment gateway integration
 */
class Refund_Processor {
  private const KOOPO_REFUND_TYPE_TOKEN_REGEX = '/\[#koopo_refund_type:(standard|fraud)\]\s*/i';
  private const KOOPO_REFUND_DEFAULT_FRAUD_KEYWORDS = 'fraud,fraudulent,chargeback,stolen card';
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
    $operation_key = self::idempotency_key($order_id, $amount, $reason, $booking_id);
    if (!self::acquire_order_lock($order_id, 5)) {
      return self::error_result('Another refund is already being processed for this order.', 'refund_busy');
    }

    try {
      do_action('koopo_appt_refund_lock_acquired', $order_id, $booking_id);
      $existing = self::existing_operation($operation_key);
      if ($existing && (string) $existing->status === 'succeeded' && (int) $existing->refund_id > 0) {
        return [
          'success' => true,
          'refund_id' => (int) $existing->refund_id,
          'automatic' => (bool) $existing->automatic,
          'amount' => (float) $existing->processed_amount,
          'idempotent' => true,
          'message' => 'This refund was already processed.',
        ];
      }
      $existing_refund = self::existing_woocommerce_refund($order_id, $operation_key, $booking_id);
      if ($existing_refund) {
        $was_automatic = $existing ? (bool) $existing->automatic : false;
        $operation_id = $existing ? (int) $existing->id : self::claim_operation($operation_key, $booking_id, $order_id, (float) $existing_refund->get_amount());
        if ($operation_id > 0) {
          self::complete_operation($operation_id, (int) $existing_refund->get_id(), (float) $existing_refund->get_amount(), $was_automatic);
        }
        return [
          'success' => true,
          'refund_id' => (int) $existing_refund->get_id(),
          'automatic' => $was_automatic,
          'amount' => (float) $existing_refund->get_amount(),
          'idempotent' => true,
          'message' => 'This refund was already processed.',
        ];
      }
      if ($existing && (string) $existing->status === 'processing' && strtotime((string) $existing->updated_at . ' UTC') > time() - (15 * MINUTE_IN_SECONDS)) {
        return self::error_result('This refund is already being processed.', 'refund_in_progress');
      }
      return self::process_refund_locked($order_id, $amount, $reason, $booking_id, $operation_key);
    } finally {
      self::release_order_lock($order_id);
    }
  }

  private static function process_refund_locked(int $order_id, float $amount, string $reason, int $booking_id, string $operation_key): array {
    $order = wc_get_order($order_id);
    if (!$order) {
      return self::error_result('Order not found', 'order_not_found');
    }

    $base_reason = $reason ? wp_strip_all_tags($reason) : 'Koopo appointment refund';
    $koopo_policy = self::maybe_apply_koopo_refund_policy($order, $amount, $base_reason);
    if (!empty($koopo_policy['applied'])) {
      $amount = (float) $koopo_policy['net_amount'];
      $base_reason = (string) ($koopo_policy['clean_reason'] ?? $base_reason);
    } else {
      $parsed_reason = self::parse_koopo_refund_reason($base_reason);
      $base_reason = $parsed_reason['reason'];
      $amount = self::maybe_adjust_refund_amount_for_stripe_fee($amount, $order);
    }

    // Validate refund amount
    $order_total = (float) $order->get_total();
    $already_refunded = (float) $order->get_total_refunded();
    $available = $order_total - $already_refunded;

    if ($amount > $available) {
      return self::error_result(sprintf('Refund amount ($%.2f) exceeds available amount ($%.2f)', $amount, $available), 'refund_amount_exceeds_available');
    }

    if ($amount <= 0) {
      return self::error_result('Refund amount must be greater than zero', 'invalid_refund_amount');
    }

    // Prepare refund reason
    $refund_reason = $base_reason;
    if ($booking_id) {
      $refund_reason = sprintf('[Booking #%d] %s', $booking_id, $refund_reason);
    }

    // Check if gateway supports automatic refunds
    $supports_refunds = self::gateway_supports_refunds($order);
    $api_refund = $supports_refunds;

    $operation_id = self::claim_operation($operation_key, $booking_id, $order_id, $amount);
    if ($operation_id < 1) {
      return self::error_result('The refund operation could not be recorded.', 'refund_operation_failed');
    }

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
        self::fail_operation($operation_id, sanitize_key((string) $refund->get_error_code()) ?: 'woocommerce_refund_failed');
        return self::error_result($refund->get_error_message(), (string) $refund->get_error_code());
      }

      $refund_id = $refund->get_id();
      self::persist_refund_identity_meta($refund, $operation_key, $booking_id);
      if (!empty($koopo_policy['applied'])) {
        self::persist_koopo_refund_meta($refund, $koopo_policy);
      }
      self::complete_operation($operation_id, $refund_id, $amount, $api_refund);

      // Add order note with details
      $note = sprintf(
        'Koopo refund processed: $%.2f%s. Reason: %s',
        $amount,
        $api_refund ? ' (automatic via payment gateway)' : ' (manual refund required)',
        $refund_reason
      );
      $fee_note = '';
      if (!empty($koopo_policy['applied'])) {
        $fee_note = self::get_koopo_policy_note_for_order($order, $koopo_policy);
      } else {
        $fee_note = self::get_stripe_fee_note($order, $api_refund);
      }
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

    } catch (\Throwable $e) {
      self::fail_operation($operation_id, 'refund_exception');
      Logger::error('refund_processing_failed', [
        'order_id' => $order_id,
        'booking_id' => $booking_id,
        'operation_id' => $operation_id,
        'exception' => $e,
      ]);
      return self::error_result('Refund creation failed: ' . $e->getMessage(), 'refund_exception');
    }
  }

  private static function idempotency_key(int $order_id, float $amount, string $reason, int $booking_id): string {
    if ($booking_id > 0) return hash('sha256', 'koopo-booking-refund|' . $booking_id);
    return hash('sha256', implode('|', [
      'koopo-order-refund',
      $order_id,
      wc_format_decimal($amount, wc_get_price_decimals()),
      wp_strip_all_tags($reason),
    ]));
  }

  private static function acquire_order_lock(int $order_id, int $timeout_seconds): bool {
    global $wpdb;
    if ($order_id < 1) return false;
    $key = 'koopo_appt_refund_' . $order_id;
    $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, max(0, $timeout_seconds)));
    return (string) $result === '1';
  }

  private static function release_order_lock(int $order_id): void {
    global $wpdb;
    if ($order_id < 1) return;
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'koopo_appt_refund_' . $order_id));
  }

  private static function existing_operation(string $operation_key): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . DB::refund_operations_table() . ' WHERE idempotency_key = %s LIMIT 1',
      $operation_key
    ));
    return $row ?: null;
  }

  private static function existing_woocommerce_refund(int $order_id, string $operation_key, int $booking_id): ?\WC_Order_Refund {
    $order = wc_get_order($order_id);
    if (!$order) return null;
    foreach ($order->get_refunds() as $refund) {
      if (!$refund instanceof \WC_Order_Refund) continue;
      if (hash_equals($operation_key, (string) $refund->get_meta('_koopo_refund_operation_key', true))) return $refund;
      if ($booking_id > 0 && (int) $refund->get_meta('_koopo_booking_id', true) === $booking_id) return $refund;
      if ($booking_id > 0 && strpos((string) $refund->get_reason(), '[Booking #' . $booking_id . ']') !== false) return $refund;
    }
    return null;
  }

  private static function claim_operation(string $operation_key, int $booking_id, int $order_id, float $amount): int {
    global $wpdb;
    $table = DB::refund_operations_table();
    $existing = self::existing_operation($operation_key);
    if ($existing) {
      $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = 'processing', requested_amount = %f, error_code = '', attempt_count = attempt_count + 1, updated_at = UTC_TIMESTAMP()
         WHERE id = %d AND status != 'succeeded'",
        $amount,
        (int) $existing->id
      ));
      return $updated === 1 ? (int) $existing->id : 0;
    }

    $inserted = $wpdb->insert($table, [
      'booking_id' => $booking_id ?: null,
      'order_id' => $order_id,
      'idempotency_key' => $operation_key,
      'requested_amount' => $amount,
      'status' => 'processing',
      'created_at' => current_time('mysql', true),
      'updated_at' => current_time('mysql', true),
    ], ['%d','%d','%s','%f','%s','%s','%s']);
    return $inserted ? (int) $wpdb->insert_id : 0;
  }

  private static function complete_operation(int $operation_id, int $refund_id, float $amount, bool $automatic): void {
    global $wpdb;
    $wpdb->update(DB::refund_operations_table(), [
      'processed_amount' => $amount,
      'refund_id' => $refund_id,
      'automatic' => $automatic ? 1 : 0,
      'status' => 'succeeded',
      'error_code' => '',
      'updated_at' => current_time('mysql', true),
    ], ['id' => $operation_id], ['%f','%d','%d','%s','%s','%s'], ['%d']);
  }

  private static function fail_operation(int $operation_id, string $error_code): void {
    global $wpdb;
    if ($operation_id < 1) return;
    $wpdb->update(DB::refund_operations_table(), [
      'status' => 'failed',
      'error_code' => sanitize_key($error_code) ?: 'refund_failed',
      'updated_at' => current_time('mysql', true),
    ], ['id' => $operation_id], ['%s','%s','%s'], ['%d']);
  }

  private static function persist_refund_identity_meta(\WC_Order_Refund $refund, string $operation_key, int $booking_id): void {
    $refund->update_meta_data('_koopo_refund_operation_key', $operation_key);
    if ($booking_id > 0) $refund->update_meta_data('_koopo_booking_id', $booking_id);
    $refund->save();
  }

  private static function error_result(string $message, string $code): array {
    return [
      'success' => false,
      'refund_id' => 0,
      'automatic' => false,
      'error_code' => sanitize_key($code) ?: 'refund_failed',
      'message' => $message,
    ];
  }

  private static function maybe_apply_koopo_refund_policy(\WC_Order $order, float $amount, string $reason): array {
    if (!class_exists('\Koopo\RefundPolicy\Plugin')) {
      return ['applied' => false];
    }
    if (!self::koopo_policy_can_apply_to_order($order)) {
      return ['applied' => false];
    }
    if ($amount <= 0) {
      return ['applied' => false];
    }

    $requested_amount = (float) wc_format_decimal($amount, wc_get_price_decimals());
    $parsed = self::parse_koopo_refund_reason($reason);
    $type = $parsed['type'];
    $clean_reason = $parsed['reason'];

    if ($type === 'standard' && self::koopo_reason_matches_fraud_keywords($clean_reason)) {
      $type = 'fraud';
    }

    $net_amount = $requested_amount;
    $withheld_fee = 0.0;
    if ($type === 'standard') {
      $calculated = self::calculate_koopo_standard_refund_amount($order, $requested_amount);
      $net_amount = (float) $calculated['net_amount'];
      $withheld_fee = (float) $calculated['withheld_fee'];
    }

    if ($net_amount <= 0) {
      $net_amount = $requested_amount;
      $withheld_fee = 0.0;
    }

    return [
      'applied' => true,
      'type' => $type,
      'clean_reason' => $clean_reason,
      'requested_amount' => (float) wc_format_decimal($requested_amount, wc_get_price_decimals()),
      'net_amount' => (float) wc_format_decimal($net_amount, wc_get_price_decimals()),
      'withheld_fee' => (float) wc_format_decimal($withheld_fee, wc_get_price_decimals()),
    ];
  }

  private static function parse_koopo_refund_reason(string $reason): array {
    $type = 'standard';
    $clean = trim($reason);

    if (preg_match(self::KOOPO_REFUND_TYPE_TOKEN_REGEX, $clean, $m)) {
      $type = strtolower((string) $m[1]) === 'fraud' ? 'fraud' : 'standard';
    }

    $clean = (string) preg_replace(self::KOOPO_REFUND_TYPE_TOKEN_REGEX, '', $clean);
    $clean = trim($clean);
    if ($clean === '') {
      $clean = 'Koopo appointment refund';
    }

    return [
      'type' => $type,
      'reason' => $clean,
    ];
  }

  private static function koopo_reason_matches_fraud_keywords(string $reason): bool {
    $reason = strtolower(trim($reason));
    if ($reason === '') return false;

    $settings = get_option('koopo_refund_policy_settings', []);
    $keywords = '';
    if (is_array($settings) && isset($settings['fraud_keywords'])) {
      $keywords = sanitize_text_field((string) $settings['fraud_keywords']);
    }
    if ($keywords === '') {
      $keywords = self::KOOPO_REFUND_DEFAULT_FRAUD_KEYWORDS;
    }

    foreach (explode(',', $keywords) as $keyword) {
      $keyword = strtolower(trim((string) $keyword));
      if ($keyword === '') continue;
      if (strpos($reason, $keyword) !== false) {
        return true;
      }
    }

    return false;
  }

  private static function koopo_policy_can_apply_to_order(\WC_Order $order): bool {
    if ((string) $order->get_payment_method() !== 'dokan_stripe_express') {
      return false;
    }

    $paid_by = (string) $order->get_meta('dokan_gateway_fee_paid_by', true);
    if ($paid_by === '' && class_exists('\WeDevs\DokanPro\Modules\StripeExpress\Support\Settings')) {
      $paid_by = \WeDevs\DokanPro\Modules\StripeExpress\Support\Settings::sellers_pay_processing_fees() ? 'seller' : 'admin';
    }

    return $paid_by === 'seller';
  }

  private static function calculate_koopo_standard_refund_amount(\WC_Order $order, float $requested_amount): array {
    $decimals = wc_get_price_decimals();
    $total_fee = self::koopo_order_processing_fee($order);
    $order_total = (float) $order->get_total('edit');

    if ($total_fee <= 0 || $order_total <= 0) {
      return ['net_amount' => $requested_amount, 'withheld_fee' => 0.0];
    }

    $already_withheld = self::koopo_already_withheld_fee($order);
    $remaining_fee = max(0.0, (float) wc_format_decimal($total_fee - $already_withheld, $decimals));
    if ($remaining_fee <= 0) {
      return ['net_amount' => $requested_amount, 'withheld_fee' => 0.0];
    }

    $proportional_fee = ($total_fee / $order_total) * $requested_amount;
    $withheld_fee = min($remaining_fee, (float) wc_format_decimal($proportional_fee, $decimals));
    $net_amount = (float) wc_format_decimal($requested_amount - $withheld_fee, $decimals);
    if ($net_amount <= 0) {
      return ['net_amount' => $requested_amount, 'withheld_fee' => 0.0];
    }

    return [
      'net_amount' => $net_amount,
      'withheld_fee' => (float) wc_format_decimal($withheld_fee, $decimals),
    ];
  }

  private static function koopo_order_processing_fee(\WC_Order $order): float {
    $fee = 0.0;

    if (class_exists('\WeDevs\DokanPro\Modules\StripeExpress\Support\OrderMeta')) {
      $fee = (float) \WeDevs\DokanPro\Modules\StripeExpress\Support\OrderMeta::get_stripe_fee($order);
    }

    if ($fee <= 0) {
      $fee = (float) wc_format_decimal($order->get_meta('dokan_gateway_fee', true), wc_get_price_decimals());
    }
    if ($fee <= 0) {
      $fee = self::get_stripe_fee_from_order($order);
    }

    return max(0.0, $fee);
  }

  private static function koopo_already_withheld_fee(\WC_Order $order): float {
    $withheld = 0.0;
    foreach ($order->get_refunds() as $refund_order) {
      $withheld += (float) wc_format_decimal($refund_order->get_meta('_koopo_withheld_fee', true), wc_get_price_decimals());
    }
    return max(0.0, $withheld);
  }

  private static function persist_koopo_refund_meta(\WC_Order_Refund $refund, array $policy): void {
    $refund->update_meta_data('_koopo_refund_type', (string) ($policy['type'] ?? 'standard'));
    $refund->update_meta_data('_koopo_requested_amount', (float) ($policy['requested_amount'] ?? 0.0));
    $refund->update_meta_data('_koopo_net_refund_amount', (float) ($policy['net_amount'] ?? 0.0));
    $refund->update_meta_data('_koopo_withheld_fee', (float) ($policy['withheld_fee'] ?? 0.0));
    $refund->save();
  }

  private static function get_koopo_policy_note_for_order(\WC_Order $order, array $policy): string {
    $type = (string) ($policy['type'] ?? 'standard');
    $label = $type === 'fraud' ? 'Fraud (full refund)' : 'Standard (processing fee withheld)';
    $requested = (float) ($policy['requested_amount'] ?? 0.0);
    $net = (float) ($policy['net_amount'] ?? 0.0);
    $withheld = (float) ($policy['withheld_fee'] ?? 0.0);
    $currency = $order->get_currency();

    return sprintf(
      '[Koopo Refund Policy] Type: %s. Requested: %s. Customer Refund: %s. Withheld Fee: %s.',
      $label,
      wp_strip_all_tags(wc_price($requested, ['currency' => $currency])),
      wp_strip_all_tags(wc_price($net, ['currency' => $currency])),
      wp_strip_all_tags(wc_price($withheld, ['currency' => $currency]))
    );
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
