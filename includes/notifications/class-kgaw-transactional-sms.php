<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Provider-neutral transactional SMS contract. No SMS is accepted without a structured adapter result. */
final class Transactional_SMS {
  public static function send(string $phone, string $message, array $context): array {
    $phone = Booking_Invitations::normalize_phone($phone);
    $booking_id = absint($context['booking_id'] ?? 0);
    $invitation_id = absint($context['invitation_id'] ?? 0);
    $attempt = max(1, absint($context['attempt'] ?? 1));
    if (!$phone || !$booking_id || !$invitation_id || empty($context['guest_only']) || empty($context['consent_recorded'])) {
      return ['accepted'=>false,'provider_message_id'=>'','error_code'=>'invalid_sms_request','retryable'=>false];
    }
    if (SMS_Compliance::is_suppressed($phone)) {
      return ['accepted'=>false,'provider_message_id'=>'','error_code'=>'sms_recipient_suppressed','retryable'=>false];
    }

    $delivery_id = Notification_Delivery::claim([
      'booking_id' => $booking_id,
      'invitation_id' => $invitation_id,
      'event_name' => 'guest_invitation_' . $attempt,
      'channel' => 'sms',
      'recipient' => $phone,
    ]);
    if (is_wp_error($delivery_id)) {
      return [
        'accepted' => $delivery_id->get_error_code() === 'delivery_already_handled',
        'provider_message_id' => '',
        'error_code' => $delivery_id->get_error_code(),
        'retryable' => false,
      ];
    }

    $context['delivery_id'] = (int) $delivery_id;
    $result = apply_filters('koopo_appt_send_transactional_sms_result', null, $phone, $message, $context);
    if (!is_array($result)) {
      // Preserve the original notification seam for observers, but do not call an
      // action-only integration "sent" because actions cannot return acceptance.
      do_action('koopo_appt_send_transactional_sms', $phone, $message, $context);
      Notification_Delivery::fail((int) $delivery_id, has_action('koopo_appt_send_transactional_sms') ? 'sms_adapter_unverified' : 'sms_adapter_not_configured');
      return [
        'accepted' => false,
        'provider_message_id' => '',
        'error_code' => has_action('koopo_appt_send_transactional_sms') ? 'sms_adapter_unverified' : 'sms_adapter_not_configured',
        'retryable' => false,
      ];
    }

    $accepted = !empty($result['accepted']);
    $provider_message_id = sanitize_text_field((string) ($result['provider_message_id'] ?? ''));
    $error_code = sanitize_key((string) ($result['error_code'] ?? ''));
    $retryable = !empty($result['retryable']);
    if ($accepted) Notification_Delivery::accept_sms((int) $delivery_id, $provider_message_id);
    else Notification_Delivery::fail((int) $delivery_id, $error_code ?: 'sms_provider_rejected');
    return [
      'accepted' => $accepted,
      'provider_message_id' => $provider_message_id,
      'error_code' => $accepted ? '' : ($error_code ?: 'sms_provider_rejected'),
      'retryable' => $retryable,
    ];
  }
}
