<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * BuddyBoss-native provider onboarding.
 *
 * Account and xProfile data stay in BuddyBoss' registration pipeline. Dokan
 * vendor data and the Koopo service profile remain inert signup metadata until
 * BuddyBoss confirms the email activation key.
 */
final class Provider_Onboarding {
  private const QUERY_ARG = 'provider_signup';
  private const MARKER = 'koopo_provider_onboarding';
  private const SIGNUP_META = 'koopo_provider_onboarding_v1';
  private const PENDING_META = '_koopo_provider_onboarding_pending';
  private const PROFILE_META = '_koopo_provider_profile_id';
  private const COMPLETE_META = '_koopo_provider_onboarding_completed';
  private const SELLING_PENDING_META = '_koopo_provider_selling_pending';
  private const VERIFY_COOKIE = 'koopo_provider_verify';
  private const VERIFY_TTL = 1800;
  private const VERIFY_MAX_ATTEMPTS = 6;
  private static $rendered = false;
  private static $activation_redirect = '';

  public static function init(): void {
    if (!function_exists('buddypress')) return;

    add_action('bp_after_account_details_fields', [__CLASS__, 'render_without_xprofile']);
    add_action('bp_after_signup_profile_fields', [__CLASS__, 'render_fields']);
    add_action('bp_signup_validate', [__CLASS__, 'validate_signup']);
    add_filter('bp_signup_usermeta', [__CLASS__, 'add_signup_meta']);
    add_action('bp_complete_signup', [__CLASS__, 'remember_pending_signup'], 20);
    add_action('bp_custom_signup_steps', [__CLASS__, 'render_email_verification']);
    add_action('bp_send_email', [__CLASS__, 'add_verification_code_to_email'], 20, 4);
    add_action('bp_core_activated_user', [__CLASS__, 'activate_provider'], 40, 3);
    add_filter('bp_core_activate_account', [__CLASS__, 'redirect_after_activation'], 50);
    add_action('wp_login', [__CLASS__, 'retry_pending_setup'], 20, 2);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 30);
    add_filter('body_class', [__CLASS__, 'body_class']);
    add_action('rest_api_init', [__CLASS__, 'register_verification_routes']);
    add_action('woocommerce_before_checkout_form', [__CLASS__, 'render_checkout_intro'], 1);
  }

  public static function signup_url(): string {
    $base = function_exists('bp_get_signup_page') ? bp_get_signup_page() : wp_registration_url();
    return add_query_arg(self::QUERY_ARG, '1', $base);
  }

  public static function cta(): array {
    if (!is_user_logged_in()) return ['url' => self::signup_url(), 'label' => __('Create your service profile', 'koopo-appointments')];
    $provider_id = Provider_Profiles::owned_profile_id(get_current_user_id());
    if ($provider_id) return ['url' => self::edit_url(), 'label' => __('Edit your service profile', 'koopo-appointments')];
    if (function_exists('dokan_is_user_seller') && dokan_is_user_seller(get_current_user_id())) return ['url' => self::edit_url(), 'label' => __('Set up your service profile', 'koopo-appointments')];
    return ['url' => home_url('/new-seller/'), 'label' => __('Become a service provider', 'koopo-appointments')];
  }

  public static function edit_url(): string {
    return function_exists('dokan_get_navigation_url')
      ? (string) dokan_get_navigation_url('koopo-professional-profile')
      : home_url('/seller-dashboard/koopo-professional-profile/');
  }

  public static function is_request(): bool {
    if (!empty($_POST[self::MARKER])) return '1' === sanitize_text_field(wp_unslash($_POST[self::MARKER]));
    return isset($_GET[self::QUERY_ARG]) && '1' === sanitize_text_field(wp_unslash($_GET[self::QUERY_ARG]));
  }

  private static function is_form_step(): bool {
    return self::is_request() && (!function_exists('bp_get_current_signup_step') || 'request-details' === bp_get_current_signup_step());
  }

  private static function is_verification_step(): bool {
    return function_exists('bp_get_current_signup_step')
      && 'completed-confirmation' === bp_get_current_signup_step()
      && (self::is_request() || '' !== self::pending_email());
  }

  public static function remember_pending_signup(): void {
    if (!self::is_request() || !function_exists('bp_get_current_signup_step') || 'completed-confirmation' !== bp_get_current_signup_step()) return;
    $email = isset($_POST['signup_email']) && !is_array($_POST['signup_email']) ? sanitize_email(wp_unslash($_POST['signup_email'])) : '';
    if (!$email) return;
    self::set_verification_cookie($email);
  }

  private static function set_verification_cookie(string $email): void {
    $expires = time() + self::VERIFY_TTL;
    $payload = $email . '|' . $expires;
    $value = self::base64url_encode($payload . '|' . hash_hmac('sha256', $payload, wp_salt('auth')));
    setcookie(self::VERIFY_COOKIE, $value, [
      'expires' => $expires,
      'path' => COOKIEPATH ?: '/',
      'domain' => COOKIE_DOMAIN ?: '',
      'secure' => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    $_COOKIE[self::VERIFY_COOKIE] = $value;
  }

  private static function pending_email(): string {
    $value = isset($_COOKIE[self::VERIFY_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::VERIFY_COOKIE])) : '';
    $decoded = self::base64url_decode($value);
    if (!$decoded) return '';
    $parts = explode('|', $decoded);
    if (3 !== count($parts)) return '';
    [$email, $expires, $signature] = $parts;
    $payload = $email . '|' . $expires;
    if ((int) $expires < time() || !is_email($email) || !hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $signature)) return '';
    return sanitize_email($email);
  }

  private static function base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private static function base64url_decode(string $value): string {
    if (!$value || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return '';
    $base64 = strtr($value, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding) $base64 .= str_repeat('=', 4 - $padding);
    $decoded = base64_decode($base64, true);
    return false === $decoded ? '' : $decoded;
  }

  public static function render_email_verification(): void {
    if (!self::is_verification_step()) return;
    $email = self::pending_email();
    if (!$email && isset($_POST['signup_email']) && !is_array($_POST['signup_email'])) $email = sanitize_email(wp_unslash($_POST['signup_email']));
    ?>
    <section class="koopo-email-verification" id="koopo-email-verification" data-endpoint="<?php echo esc_url(rest_url('koopo/v1/provider-onboarding/verify-email')); ?>" data-resend-endpoint="<?php echo esc_url(rest_url('koopo/v1/provider-onboarding/resend-code')); ?>">
      <span class="koopo-onboard-kicker"><?php esc_html_e('Verify your email', 'koopo-appointments'); ?></span>
      <h2><?php esc_html_e('Enter the code we sent you.', 'koopo-appointments'); ?></h2>
      <p><?php printf(esc_html__('We sent a six-digit verification code to %s. It expires with your pending registration.', 'koopo-appointments'), esc_html($email)); ?></p>
      <label class="koopo-onboard-field" for="koopo-verification-email"><span><?php esc_html_e('Email address', 'koopo-appointments'); ?></span><input id="koopo-verification-email" type="email" value="<?php echo esc_attr($email); ?>" autocomplete="email" required></label>
      <label class="koopo-onboard-field" for="koopo-verification-code"><span><?php esc_html_e('Verification code', 'koopo-appointments'); ?></span><input id="koopo-verification-code" class="koopo-verification-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="koopo-verification-status" required></label>
      <div id="koopo-verification-status" class="koopo-verification-status" role="status" aria-live="polite"></div>
      <div class="koopo-onboard-actions"><button type="button" class="koopo-verify-submit"><?php esc_html_e('Verify and continue', 'koopo-appointments'); ?></button><button type="button" class="koopo-verify-resend"><?php esc_html_e('Send a new code', 'koopo-appointments'); ?></button></div>
      <p class="koopo-verification-help"><?php esc_html_e('The activation link in the email still works if you prefer it.', 'koopo-appointments'); ?></p>
    </section>
    <?php
  }

  public static function add_verification_code_to_email(\BP_Email $email, string $email_type, $to, array $args): void {
    unset($to);
    if ('core-user-registration' !== $email_type) return;
    $key = isset($args['tokens']['key']) ? sanitize_text_field((string) $args['tokens']['key']) : '';
    $signup = self::provider_signup_by_key($key);
    if (!$signup) return;
    $code = self::verification_code($key);
    $html = '<h2 style="margin:24px 0 8px">' . esc_html__('Your Koopo verification code', 'koopo-appointments') . '</h2><p style="font-size:30px;font-weight:700;letter-spacing:8px;margin:0 0 16px">' . esc_html($code) . '</p><p>' . esc_html__('Enter this code in the Koopo provider setup form. The activation link below remains available as a fallback.', 'koopo-appointments') . '</p>';
    $plain = "\n\n" . __('Your Koopo verification code:', 'koopo-appointments') . ' ' . $code . "\n" . __('Enter this code in the provider setup form. The activation link remains available as a fallback.', 'koopo-appointments') . "\n";
    $email->set_content_html($html . $email->get_content_html('raw'));
    $email->set_content_plaintext($plain . $email->get_content_plaintext('raw'));
  }

  public static function register_verification_routes(): void {
    register_rest_route('koopo/v1', '/provider-onboarding/verify-email', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'verify_email_code'],
      'permission_callback' => '__return_true',
      'args' => [
        'email' => ['type' => 'string', 'format' => 'email', 'required' => true, 'sanitize_callback' => 'sanitize_email'],
        'code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
      ],
    ]);
    register_rest_route('koopo/v1', '/provider-onboarding/resend-code', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'resend_email_code'],
      'permission_callback' => '__return_true',
      'args' => ['email' => ['type' => 'string', 'format' => 'email', 'required' => true, 'sanitize_callback' => 'sanitize_email']],
    ]);
    register_rest_route('koopo/v1', '/provider-onboarding/prepare-checkout', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'prepare_subscription_checkout'],
      'permission_callback' => [__CLASS__, 'can_prepare_subscription_checkout'],
      'args' => [
        'pack_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true, 'validate_callback' => 'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
      ],
    ]);
  }

  public static function can_prepare_subscription_checkout(): bool {
    if (!is_user_logged_in()) return false;
    $user_id = get_current_user_id();
    return current_user_can('manage_options') || (function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id));
  }

  public static function verify_email_code(\WP_REST_Request $request) {
    $email = sanitize_email((string) $request->get_param('email'));
    $code = preg_replace('/\D+/', '', (string) $request->get_param('code'));
    $limit = self::rate_limit('verify', $email, self::VERIFY_MAX_ATTEMPTS, 15 * MINUTE_IN_SECONDS);
    if (is_wp_error($limit)) return $limit;
    $signup = self::provider_signup_by_email($email);
    if (!$signup || !hash_equals(self::verification_code((string) $signup->activation_key), $code)) {
      return new \WP_Error('invalid_verification_code', __('That verification code is incorrect or expired.', 'koopo-appointments'), ['status' => 422]);
    }
    $user_id = bp_core_activate_signup((string) $signup->activation_key);
    if (is_wp_error($user_id) || !$user_id) return new \WP_Error('activation_failed', __('We could not activate that account. Request a new code and try again.', 'koopo-appointments'), ['status' => 409]);
    wp_set_current_user((int) $user_id);
    wp_set_auth_cookie((int) $user_id, true, is_ssl());
    delete_transient(self::rate_limit_key('verify', $email));
    setcookie(self::VERIFY_COOKIE, '', ['expires' => time() - HOUR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    $provider_id = (int) get_user_meta((int) $user_id, self::PROFILE_META, true);
    $pack_id = isset($signup->meta[self::SIGNUP_META]['pack_id']) ? absint($signup->meta[self::SIGNUP_META]['pack_id']) : 0;
    return new \WP_REST_Response([
      'ok' => true,
      'next_url' => $provider_id ? add_query_arg('welcome', '1', get_permalink($provider_id)) : self::edit_url(),
      'pack_id' => self::is_allowed_pack($pack_id) ? $pack_id : 0,
      'checkout_endpoint' => esc_url_raw(rest_url('koopo/v1/provider-onboarding/prepare-checkout')),
      'nonce' => wp_create_nonce('wp_rest'),
    ], 200);
  }

  public static function prepare_subscription_checkout(\WP_REST_Request $request) {
    $pack_id = absint($request->get_param('pack_id'));
    if (!self::is_allowed_pack($pack_id)) {
      return new \WP_Error('onboarding_pack_not_allowed', __('That subscription is not available during provider onboarding.', 'koopo-appointments'), ['status' => 422]);
    }
    if (!function_exists('WC') || !function_exists('wc_get_product')) {
      return new \WP_Error('woocommerce_unavailable', __('Secure checkout is temporarily unavailable.', 'koopo-appointments'), ['status' => 503]);
    }
    $product = wc_get_product($pack_id);
    if (!$product || !$product->is_purchasable()) {
      return new \WP_Error('onboarding_pack_unavailable', __('That subscription cannot be purchased right now.', 'koopo-appointments'), ['status' => 409]);
    }
    if (function_exists('wc_load_cart') && (!WC()->session || !WC()->cart)) wc_load_cart();
    if (!WC()->cart) return new \WP_Error('woocommerce_cart_unavailable', __('We could not start the secure checkout session.', 'koopo-appointments'), ['status' => 503]);

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
      $existing = !empty($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data'] : null;
      if ($existing && 'product_pack' === $existing->get_type()) WC()->cart->remove_cart_item($cart_item_key);
    }
    $cart_item_key = WC()->cart->add_to_cart($pack_id, 1);
    if (!$cart_item_key) return new \WP_Error('onboarding_pack_cart_failed', __('We could not add that subscription to checkout.', 'koopo-appointments'), ['status' => 409]);
    WC()->cart->calculate_totals();
    WC()->cart->set_session();

    return new \WP_REST_Response([
      'ok' => true,
      'checkout_url' => esc_url_raw(add_query_arg('provider_onboarding', '1', wc_get_checkout_url())),
      'pack' => self::format_subscription_pack($product),
    ], 200);
  }

  public static function subscription_packs(bool $include_all = false): array {
    if (!function_exists('wc_get_product')) return [];
    $selected = array_map('absint', (array) get_option(Admin_Settings::OPTION_ONBOARDING_PACK_IDS, []));
    if (!$include_all && !$selected) return [];
    $ids = get_posts([
      'post_type' => 'product',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
      'tax_query' => [[ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => ['product_pack'] ]],
      'no_found_rows' => true,
    ]);
    $packs = [];
    foreach ($ids as $id) {
      $id = absint($id);
      if (!$include_all && !in_array($id, $selected, true)) continue;
      if ('yes' === get_post_meta($id, '_exclusive_for_admin_only', true)) continue;
      $product = wc_get_product($id);
      if (!$product || 'product_pack' !== $product->get_type()) continue;
      $packs[] = self::format_subscription_pack($product);
    }
    return $packs;
  }

  private static function format_subscription_pack($product): array {
    $short_description = (string) $product->get_short_description();
    $full_description = (string) $product->get_description();
    $summary_source = $short_description ?: $full_description;
    $features_source = $full_description ?: $short_description;
    return [
      'id' => (int) $product->get_id(),
      'name' => (string) $product->get_name(),
      'price' => (float) $product->get_price(),
      'price_html' => (string) $product->get_price_html(),
      'description' => wp_trim_words(wp_strip_all_tags($summary_source), 28),
      'features_html' => $features_source ? wp_kses_post(wpautop($features_source)) : '',
    ];
  }

  private static function is_allowed_pack(int $pack_id): bool {
    if (!$pack_id) return false;
    foreach (self::subscription_packs(false) as $pack) if ((int) $pack['id'] === $pack_id) return true;
    return false;
  }

  public static function resend_email_code(\WP_REST_Request $request) {
    $email = sanitize_email((string) $request->get_param('email'));
    $limit = self::rate_limit('resend', $email, 2, 10 * MINUTE_IN_SECONDS);
    if (is_wp_error($limit)) return $limit;
    $signup = self::provider_signup_by_email($email);
    if ($signup) bp_core_signup_send_validation_email(false, (string) $signup->user_email, (string) $signup->activation_key, (string) $signup->user_login);
    return new \WP_REST_Response(['ok' => true, 'message' => __('If that pending provider account exists, a new code has been sent.', 'koopo-appointments')], 200);
  }

  private static function verification_code(string $key): string {
    $hex = substr(hash_hmac('sha256', $key, wp_salt('auth')), 0, 12);
    return str_pad((string) (hexdec($hex) % 1000000), 6, '0', STR_PAD_LEFT);
  }

  private static function provider_signup_by_key(string $key) {
    if (!$key || !class_exists('BP_Signup')) return null;
    $result = \BP_Signup::get(['activation_key' => $key, 'exclude_active' => true]);
    $signup = !empty($result['signups'][0]) ? $result['signups'][0] : null;
    return $signup && !empty($signup->meta[self::SIGNUP_META]) ? $signup : null;
  }

  private static function provider_signup_by_email(string $email) {
    if (!$email || !function_exists('buddypress')) return null;
    global $wpdb;
    $table = buddypress()->members->table_name_signups;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE active = 0 AND user_email = %s ORDER BY signup_id DESC LIMIT 1", $email));
    if (!$row) return null;
    $row->meta = maybe_unserialize($row->meta);
    return is_array($row->meta) && !empty($row->meta[self::SIGNUP_META]) ? $row : null;
  }

  private static function rate_limit(string $scope, string $email, int $max, int $ttl) {
    $key = self::rate_limit_key($scope, $email);
    $attempts = (int) get_transient($key);
    if ($attempts >= $max) return new \WP_Error('verification_rate_limited', __('Too many attempts. Please wait before trying again.', 'koopo-appointments'), ['status' => 429]);
    set_transient($key, $attempts + 1, $ttl);
    return true;
  }

  private static function rate_limit_key(string $scope, string $email): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    return 'koopo_pv_' . md5($scope . '|' . strtolower($email) . '|' . $ip);
  }

  public static function render_without_xprofile(): void {
    if (function_exists('bp_is_active') && bp_is_active('xprofile') && function_exists('bp_nouveau_base_account_has_xprofile') && bp_nouveau_base_account_has_xprofile()) return;
    self::render_fields();
  }

  public static function render_fields(): void {
    if (self::$rendered || !self::is_request()) return;
    self::$rendered = true;
    $values = self::posted_values();
    $categories = Service_Categories::get_all_categories();
    $packs = self::subscription_packs(false);
    ?>
    <input type="hidden" name="<?php echo esc_attr(self::MARKER); ?>" value="1">
    <section class="register-section koopo-onboard-section" id="koopo-vendor-section" data-koopo-step="store">
      <span class="koopo-onboard-kicker"><?php esc_html_e('Your business', 'koopo-appointments'); ?></span>
      <h2><?php esc_html_e('Set up your storefront', 'koopo-appointments'); ?></h2>
      <p><?php esc_html_e('This makes your account vendor-ready so you can sell bookable services and other products on Koopo.', 'koopo-appointments'); ?></p>
      <div class="koopo-onboard-grid koopo-onboard-grid--two">
        <?php self::text_field('koopo_first_name', __('First name', 'koopo-appointments'), $values['first_name'], true, 'given-name'); ?>
        <?php self::text_field('koopo_last_name', __('Last name', 'koopo-appointments'), $values['last_name'], true, 'family-name'); ?>
      </div>
      <?php self::text_field('koopo_vendor_phone', __('Business phone', 'koopo-appointments'), $values['phone'], true, 'tel', 'tel'); ?>
      <?php self::text_field('koopo_store_name', __('Store name', 'koopo-appointments'), $values['store_name'], true, 'organization'); ?>
      <label class="koopo-onboard-field" for="koopo-store-slug">
        <span><?php esc_html_e('Store URL', 'koopo-appointments'); ?> <b aria-hidden="true">*</b></span>
        <?php do_action('bp_koopo_store_slug_errors'); ?>
        <span class="koopo-onboard-slug"><small><?php echo esc_html(trailingslashit(home_url('/store/'))); ?></small><input id="koopo-store-slug" name="koopo_store_slug" type="text" value="<?php echo esc_attr($values['store_slug']); ?>" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="60" required autocomplete="off"></span>
        <small><?php esc_html_e('Lowercase letters, numbers, and hyphens. You can edit the suggestion.', 'koopo-appointments'); ?></small>
      </label>
    </section>

    <section class="register-section koopo-onboard-section" id="koopo-service-profile-section" data-koopo-step="service">
      <span class="koopo-onboard-kicker"><?php esc_html_e('Your public profile', 'koopo-appointments'); ?></span>
      <h2><?php esc_html_e('How should customers find you?', 'koopo-appointments'); ?></h2>
      <p><?php esc_html_e('Start with the essentials. After email verification, you can add services, availability, locations, pricing, and portfolio photos.', 'koopo-appointments'); ?></p>
      <?php self::text_field('koopo_profile_name', __('Service profile name', 'koopo-appointments'), $values['profile_name'], true, 'organization-title'); ?>
      <?php self::text_field('koopo_headline', __('Short headline', 'koopo-appointments'), $values['headline'], true, 'off', 'text', __('Example: Natural hair specialist and colorist', 'koopo-appointments')); ?>
      <label class="koopo-onboard-field" for="koopo-category">
        <span><?php esc_html_e('Primary service category', 'koopo-appointments'); ?> <b aria-hidden="true">*</b></span>
        <?php do_action('bp_koopo_category_id_errors'); ?>
        <select id="koopo-category" name="koopo_category_id" required>
          <option value=""><?php esc_html_e('Choose a category', 'koopo-appointments'); ?></option>
          <?php foreach ($categories as $category) : ?><option value="<?php echo esc_attr($category['id']); ?>" <?php selected($values['category_id'], (int) $category['id']); ?>><?php echo esc_html(($category['glyph'] ? $category['glyph'] . ' ' : '') . $category['name']); ?></option><?php endforeach; ?>
        </select>
      </label>
      <fieldset class="koopo-onboard-field koopo-onboard-modes">
        <legend><?php esc_html_e('How do you provide services?', 'koopo-appointments'); ?> <b aria-hidden="true">*</b></legend>
        <?php do_action('bp_koopo_service_modes_errors'); ?>
        <?php foreach (self::mode_options() as $key => $label) : ?><label><input type="checkbox" name="koopo_service_modes[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $values['service_modes'], true)); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?>
      </fieldset>
      <label class="koopo-onboard-field" for="koopo-bio">
        <span><?php esc_html_e('About your work', 'koopo-appointments'); ?></span>
        <?php do_action('bp_koopo_bio_errors'); ?>
        <textarea id="koopo-bio" name="koopo_bio" rows="5" maxlength="2000" placeholder="<?php esc_attr_e('Tell customers about your experience, approach, and what makes your service different.', 'koopo-appointments'); ?>"><?php echo esc_textarea($values['bio']); ?></textarea>
      </label>
      <aside class="koopo-onboard-verification"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e('Your profile stays private until your email is verified.', 'koopo-appointments'); ?></strong><p><?php esc_html_e('We will send BuddyBoss’s normal activation email. Opening that link activates your vendor account, publishes this profile, and takes you directly to it.', 'koopo-appointments'); ?></p></div></aside>
    </section>
    <?php if ($packs) : ?>
    <section class="register-section koopo-onboard-section" id="koopo-subscription-section" data-koopo-step="subscription">
      <span class="koopo-onboard-kicker"><?php esc_html_e('Choose your plan', 'koopo-appointments'); ?></span>
      <h2><?php esc_html_e('Start with the features that fit.', 'koopo-appointments'); ?></h2>
      <p><?php esc_html_e('Your selection is reserved during registration. After email verification, Koopo will prepare a secure WooCommerce checkout for this plan.', 'koopo-appointments'); ?></p>
      <div class="koopo-onboard-plans" role="radiogroup" aria-label="<?php esc_attr_e('Provider subscription plans', 'koopo-appointments'); ?>">
        <?php foreach ($packs as $index => $pack) : $selected = $values['pack_id'] ? $values['pack_id'] === (int) $pack['id'] : 0 === $index; ?>
          <?php $input_id = 'koopo-pack-' . (int) $pack['id']; $features_id = 'koopo-pack-features-' . (int) $pack['id']; ?>
          <article class="koopo-onboard-plan<?php echo $selected ? ' is-selected' : ''; ?>">
            <input id="<?php echo esc_attr($input_id); ?>" type="radio" name="koopo_pack_id" value="<?php echo esc_attr($pack['id']); ?>" <?php checked($selected); ?> required>
            <label class="koopo-onboard-plan__choice" for="<?php echo esc_attr($input_id); ?>">
              <span class="koopo-onboard-plan__check" aria-hidden="true"></span>
              <span class="koopo-onboard-plan__body"><strong><?php echo esc_html($pack['name']); ?></strong><?php if ($pack['description']) : ?><small><?php echo esc_html($pack['description']); ?></small><?php endif; ?></span>
              <span class="koopo-onboard-plan__price"><?php echo wp_kses_post($pack['price_html']); ?></span>
            </label>
            <?php if ($pack['features_html']) : ?>
              <button class="koopo-onboard-plan__toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr($features_id); ?>" data-show-label="<?php esc_attr_e('Show features', 'koopo-appointments'); ?>" data-hide-label="<?php esc_attr_e('Hide features', 'koopo-appointments'); ?>"><?php esc_html_e('Show features', 'koopo-appointments'); ?></button>
              <div class="koopo-onboard-plan__features" id="<?php echo esc_attr($features_id); ?>" hidden><?php echo wp_kses_post($pack['features_html']); ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <aside class="koopo-onboard-checkout-note"><span aria-hidden="true">↗</span><div><strong><?php esc_html_e('Secure checkout comes next.', 'koopo-appointments'); ?></strong><p><?php esc_html_e('WooCommerce and your selected payment provider handle payment details and verification. Koopo never receives raw card information.', 'koopo-appointments'); ?></p></div></aside>
    </section>
    <?php endif; ?>
    <?php
  }

  private static function text_field(string $name, string $label, string $value, bool $required = false, string $autocomplete = 'off', string $type = 'text', string $placeholder = ''): void {
    $id = str_replace('_', '-', $name);
    ?><label class="koopo-onboard-field" for="<?php echo esc_attr($id); ?>"><span><?php echo esc_html($label); ?><?php if ($required) : ?> <b aria-hidden="true">*</b><?php endif; ?></span><?php do_action('bp_' . $name . '_errors'); ?><input id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($type); ?>" value="<?php echo esc_attr($value); ?>" maxlength="<?php echo 'tel' === $type ? '30' : '120'; ?>" autocomplete="<?php echo esc_attr($autocomplete); ?>"<?php echo $required ? ' required' : ''; ?><?php if ($placeholder) : ?> placeholder="<?php echo esc_attr($placeholder); ?>"<?php endif; ?>></label><?php
  }

  public static function validate_signup(): void {
    if (!self::is_request() || !function_exists('buddypress')) return;
    $values = self::posted_values();
    $errors = [];
    foreach (['first_name', 'last_name', 'phone', 'store_name', 'store_slug', 'profile_name', 'headline'] as $required) {
      if ('' === $values[$required]) $errors['koopo_' . ($required === 'phone' ? 'vendor_phone' : $required)] = __('This field is required.', 'koopo-appointments');
    }
    if (!$values['category_id'] || !term_exists($values['category_id'], Service_Categories::TAXONOMY)) $errors['koopo_category_id'] = __('Choose a valid service category.', 'koopo-appointments');
    $phone_field_id = self::phone_field_id();
    if ($phone_field_id && '' === self::post('field_' . $phone_field_id)) $errors['field_' . $phone_field_id] = __('Phone number is required for provider accounts.', 'koopo-appointments');
    if (!$values['service_modes']) $errors['koopo_service_modes'] = __('Choose at least one service delivery option.', 'koopo-appointments');
    if (self::subscription_packs(false) && !self::is_allowed_pack($values['pack_id'])) $errors['koopo_pack_id'] = __('Choose an available provider subscription.', 'koopo-appointments');
    if (!$values['store_slug'] || $values['store_slug'] !== sanitize_title($values['store_slug'])) $errors['koopo_store_slug'] = __('Use only lowercase letters, numbers, and hyphens.', 'koopo-appointments');
    if ($values['store_slug'] && get_user_by('slug', $values['store_slug'])) $errors['koopo_store_slug'] = __('That store URL is already in use. Try another.', 'koopo-appointments');

    foreach ($errors as $field => $message) buddypress()->signup->errors[$field] = $message;
  }

  public static function add_signup_meta(array $usermeta): array {
    if (!self::is_request()) return $usermeta;
    $usermeta[self::SIGNUP_META] = self::posted_values();
    return $usermeta;
  }

  public static function activate_provider(int $user_id, string $key, array $user): void {
    unset($key);
    $payload = $user['meta'][self::SIGNUP_META] ?? null;
    if (!is_array($payload) || !$user_id) return;
    update_user_meta($user_id, self::PENDING_META, $payload);
    self::complete_setup($user_id, $payload);
    $provider_id = (int) get_user_meta($user_id, self::PROFILE_META, true);
    if ($provider_id) self::$activation_redirect = get_permalink($provider_id);
  }

  public static function redirect_after_activation($result) {
    if (is_numeric($result) && (int) $result > 0) {
      $provider_id = (int) get_user_meta((int) $result, self::PROFILE_META, true);
      $target = self::$activation_redirect ?: ($provider_id ? get_permalink($provider_id) : '');
      if ($target) {
        bp_core_add_message(__('Your account is active and your service profile is ready.', 'koopo-appointments'));
        bp_core_redirect(add_query_arg('welcome', '1', $target));
      }
    }
    return $result;
  }

  public static function retry_pending_setup(string $login, \WP_User $user): void {
    unset($login);
    $payload = get_user_meta($user->ID, self::PENDING_META, true);
    if (is_array($payload) && $payload) self::complete_setup((int) $user->ID, $payload);
  }

  private static function complete_setup(int $user_id, array $payload): void {
    if ('1' === get_user_meta($user_id, self::COMPLETE_META, true)) return;
    $user = get_userdata($user_id);
    if (!$user) return;

    if (function_exists('dokan_user_update_to_seller') && function_exists('dokan')) {
      $slug = self::unique_store_slug((string) ($payload['store_slug'] ?? ''), $user_id);
      dokan_user_update_to_seller($user, [
        'fname' => (string) ($payload['first_name'] ?? ''),
        'lname' => (string) ($payload['last_name'] ?? ''),
        'phone' => (string) ($payload['phone'] ?? ''),
        'shopname' => (string) ($payload['store_name'] ?? ''),
        'shopurl' => $slug,
        'address' => [],
      ]);
      $vendor = dokan()->vendor->get($user_id);
      if ($vendor && method_exists($vendor, 'make_active')) $vendor->make_active();
      if (function_exists('dokan_is_seller_enabled') && !dokan_is_seller_enabled($user_id)) {
        update_user_meta($user_id, self::SELLING_PENDING_META, 'dokan_policy');
        Logger::warning('provider_onboarding_selling_policy_blocked', ['user_id' => $user_id]);
      } else {
        delete_user_meta($user_id, self::SELLING_PENDING_META);
      }
    } else {
      Logger::warning('provider_onboarding_vendor_pending', ['user_id' => $user_id]);
      return;
    }

    $provider_id = Provider_Profiles::owned_profile_id($user_id);
    if (!$provider_id) {
      $provider_id = Provider_Profiles::create_for_user($user_id, [
        'name' => (string) ($payload['profile_name'] ?? ''),
        'headline' => (string) ($payload['headline'] ?? ''),
        'bio' => (string) ($payload['bio'] ?? ''),
        'phone' => (string) ($payload['phone'] ?? ''),
        'category_id' => (int) ($payload['category_id'] ?? 0),
        'service_modes' => (array) ($payload['service_modes'] ?? []),
      ]);
    }
    if (is_wp_error($provider_id) || !$provider_id) {
      Logger::error('provider_onboarding_profile_failed', ['user_id' => $user_id, 'error' => is_wp_error($provider_id) ? $provider_id->get_error_code() : 'unknown']);
      return;
    }
    update_user_meta($user_id, self::PROFILE_META, (int) $provider_id);
    update_user_meta($user_id, self::COMPLETE_META, '1');
    delete_user_meta($user_id, self::PENDING_META);
    do_action('koopo_appt_provider_onboarding_completed', $user_id, (int) $provider_id);
  }

  private static function unique_store_slug(string $requested, int $user_id): string {
    $base = sanitize_title($requested) ?: 'provider-' . $user_id;
    $slug = $base;
    for ($suffix = 2; $suffix < 1000; $suffix++) {
      $existing = get_user_by('slug', $slug);
      if (!$existing || (int) $existing->ID === $user_id) return $slug;
      $slug = substr($base, 0, 54) . '-' . $suffix;
    }
    return $base . '-' . $user_id;
  }

  private static function posted_values(): array {
    $modes = isset($_POST['koopo_service_modes']) && is_array($_POST['koopo_service_modes']) ? array_map('sanitize_key', wp_unslash($_POST['koopo_service_modes'])) : [];
    $phone_field_id = self::phone_field_id();
    $buddy_phone = $phone_field_id ? self::post('field_' . $phone_field_id) : '';
    return [
      'first_name' => self::post('koopo_first_name'),
      'last_name' => self::post('koopo_last_name'),
      'phone' => self::post('koopo_vendor_phone') ?: $buddy_phone,
      'store_name' => self::post('koopo_store_name'),
      'store_slug' => sanitize_title(self::post('koopo_store_slug')),
      'profile_name' => self::post('koopo_profile_name'),
      'headline' => self::post('koopo_headline'),
      'category_id' => isset($_POST['koopo_category_id']) ? absint($_POST['koopo_category_id']) : 0,
      'service_modes' => array_values(array_intersect(array_keys(self::mode_options()), $modes)),
      'bio' => isset($_POST['koopo_bio']) ? sanitize_textarea_field(wp_unslash($_POST['koopo_bio'])) : '',
      'pack_id' => isset($_POST['koopo_pack_id']) ? absint($_POST['koopo_pack_id']) : 0,
    ];
  }

  private static function post(string $key): string {
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
  }

  private static function phone_field_id(): int {
    return function_exists('xprofile_get_field_id_from_name') ? (int) xprofile_get_field_id_from_name('Phone') : 0;
  }

  private static function mode_options(): array {
    return [
      'at_location' => __('At a business location', 'koopo-appointments'),
      'mobile' => __('Mobile — I travel to customers', 'koopo-appointments'),
      'virtual' => __('Virtual or online', 'koopo-appointments'),
    ];
  }

  public static function enqueue_assets(): void {
    if (self::is_checkout_continuation()) {
      wp_enqueue_style('koopo-provider-checkout', KOOPO_APPT_URL . 'assets/provider-onboarding-checkout.css', [], KOOPO_APPT_VERSION);
      return;
    }
    if (!self::is_form_step() && !self::is_verification_step()) return;
    wp_enqueue_style('koopo-provider-onboarding', KOOPO_APPT_URL . 'assets/provider-onboarding.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-provider-onboarding', KOOPO_APPT_URL . 'assets/provider-onboarding.js', [], KOOPO_APPT_VERSION, true);
    wp_localize_script('koopo-provider-onboarding', 'KOOPO_PROVIDER_ONBOARDING', [
      'bookUrl' => get_post_type_archive_link(Provider_Profiles::POST_TYPE) ?: home_url('/bookable/'),
      'loginUrl' => wp_login_url(self::signup_url()),
      'phoneFieldId' => self::phone_field_id(),
    ]);
  }

  public static function body_class(array $classes): array {
    if (self::is_form_step()) $classes[] = 'koopo-provider-onboarding';
    if (self::is_verification_step()) {
      $classes[] = 'koopo-provider-onboarding';
      $classes[] = 'koopo-provider-verification';
    }
    if (self::is_checkout_continuation()) $classes[] = 'koopo-provider-checkout';
    return $classes;
  }

  private static function is_checkout_continuation(): bool {
    return function_exists('is_checkout') && is_checkout() && is_user_logged_in() && isset($_GET['provider_onboarding']) && '1' === sanitize_text_field(wp_unslash($_GET['provider_onboarding']));
  }

  public static function render_checkout_intro(): void {
    if (!self::is_checkout_continuation()) return;
    $pack = null;
    if (function_exists('WC') && WC()->cart) {
      foreach (WC()->cart->get_cart() as $item) {
        $candidate = !empty($item['data']) && is_object($item['data']) ? $item['data'] : null;
        if ($candidate && 'product_pack' === $candidate->get_type()) { $pack = $candidate; break; }
      }
    }
    ?>
    <section class="koopo-provider-checkout-intro">
      <a href="<?php echo esc_url(get_post_type_archive_link(Provider_Profiles::POST_TYPE) ?: home_url('/bookable/')); ?>" class="koopo-provider-checkout-brand"><strong>Koopo</strong><span><?php esc_html_e('Provider setup', 'koopo-appointments'); ?></span></a>
      <div><small><?php esc_html_e('Final step · Secure checkout', 'koopo-appointments'); ?></small><h1><?php esc_html_e('Confirm your provider plan.', 'koopo-appointments'); ?></h1><p><?php echo $pack ? esc_html(sprintf(__('You selected %s. Complete checkout below to activate the subscription.', 'koopo-appointments'), $pack->get_name())) : esc_html__('Review the secure checkout below to finish provider setup.', 'koopo-appointments'); ?></p></div>
      <aside><span aria-hidden="true">✓</span><p><?php esc_html_e('Payment details are processed by WooCommerce and your configured payment provider. Koopo does not receive raw card data.', 'koopo-appointments'); ?></p></aside>
    </section>
    <?php
  }
}
