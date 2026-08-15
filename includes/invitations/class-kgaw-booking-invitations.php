<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Secure, guest-only appointment invitations that become account-owned before checkout. */
final class Booking_Invitations {
  const STATUS = 'pending_invitation';
  const DEFAULT_HOLD_MINUTES = 120;
  const REWRITE_VERSION = '1';

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('init', [__CLASS__, 'rewrite']);
    add_action('wp_loaded', [__CLASS__, 'ensure_rewrite_rules']);
    add_filter('query_vars', static function(array $vars): array { $vars[] = 'koopo_appointment_invite'; return $vars; });
    add_action('template_redirect', [__CLASS__, 'render_landing']);
    add_action('koopo_appt_cleanup_pending', [__CLASS__, 'expire']);
  }

  public static function rewrite(): void {
    add_rewrite_rule('^appointment-invite/([A-Za-z0-9_-]+)/?$', 'index.php?koopo_appointment_invite=$matches[1]', 'top');
  }

  public static function ensure_rewrite_rules(): void {
    if ((string) get_option('koopo_appt_invite_rewrite_version', '') === self::REWRITE_VERSION) return;
    flush_rewrite_rules(false);
    update_option('koopo_appt_invite_rewrite_version', self::REWRITE_VERSION, false);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/appointment-invites/preview', [
      'methods' => 'POST', 'callback' => [__CLASS__, 'preview_route'], 'permission_callback' => '__return_true',
    ]);
    register_rest_route('koopo/v1', '/appointment-invites/claim', [
      'methods' => 'POST', 'callback' => [__CLASS__, 'claim_route'], 'permission_callback' => static fn(): bool => is_user_logged_in(),
    ]);
    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/invitation/resend', [
      'methods' => 'POST', 'callback' => [__CLASS__, 'resend_route'], 'permission_callback' => [__CLASS__, 'can_manage_route'],
    ]);
    register_rest_route('koopo/v1', '/vendor/bookings/(?P<id>\d+)/invitation/revoke', [
      'methods' => 'POST', 'callback' => [__CLASS__, 'revoke_route'], 'permission_callback' => [__CLASS__, 'can_manage_route'],
    ]);
  }

  public static function can_manage_route(\WP_REST_Request $request): bool {
    $booking = Bookings::get_booking(absint($request['id']));
    return $booking && Resources::can_manage(Resources::booking_resource_id($booking));
  }

  public static function normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) return '+1' . $digits;
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) return '+' . $digits;
    return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : '';
  }

  public static function resolve_user(string $email, string $phone): int {
    $email = sanitize_email($email);
    if ($email) {
      $user = get_user_by('email', $email);
      if ($user) return (int) $user->ID;
    }
    $normalized = self::normalize_phone($phone);
    if ($normalized) {
      $users = get_users(['number'=>2, 'fields'=>'ids', 'meta_key'=>'_koopo_verified_phone_e164', 'meta_value'=>$normalized]);
      if (count($users) === 1) return (int) $users[0];
      $users = get_users(['number'=>2, 'fields'=>'ids', 'meta_key'=>'billing_phone', 'meta_value'=>$phone]);
      if (count($users) === 1) return (int) $users[0];
    }
    return (int) apply_filters('koopo_appt_resolve_user_by_phone', 0, $normalized, $phone);
  }

  public static function create(int $booking_id, int $created_by, array $channels, int $hold_minutes, bool $sms_consent) {
    global $wpdb;
    $booking = Bookings::get_booking($booking_id);
    if (!$booking || (int) $booking->customer_id > 0 || (string) $booking->status !== self::STATUS) {
      return new \WP_Error('invalid_guest_booking', __('Only an unregistered guest appointment can be invited.', 'koopo-appointments'));
    }
    $email = sanitize_email((string) $booking->customer_email);
    $phone = self::normalize_phone((string) $booking->customer_phone);
    $channels = array_values(array_intersect(['email','sms'], array_map('sanitize_key', $channels)));
    if (!$email) $channels = array_values(array_diff($channels, ['email']));
    if (!$phone || !$sms_consent) $channels = array_values(array_diff($channels, ['sms']));
    if (!$channels) return new \WP_Error('invitation_channel_required', __('Choose an available email or text invitation channel.', 'koopo-appointments'));

    $hold_minutes = min(1440, max(30, $hold_minutes ?: self::DEFAULT_HOLD_MINUTES));
    $expires_ts = time() + ($hold_minutes * MINUTE_IN_SECONDS);
    $start_ts = strtotime((string) $booking->start_datetime . ' UTC');
    if ($start_ts) $expires_ts = min($expires_ts, $start_ts - (5 * MINUTE_IN_SECONDS));
    if ($expires_ts <= time()) return new \WP_Error('invitation_time_too_short', __('This appointment starts too soon to send a registration invitation.', 'koopo-appointments'));

    $token = self::token();
    $now = current_time('mysql', true);
    $data = [
      'booking_id'=>$booking_id, 'created_by'=>$created_by, 'token_hash'=>hash('sha256', $token),
      'channels'=>implode(',', $channels), 'status'=>'pending', 'send_attempts'=>0,
      'expires_at'=>gmdate('Y-m-d H:i:s', $expires_ts), 'created_at'=>$now, 'updated_at'=>$now,
    ];
    $table = DB::booking_invites_table();
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE booking_id=%d", $booking_id));
    $saved = $existing
      ? $wpdb->update($table, $data, ['id'=>(int)$existing])
      : $wpdb->insert($table, $data);
    if (false === $saved) return new \WP_Error('invitation_save_failed', __('The invitation could not be created.', 'koopo-appointments'));

    $delivery = self::deliver($booking, $token, $channels, $sms_consent);
    return ['link'=>self::url($token), 'expires_at'=>$data['expires_at'], 'channels'=>$channels, 'delivery'=>$delivery];
  }

  private static function deliver(object $booking, string $token, array $channels, bool $sms_consent): array {
    global $wpdb;
    $table = DB::booking_invites_table();
    $now = current_time('mysql', true);
    $update = ['send_attempts' => (int) $wpdb->get_var($wpdb->prepare("SELECT send_attempts FROM {$table} WHERE booking_id=%d", (int)$booking->id)) + 1, 'updated_at'=>$now];
    $result = ['email'=>false, 'sms'=>false, 'warnings'=>[]];
    $url = self::url($token);
    $service = get_the_title((int) $booking->service_id);
    $subject_id = (int) ($booking->listing_id ?: $booking->provider_id);
    $provider = $subject_id ? get_the_title($subject_id) : __('Your provider', 'koopo-appointments');
    $when = Date_Formatter::format((string)$booking->start_datetime, (string)$booking->timezone, 'full');
    if (in_array('email', $channels, true) && is_email($booking->customer_email)) {
      $body = sprintf("%s scheduled a %s appointment for you on %s.\n\nCreate or sign in to your Koopo account to review the appointment and complete checkout:\n%s", $provider, $service, $when, $url);
      $result['email'] = wp_mail((string)$booking->customer_email, __('Review your Koopo appointment invitation', 'koopo-appointments'), $body);
      if ($result['email']) $update['email_sent_at'] = $now;
      else $result['warnings'][] = 'email_not_sent';
    }
    if (in_array('sms', $channels, true) && $sms_consent) {
      if (has_action('koopo_appt_send_transactional_sms')) {
        $message = sprintf('Koopo invite: %s on %s. Register and checkout: %s', wp_html_excerpt($service, 32, ''), Date_Formatter::format((string)$booking->start_datetime, (string)$booking->timezone, 'date'), $url);
        do_action('koopo_appt_send_transactional_sms', self::normalize_phone((string)$booking->customer_phone), $message, ['type'=>'guest_appointment_invite','booking_id'=>(int)$booking->id,'guest_only'=>true,'consent_recorded'=>true]);
        $result['sms'] = true;
        $update['sms_sent_at'] = $now;
      } else $result['warnings'][] = 'sms_adapter_not_configured';
    }
    $wpdb->update($table, $update, ['booking_id'=>(int)$booking->id]);
    return $result;
  }

  public static function preview_route(\WP_REST_Request $request) {
    $limited = self::rate_limit();
    if (is_wp_error($limited)) return $limited;
    $invite = self::lookup((string)($request->get_json_params()['token'] ?? ''));
    if (is_wp_error($invite)) return $invite;
    return new \WP_REST_Response(self::preview_data($invite), 200);
  }

  public static function claim_route(\WP_REST_Request $request) {
    $token = sanitize_text_field((string)($request->get_json_params()['token'] ?? ''));
    $claimed = self::claim($token, get_current_user_id());
    if (is_wp_error($claimed)) return $claimed;
    return new \WP_REST_Response($claimed, 200);
  }

  public static function claim(string $token, int $user_id) {
    global $wpdb;
    if (!$user_id) return new \WP_Error('authentication_required', __('Sign in or register to accept this appointment.', 'koopo-appointments'), ['status'=>401]);
    $hash = hash('sha256', $token);
    $invites = DB::booking_invites_table();
    $bookings = DB::table();
    $wpdb->query('START TRANSACTION');
    try {
      $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$invites} WHERE token_hash=%s FOR UPDATE", $hash));
      if (!$invite || $invite->status !== 'pending' || strtotime((string)$invite->expires_at . ' UTC') <= time()) throw new \RuntimeException('invitation_unavailable');
      $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bookings} WHERE id=%d FOR UPDATE", (int)$invite->booking_id));
      if (!$booking || $booking->status !== self::STATUS || (int)$booking->customer_id !== 0) throw new \RuntimeException('invitation_unavailable');
      if (!self::identity_matches($booking, $user_id)) throw new \RuntimeException('invitation_identity_mismatch');
      $user = get_userdata($user_id);
      $paid = (float)$booking->price > 0;
      $hold_minutes = max(1, (int)apply_filters('koopo_appt_pending_expire_minutes', 10));
      $updated = $wpdb->update($bookings, [
        'customer_id'=>$user_id,
        'customer_name'=>(string)($booking->customer_name ?: $user->display_name),
        'customer_email'=>(string)($booking->customer_email ?: $user->user_email),
        'customer_phone'=>(string)($booking->customer_phone ?: get_user_meta($user_id,'billing_phone',true)),
        'status'=>$paid ? 'pending_payment' : 'confirmed',
        'hold_expires_at'=>$paid ? gmdate('Y-m-d H:i:s', time()+$hold_minutes*MINUTE_IN_SECONDS) : null,
        'updated_at'=>current_time('mysql'),
      ], ['id'=>(int)$booking->id,'status'=>self::STATUS]);
      if (false === $updated || 0 === $updated) throw new \RuntimeException('invitation_claim_conflict');
      $wpdb->update($invites, ['status'=>'claimed','claimed_user_id'=>$user_id,'claimed_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)], ['id'=>(int)$invite->id,'status'=>'pending']);
      $wpdb->query('COMMIT');
    } catch (\Throwable $error) {
      $wpdb->query('ROLLBACK');
      $messages = [
        'invitation_identity_mismatch'=>__('Sign in with the email address or phone number that received this invitation.', 'koopo-appointments'),
        'invitation_claim_conflict'=>__('This invitation was claimed in another session.', 'koopo-appointments'),
      ];
      return new \WP_Error($error->getMessage(), $messages[$error->getMessage()] ?? __('This invitation is expired or unavailable.', 'koopo-appointments'), ['status'=>$error->getMessage()==='invitation_identity_mismatch'?403:409]);
    }

    $booking = Bookings::get_booking((int)$invite->booking_id);
    if ((float)$booking->price <= 0) {
      Appointment_Messaging::send((int)$booking->id, 'claimed');
      $order = Bookings::create_free_order_for_booking((int)$booking->id);
      if (is_wp_error($order)) return $order;
      return ['claimed'=>true,'booking_id'=>(int)$booking->id,'confirmed'=>true,'order_received_url'=>$order['order_received_url']];
    }
    do_action('koopo_booking_pending_payment', (int)$booking->id, $booking);
    $checkout = Checkout_Cart::prepare_order_for_booking((int)$booking->id);
    if (is_wp_error($checkout)) return $checkout;
    return array_merge(['claimed'=>true,'booking_id'=>(int)$booking->id,'confirmed'=>false], $checkout);
  }

  private static function identity_matches(object $booking, int $user_id): bool {
    $user = get_userdata($user_id);
    if (!$user) return false;
    $email = strtolower(sanitize_email((string)$booking->customer_email));
    if ($email && hash_equals($email, strtolower((string)$user->user_email))) return true;
    $phone = self::normalize_phone((string)$booking->customer_phone);
    $user_phone = self::normalize_phone((string)get_user_meta($user_id, '_koopo_verified_phone_e164', true));
    if (!$user_phone) $user_phone = self::normalize_phone((string)get_user_meta($user_id, 'billing_phone', true));
    if ($phone && $user_phone && hash_equals($phone, $user_phone)) return true;

    // A phone-only invitation is a high-entropy bearer link delivered by SMS.
    // This allows a newly registered member to claim before their account phone
    // field is populated. Email invitations still require an exact email match.
    if (!$email && $phone) {
      global $wpdb;
      $invite = $wpdb->get_row($wpdb->prepare(
        'SELECT channels,sms_sent_at FROM ' . DB::booking_invites_table() . ' WHERE booking_id=%d',
        (int) $booking->id
      ));
      $channels = $invite ? array_filter(explode(',', (string) $invite->channels)) : [];
      return $invite && in_array('sms', $channels, true) && !empty($invite->sms_sent_at);
    }
    return false;
  }

  public static function resend_route(\WP_REST_Request $request) {
    global $wpdb;
    $booking_id = absint($request['id']);
    $invite = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::booking_invites_table().' WHERE booking_id=%d', $booking_id));
    if (!$invite || $invite->status !== 'pending') return new \WP_Error('invitation_unavailable', __('No pending invitation was found.', 'koopo-appointments'), ['status'=>404]);
    if ((int)$invite->send_attempts >= 3) return new \WP_Error('invitation_rate_limited', __('This invitation has reached its resend limit.', 'koopo-appointments'), ['status'=>429]);
    $channels = array_filter(explode(',', (string) $invite->channels));
    // SMS could only have been stored after explicit consent at creation.
    $token = self::token();
    $updated = $wpdb->update(DB::booking_invites_table(), [
      'token_hash'=>hash('sha256', $token),
      'updated_at'=>current_time('mysql', true),
    ], ['id'=>(int)$invite->id, 'status'=>'pending']);
    if (1 !== $updated) return new \WP_Error('invitation_resend_conflict', __('This invitation changed before it could be resent.', 'koopo-appointments'), ['status'=>409]);
    $booking = Bookings::get_booking($booking_id);
    $delivery = self::deliver($booking, $token, $channels, in_array('sms', $channels, true));
    return ['link'=>self::url($token), 'expires_at'=>(string)$invite->expires_at, 'channels'=>array_values($channels), 'delivery'=>$delivery];
  }

  public static function revoke_route(\WP_REST_Request $request) {
    global $wpdb;
    $booking_id = absint($request['id']);
    $updated = $wpdb->update(DB::booking_invites_table(), ['status'=>'revoked','revoked_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)], ['booking_id'=>$booking_id,'status'=>'pending']);
    if (1 !== $updated) return new \WP_Error('invitation_unavailable', __('No pending invitation was found.', 'koopo-appointments'), ['status'=>404]);
    $booking = Bookings::get_booking($booking_id);
    if ($booking && $booking->status === self::STATUS) Bookings::set_status($booking_id, 'cancelled');
    return new \WP_REST_Response(['revoked'=>true], 200);
  }

  public static function expire(): void {
    global $wpdb;
    $table = DB::booking_invites_table();
    $rows = $wpdb->get_results("SELECT id,booking_id FROM {$table} WHERE status='pending' AND expires_at<=UTC_TIMESTAMP() LIMIT 200") ?: [];
    foreach ($rows as $row) {
      $wpdb->update($table, ['status'=>'expired','updated_at'=>current_time('mysql',true)], ['id'=>(int)$row->id,'status'=>'pending']);
      $booking = Bookings::get_booking((int)$row->booking_id);
      if ($booking && $booking->status === self::STATUS) Bookings::set_status((int)$row->booking_id, 'expired');
    }
  }

  private static function lookup(string $token) {
    global $wpdb;
    if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) return new \WP_Error('invitation_unavailable', __('This invitation is unavailable.', 'koopo-appointments'), ['status'=>404]);
    $invite = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::booking_invites_table().' WHERE token_hash=%s', hash('sha256',$token)));
    if (!$invite || $invite->status !== 'pending' || strtotime((string)$invite->expires_at.' UTC') <= time()) return new \WP_Error('invitation_unavailable', __('This invitation is expired or unavailable.', 'koopo-appointments'), ['status'=>410]);
    return $invite;
  }

  private static function preview_data(object $invite): array {
    $booking = Bookings::get_booking((int)$invite->booking_id);
    if (!$booking) return [];
    $subject_id = (int)($booking->listing_id ?: $booking->provider_id);
    return [
      'booking_id'=>(int)$booking->id, 'provider'=>$subject_id?get_the_title($subject_id):'',
      'service'=>get_the_title((int)$booking->service_id),
      'starts_at'=>(string)$booking->start_datetime, 'timezone'=>(string)$booking->timezone,
      'price'=>(float)$booking->price, 'currency'=>(string)$booking->currency,
      'expires_at'=>(string)$invite->expires_at, 'requires_auth'=>!is_user_logged_in(),
    ];
  }

  public static function render_landing(): void {
    $token = (string)get_query_var('koopo_appointment_invite');
    if (!$token) return;
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow', true);
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    $invite = self::lookup($token);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_wp_error($invite) && is_user_logged_in()) {
      check_admin_referer('koopo_claim_invite_' . $token);
      $claimed = self::claim($token, get_current_user_id());
      if (!is_wp_error($claimed)) {
        $target = (string)($claimed['payment_url'] ?? $claimed['checkout_url'] ?? $claimed['order_received_url'] ?? MyAccount::manage_appointment_url((int)$claimed['booking_id']));
        wp_safe_redirect($target); exit;
      }
      $invite = $claimed;
    }
    status_header(is_wp_error($invite) ? 410 : 200);
    $data = is_wp_error($invite) ? [] : self::preview_data($invite);
    $current = self::url($token);
    ?><!doctype html><html><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e('Koopo appointment invitation','koopo-appointments'); ?></title></head><body style="font-family:system-ui;background:#f6f3ed;margin:0;padding:40px 18px"><main style="max-width:620px;margin:auto;background:#fff;border-radius:22px;padding:32px;box-shadow:0 18px 60px rgba(25,20,12,.10)"><?php if(is_wp_error($invite)): ?><h1><?php esc_html_e('Invitation unavailable','koopo-appointments'); ?></h1><p><?php echo esc_html($invite->get_error_message()); ?></p><?php else: ?><p style="text-transform:uppercase;letter-spacing:.12em;color:#8a6c2f;font-weight:700">Koopo appointment</p><h1><?php echo esc_html(sprintf(__('%s invited you','koopo-appointments'),$data['provider'])); ?></h1><p><strong><?php echo esc_html($data['service']); ?></strong><br><?php echo esc_html(Date_Formatter::format($data['starts_at'],$data['timezone'],'full')); ?><br><?php echo esc_html(sprintf('%s %.2f',$data['currency'],$data['price'])); ?></p><?php if(is_user_logged_in()): ?><form method="post"><?php wp_nonce_field('koopo_claim_invite_'.$token); ?><button style="border:0;border-radius:999px;background:#17130d;color:#fff;padding:14px 22px;font-weight:700" type="submit"><?php esc_html_e('Add appointment and continue','koopo-appointments'); ?></button></form><?php else: ?><p><?php esc_html_e('Create a Koopo account or sign in to add this appointment and continue to checkout.','koopo-appointments'); ?></p><p><a href="<?php echo esc_url(wp_login_url($current)); ?>"><?php esc_html_e('Sign in','koopo-appointments'); ?></a> &nbsp; <a href="<?php echo esc_url(add_query_arg('redirect_to',$current,wp_registration_url())); ?>"><?php esc_html_e('Create account','koopo-appointments'); ?></a></p><?php endif; ?><?php endif; ?></main></body></html><?php exit;
  }

  private static function rate_limit() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $key = 'koopo_invite_preview_' . hash_hmac('sha256',$ip,wp_salt('nonce'));
    $count = (int)get_transient($key);
    if ($count >= 20) return new \WP_Error('invitation_rate_limited', __('Too many invitation attempts. Try again later.', 'koopo-appointments'), ['status'=>429]);
    set_transient($key,$count+1,MINUTE_IN_SECONDS);
    return true;
  }

  private static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
  private static function url(string $token): string { return home_url('/appointment-invite/' . rawurlencode($token) . '/'); }
}
