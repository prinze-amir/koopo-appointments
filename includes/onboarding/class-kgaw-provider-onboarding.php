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
  private static $rendered = false;
  private static $activation_redirect = '';

  public static function init(): void {
    if (!function_exists('buddypress')) return;

    add_action('bp_after_account_details_fields', [__CLASS__, 'render_without_xprofile']);
    add_action('bp_after_signup_profile_fields', [__CLASS__, 'render_fields']);
    add_action('bp_signup_validate', [__CLASS__, 'validate_signup']);
    add_filter('bp_signup_usermeta', [__CLASS__, 'add_signup_meta']);
    add_action('bp_core_activated_user', [__CLASS__, 'activate_provider'], 40, 3);
    add_filter('bp_core_activate_account', [__CLASS__, 'redirect_after_activation'], 50);
    add_action('wp_login', [__CLASS__, 'retry_pending_setup'], 20, 2);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 30);
    add_filter('body_class', [__CLASS__, 'body_class']);
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

  public static function render_without_xprofile(): void {
    if (function_exists('bp_is_active') && bp_is_active('xprofile') && function_exists('bp_nouveau_base_account_has_xprofile') && bp_nouveau_base_account_has_xprofile()) return;
    self::render_fields();
  }

  public static function render_fields(): void {
    if (self::$rendered || !self::is_request()) return;
    self::$rendered = true;
    $values = self::posted_values();
    $categories = Service_Categories::get_all_categories();
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
    if (!$values['service_modes']) $errors['koopo_service_modes'] = __('Choose at least one service delivery option.', 'koopo-appointments');
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
    return [
      'first_name' => self::post('koopo_first_name'),
      'last_name' => self::post('koopo_last_name'),
      'phone' => self::post('koopo_vendor_phone'),
      'store_name' => self::post('koopo_store_name'),
      'store_slug' => sanitize_title(self::post('koopo_store_slug')),
      'profile_name' => self::post('koopo_profile_name'),
      'headline' => self::post('koopo_headline'),
      'category_id' => isset($_POST['koopo_category_id']) ? absint($_POST['koopo_category_id']) : 0,
      'service_modes' => array_values(array_intersect(array_keys(self::mode_options()), $modes)),
      'bio' => isset($_POST['koopo_bio']) ? sanitize_textarea_field(wp_unslash($_POST['koopo_bio'])) : '',
    ];
  }

  private static function post(string $key): string {
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
  }

  private static function mode_options(): array {
    return [
      'at_location' => __('At a business location', 'koopo-appointments'),
      'mobile' => __('Mobile — I travel to customers', 'koopo-appointments'),
      'virtual' => __('Virtual or online', 'koopo-appointments'),
    ];
  }

  public static function enqueue_assets(): void {
    if (!self::is_form_step()) return;
    wp_enqueue_style('koopo-provider-onboarding', KOOPO_APPT_URL . 'assets/provider-onboarding.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-provider-onboarding', KOOPO_APPT_URL . 'assets/provider-onboarding.js', [], KOOPO_APPT_VERSION, true);
    wp_localize_script('koopo-provider-onboarding', 'KOOPO_PROVIDER_ONBOARDING', [
      'bookUrl' => get_post_type_archive_link(Provider_Profiles::POST_TYPE) ?: home_url('/bookable/'),
    ]);
  }

  public static function body_class(array $classes): array {
    if (self::is_form_step()) $classes[] = 'koopo-provider-onboarding';
    return $classes;
  }
}
