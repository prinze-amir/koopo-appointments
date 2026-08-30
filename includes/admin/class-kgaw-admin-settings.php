<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Lightweight global settings for the plugin.
 *
 * Per-listing settings (hours, breaks, etc.) live in Settings_API and are saved as post meta.
 * These are site-wide defaults / behavior toggles.
 */
class Admin_Settings {

  const OPTION_HOLD_MINUTES = 'koopo_appt_hold_minutes';
  const OPTION_REFUND_POLICY_DEFAULT = 'koopo_appt_refund_policy_default';
  const OPTION_EMAIL_LOGO = 'koopo_appt_email_logo';
  const OPTION_EMAIL_LOGO_ATTACHMENT_ID = 'koopo_appt_email_logo_attachment_id';
  const OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID = 'koopo_appt_email_logo_active_attachment_id';
  const OPTION_EMAIL_LOGO_SCHEMA_VERSION = 'koopo_appt_email_logo_schema_version';
  const OPTION_GOOGLE_CALENDAR_CLIENT_ID = 'koopo_appt_google_calendar_client_id';
  const OPTION_GOOGLE_CALENDAR_CLIENT_SECRET = 'koopo_appt_google_calendar_client_secret';
  const OPTION_GOOGLE_CALENDAR_ENABLED = 'koopo_appt_google_calendar_enabled';
  const OPTION_MICROSOFT_CALENDAR_CLIENT_ID = 'koopo_appt_microsoft_calendar_client_id';
  const OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET = 'koopo_appt_microsoft_calendar_client_secret';
  const OPTION_MICROSOFT_CALENDAR_TENANT = 'koopo_appt_microsoft_calendar_tenant';
  const OPTION_MICROSOFT_CALENDAR_ENABLED = 'koopo_appt_microsoft_calendar_enabled';
  const OPTION_GEOCODER_MODE = 'koopo_appt_geocoder_mode';
  const OPTION_GEOCODER_AUTO_ORDER = 'koopo_appt_geocoder_auto_order';
  const OPTION_GEOCODER_DAILY_LIMITS = 'koopo_appt_geocoder_daily_limits';
  const OPTION_GEOCODER_OSM_PUBLIC = 'koopo_appt_geocoder_osm_public';
  const OPTION_GEOCODEFARM_KEY = 'koopo_appt_geocodefarm_key';
  const OPTION_GEOAPIFY_KEY = 'koopo_appt_geoapify_key';
  const OPTION_GOOGLE_GEOCODING_KEY = 'koopo_appt_google_geocoding_key';
  const OPTION_ONBOARDING_PACK_IDS = 'koopo_appt_onboarding_pack_ids';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    add_action('admin_init', [__CLASS__, 'maybe_migrate_email_logo_setting'], 5);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('add_option_' . self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, [__CLASS__, 'handle_email_logo_added'], 10, 2);
    add_action('update_option_' . self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, [__CLASS__, 'handle_email_logo_selection'], 10, 2);
    add_action('koopo_bbmu_offload_uploaded', [__CLASS__, 'handle_email_logo_offloaded'], 30, 2);

    // Provide a simple, centralized way to control the pending-hold expiration.
    add_filter('koopo_appt_pending_expire_minutes', [__CLASS__, 'filter_hold_minutes'], 10, 1);
  }

  public static function filter_hold_minutes($minutes) {
    $opt = get_option(self::OPTION_HOLD_MINUTES, '');
    $opt = absint($opt);
    if ($opt > 0) return $opt;
    return (int) $minutes;
  }

  public static function menu() {
    add_options_page(
      'Koopo Appointments',
      'Koopo Appointments',
      'manage_options',
      'koopo-appointments-settings',
      [__CLASS__, 'render_page']
    );
  }

  public static function register_settings() {
    foreach ([Features::OPTION_WAITLIST_ENABLED, Features::OPTION_CLIENT_FORMS_ENABLED] as $option) {
      register_setting('koopo_appt_settings', $option, [
        'type' => 'boolean',
        'sanitize_callback' => static fn($value): int => empty($value) ? 0 : 1,
        'default' => 0,
      ]);
    }
    register_setting('koopo_appt_settings', self::OPTION_HOLD_MINUTES, [
      'type' => 'integer',
      'sanitize_callback' => [__CLASS__, 'sanitize_hold_minutes'],
      'default' => 10,
    ]);
    register_setting('koopo_appt_settings', self::OPTION_REFUND_POLICY_DEFAULT, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_refund_policy_rules'],
      'default' => self::standard_refund_policy(),
    ]);
    register_setting('koopo_appt_settings', self::OPTION_ONBOARDING_PACK_IDS, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_onboarding_pack_ids'],
      'default' => [],
    ]);
    register_setting('koopo_appt_settings', self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, [
      'type' => 'integer',
      'sanitize_callback' => [__CLASS__, 'sanitize_email_logo_attachment_id'],
      'default' => 0,
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GOOGLE_CALENDAR_CLIENT_ID, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_google_calendar_secret'],
      'default' => '',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GOOGLE_CALENDAR_ENABLED, [
      'type' => 'boolean',
      'sanitize_callback' => static fn($value): int => empty($value) ? 0 : 1,
      'default' => 0,
    ]);
    register_setting('koopo_appt_settings', self::OPTION_MICROSOFT_CALENDAR_CLIENT_ID, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_microsoft_calendar_secret'],
      'default' => '',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_MICROSOFT_CALENDAR_TENANT, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_microsoft_calendar_tenant'],
      'default' => 'common',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_MICROSOFT_CALENDAR_ENABLED, [
      'type' => 'boolean',
      'sanitize_callback' => static fn($value): int => empty($value) ? 0 : 1,
      'default' => 0,
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GEOCODER_MODE, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_geocoder_mode'],
      'default' => 'auto',
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GEOCODER_AUTO_ORDER, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_geocoder_auto_order'],
      'default' => ['geocodefarm', 'geoapify', 'google', 'osm'],
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GEOCODER_DAILY_LIMITS, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_geocoder_daily_limits'],
      'default' => self::default_geocoder_daily_limits(),
    ]);
    register_setting('koopo_appt_settings', self::OPTION_GEOCODER_OSM_PUBLIC, [
      'type' => 'boolean',
      'sanitize_callback' => static fn($value): int => empty($value) ? 0 : 1,
      'default' => 0,
    ]);
    foreach ([self::OPTION_GEOCODEFARM_KEY, self::OPTION_GEOAPIFY_KEY, self::OPTION_GOOGLE_GEOCODING_KEY] as $option) {
      register_setting('koopo_appt_settings', $option, [
        'type' => 'string',
        'sanitize_callback' => static fn($value): string => self::sanitize_geocoder_key($value, $option),
        'default' => '',
      ]);
    }
    register_setting('koopo_appt_settings', SMS_Usage::OPTION_PAUSED, [
      'type'=>'boolean', 'sanitize_callback'=>static fn($value): int=>empty($value)?0:1, 'default'=>0,
    ]);
    register_setting('koopo_appt_settings', SMS_Usage::OPTION_DAILY_LIMIT_ENABLED, [
      'type'=>'boolean', 'sanitize_callback'=>static fn($value): int=>empty($value)?0:1, 'default'=>1,
    ]);
    register_setting('koopo_appt_settings', SMS_Usage::OPTION_DAILY_LIMIT, [
      'type'=>'integer', 'sanitize_callback'=>static fn($value): int=>max(1,min(10000000,absint($value))), 'default'=>SMS_Usage::DEFAULT_DAILY_LIMIT,
    ]);
    register_setting('koopo_appt_settings', SMS_Usage::OPTION_MONTHLY_LIMIT_ENABLED, [
      'type'=>'boolean', 'sanitize_callback'=>static fn($value): int=>empty($value)?0:1, 'default'=>1,
    ]);
    register_setting('koopo_appt_settings', SMS_Usage::OPTION_MONTHLY_LIMIT, [
      'type'=>'integer', 'sanitize_callback'=>static fn($value): int=>max(1,min(10000000,absint($value))), 'default'=>SMS_Usage::DEFAULT_MONTHLY_LIMIT,
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_ENABLED, [
      'type'=>'boolean', 'sanitize_callback'=>static fn($value): int=>empty($value)?0:1, 'default'=>0,
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_PROVIDER, [
      'type'=>'string', 'sanitize_callback'=>static fn($value): string=>in_array(sanitize_key((string)$value),SMS_Provider::SUPPORTED_PROVIDERS,true)?sanitize_key((string)$value):'brevo', 'default'=>'brevo',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_BREVO_API_KEY, [
      'type'=>'string', 'sanitize_callback'=>static fn($value): string=>SMS_Provider::sanitize_secret($value,SMS_Provider::OPTION_BREVO_API_KEY), 'default'=>'',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_BREVO_SENDER, [
      'type'=>'string', 'sanitize_callback'=>[SMS_Provider::class,'sanitize_brevo_sender'], 'default'=>'Koopo',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_TWILIO_ACCOUNT_SID, [
      'type'=>'string', 'sanitize_callback'=>[SMS_Provider::class,'sanitize_twilio_account_sid'], 'default'=>'',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_TWILIO_API_KEY_SID, [
      'type'=>'string', 'sanitize_callback'=>[SMS_Provider::class,'sanitize_twilio_api_key_sid'], 'default'=>'',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_TWILIO_API_KEY_SECRET, [
      'type'=>'string', 'sanitize_callback'=>static fn($value): string=>SMS_Provider::sanitize_secret($value,SMS_Provider::OPTION_TWILIO_API_KEY_SECRET), 'default'=>'',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_TWILIO_MESSAGING_SERVICE_SID, [
      'type'=>'string', 'sanitize_callback'=>[SMS_Provider::class,'sanitize_twilio_service_sid'], 'default'=>'',
    ]);
    register_setting('koopo_appt_settings', SMS_Provider::OPTION_TWILIO_FROM_NUMBER, [
      'type'=>'string', 'sanitize_callback'=>[SMS_Provider::class,'sanitize_phone'], 'default'=>'',
    ]);

    add_settings_section(
      'koopo_appt_feature_availability',
      __('Feature Availability', 'koopo-appointments'),
      [__CLASS__, 'feature_availability_description'],
      'koopo-appointments-settings'
    );
    add_settings_field(
      'koopo_appt_release_gates',
      __('Release controls', 'koopo-appointments'),
      [__CLASS__, 'field_feature_availability'],
      'koopo-appointments-settings',
      'koopo_appt_feature_availability'
    );

    add_settings_section(
      'koopo_appt_general',
      'General',
      '__return_false',
      'koopo-appointments-settings'
    );

    add_settings_field(
      self::OPTION_HOLD_MINUTES,
      'Checkout hold (minutes)',
      [__CLASS__, 'field_hold_minutes'],
      'koopo-appointments-settings',
      'koopo_appt_general'
    );

    add_settings_field(
      self::OPTION_REFUND_POLICY_DEFAULT,
      'Default Refund Policy',
      [__CLASS__, 'field_refund_policy_default'],
      'koopo-appointments-settings',
      'koopo_appt_general'
    );

    add_settings_field(
      self::OPTION_EMAIL_LOGO_ATTACHMENT_ID,
      'Email logo',
      [__CLASS__, 'field_email_logo'],
      'koopo-appointments-settings',
      'koopo_appt_general'
    );

    add_settings_section(
      'koopo_appt_provider_onboarding',
      __('Provider Onboarding', 'koopo-appointments'),
      [__CLASS__, 'provider_onboarding_description'],
      'koopo-appointments-settings'
    );
    add_settings_field(
      self::OPTION_ONBOARDING_PACK_IDS,
      __('Subscription options', 'koopo-appointments'),
      [__CLASS__, 'field_onboarding_pack_ids'],
      'koopo-appointments-settings',
      'koopo_appt_provider_onboarding'
    );

    add_settings_section(
      'koopo_appt_sms_delivery',
      'SMS Delivery',
      [__CLASS__, 'sms_delivery_description'],
      'koopo-appointments-settings'
    );
    add_settings_field(
      'koopo_appt_sms_credentials',
      'Transactional SMS',
      [__CLASS__, 'field_sms_delivery'],
      'koopo-appointments-settings',
      'koopo_appt_sms_delivery'
    );

    add_settings_section(
      'koopo_appt_calendar_integrations',
      'Calendar Integrations',
      [__CLASS__, 'calendar_integrations_description'],
      'koopo-appointments-settings'
    );

    add_settings_field(
      'koopo_appt_google_calendar_credentials',
      'Google Calendar',
      [__CLASS__, 'field_google_calendar_credentials'],
      'koopo-appointments-settings',
      'koopo_appt_calendar_integrations'
    );

    add_settings_field(
      'koopo_appt_microsoft_calendar_credentials',
      'Microsoft Outlook',
      [__CLASS__, 'field_microsoft_calendar_credentials'],
      'koopo-appointments-settings',
      'koopo_appt_calendar_integrations'
    );

    add_settings_section(
      'koopo_appt_geocoding',
      'Service Profile Geocoding',
      [__CLASS__, 'geocoding_description'],
      'koopo-appointments-settings'
    );
    add_settings_field(self::OPTION_GEOCODER_MODE, 'Provider mode', [__CLASS__, 'field_geocoder_mode'], 'koopo-appointments-settings', 'koopo_appt_geocoding');
    add_settings_field(self::OPTION_GEOCODER_AUTO_ORDER, 'Automatic routing order', [__CLASS__, 'field_geocoder_auto_order'], 'koopo-appointments-settings', 'koopo_appt_geocoding');
    add_settings_field('koopo_appt_geocoder_keys', 'Provider API keys', [__CLASS__, 'field_geocoder_keys'], 'koopo-appointments-settings', 'koopo_appt_geocoding');
    add_settings_field(self::OPTION_GEOCODER_DAILY_LIMITS, 'Auto daily allowances', [__CLASS__, 'field_geocoder_daily_limits'], 'koopo-appointments-settings', 'koopo_appt_geocoding');
    add_settings_field(self::OPTION_GEOCODER_OSM_PUBLIC, 'Public OSM fallback', [__CLASS__, 'field_geocoder_osm_public'], 'koopo-appointments-settings', 'koopo_appt_geocoding');
  }

  public static function enqueue_admin_assets(string $hook): void {
    if ($hook !== 'settings_page_koopo-appointments-settings') return;
    wp_enqueue_style('koopo-appt-admin-settings', KOOPO_APPT_URL . 'assets/admin-settings.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-appt-admin-settings', KOOPO_APPT_URL . 'assets/admin-settings.js', [], KOOPO_APPT_VERSION, true);
  }

  public static function feature_availability_description(): void {
    ?><p><?php esc_html_e('Keep unfinished operational features installed and testable without exposing them to providers or customers. Disabling a feature preserves its records and configuration.', 'koopo-appointments'); ?></p><?php
  }

  public static function field_feature_availability(): void {
    $features = [
      ['option'=>Features::OPTION_WAITLIST_ENABLED, 'enabled'=>Features::waitlist_enabled(), 'icon'=>'↻', 'title'=>__('Waitlist', 'koopo-appointments'), 'description'=>__('Shows cancellation-fill tools, customer opening alerts, expiring offers, and provider waitlist controls.', 'koopo-appointments')],
      ['option'=>Features::OPTION_CLIENT_FORMS_ENABLED, 'enabled'=>Features::client_forms_enabled(), 'icon'=>'✓', 'title'=>__('Client Records & Forms', 'koopo-appointments'), 'description'=>__('Shows private client workspaces, intake and consent forms, scheduled requests, signatures, and client-file uploads.', 'koopo-appointments')],
    ];
    ?><div class="koopo-feature-gates"><?php foreach ($features as $feature): ?>
      <label class="koopo-feature-toggle <?php echo $feature['enabled'] ? 'is-enabled' : 'is-disabled'; ?>" data-koopo-feature-card>
        <span class="koopo-feature-toggle__icon" aria-hidden="true"><?php echo esc_html($feature['icon']); ?></span>
        <span class="koopo-feature-toggle__copy"><strong><?php echo esc_html($feature['title']); ?></strong><small><?php echo esc_html($feature['description']); ?></small></span>
        <span class="koopo-feature-toggle__control">
          <input type="checkbox" name="<?php echo esc_attr($feature['option']); ?>" value="1" <?php checked($feature['enabled']); ?> data-koopo-feature-toggle />
          <span class="koopo-feature-toggle__track" aria-hidden="true"><span></span></span>
          <b data-koopo-feature-status><?php echo esc_html($feature['enabled'] ? __('Enabled', 'koopo-appointments') : __('Hidden', 'koopo-appointments')); ?></b>
        </span>
      </label>
    <?php endforeach; ?></div>
    <p class="description koopo-feature-gates__note"><strong><?php esc_html_e('Fail-closed behavior:', 'koopo-appointments'); ?></strong> <?php esc_html_e('Disabled features are removed from provider and customer interfaces, their APIs return unavailable, and no new automation or notifications are created.', 'koopo-appointments'); ?></p><?php
  }

  public static function geocoding_description(): void {
    ?>
    <p>Geocode service-profile locations and mobile coverage addresses on the server. Automatic mode uses configured free allowances first, caches successful results, and moves to the next healthy provider when a quota or provider fails.</p>
    <p><strong>Privacy:</strong> public Nominatim is never used for customer home addresses or private mobile-provider origins. API keys are encrypted and never returned to browsers.</p>
    <?php
  }

  public static function sms_delivery_description(): void {
    ?>
    <span id="koopo-appt-sms-delivery"></span>
    <p>Send a one-time appointment invitation only when a provider creates an appointment for an unregistered guest and records that guest's consent to receive the text.</p>
    <p><strong>Privacy:</strong> credentials are encrypted at rest. Registered-customer updates continue through the Koopo inbox, push, and email rather than SMS.</p>
    <?php
  }

  public static function field_sms_delivery(): void {
    $status=SMS_Provider::status();$provider=$status['provider'];
    $brevo_saved=SMS_Provider::secret(SMS_Provider::OPTION_BREVO_API_KEY)!=='';
    $twilio_secret_saved=SMS_Provider::secret(SMS_Provider::OPTION_TWILIO_API_KEY_SECRET)!=='';
    $usage=SMS_Usage::usage();
    $receipt_summary=SMS_Delivery_Receipts::summary(30);
    $brevo_credits=$provider==='brevo'&&$brevo_saved?SMS_Provider::brevo_credits():['status'=>'unavailable','credits'=>null,'fetched_at'=>null,'error_code'=>''];
    $notice=sanitize_key((string)($_GET['koopo_sms_test']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result.
    $notice_code=sanitize_key((string)($_GET['koopo_sms_code']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result.
    ?>
    <fieldset style="max-width:780px">
      <p><span style="display:inline-block;padding:4px 9px;border-radius:999px;background:<?php echo $status['ready']?'#def9eb':'#f0edf5'; ?>;color:<?php echo $status['ready']?'#1e6f5c':'#655d78'; ?>;font-weight:600"><?php echo esc_html($status['ready']?'Ready':'Not ready'); ?></span></p>
      <?php if($notice==='sent'): ?><div class="notice notice-success inline"><p>Test SMS accepted by <?php echo esc_html(ucfirst($provider)); ?>.</p></div><?php elseif($notice==='failed'): ?><div class="notice notice-error inline"><p>Test SMS failed: <code><?php echo esc_html($notice_code?:'unknown_error'); ?></code></p></div><?php endif; ?>
      <p><label><input type="checkbox" name="<?php echo esc_attr(SMS_Provider::OPTION_ENABLED); ?>" value="1" <?php checked($status['enabled']); ?> /> Enable consented guest-invitation SMS</label></p>
      <p><label><input type="checkbox" name="<?php echo esc_attr(SMS_Usage::OPTION_PAUSED); ?>" value="1" <?php checked(SMS_Usage::paused()); ?> /> <strong>Emergency pause all SMS</strong></label></p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:16px 0;">
        <?php foreach(['daily'=>'Today','monthly'=>'This month'] as $period=>$label): $meter=$usage[$period];$percent=$meter['enabled']?min(100,(int)round(($meter['used']/max(1,$meter['limit']))*100)):0; ?>
          <div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff;">
            <strong><?php echo esc_html($label); ?></strong>
            <div style="font-size:20px;margin:5px 0;"><?php echo esc_html(number_format_i18n($meter['used'])); ?> <?php echo $meter['enabled']?'/ '.esc_html(number_format_i18n($meter['limit'])):esc_html__('used (limit off)','koopo-appointments'); ?></div>
            <div style="height:8px;background:#e8e8ea;border-radius:999px;overflow:hidden;"><span style="display:block;height:100%;width:<?php echo esc_attr($percent); ?>%;background:<?php echo $percent>=100?'#b32d2e':($percent>=90?'#dba617':'#2271b1'); ?>;"></span></div>
            <small><?php echo esc_html(sprintf('%d accepted, %d pending, %d failed before acceptance. Resets %s UTC.', $meter['sent'], $meter['reserved'], $meter['failed'], gmdate('M j, Y H:i', strtotime($meter['period_end'].' UTC')))); ?></small>
          </div>
        <?php endforeach; ?>
        <div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff;">
          <strong>Brevo SMS credits</strong>
          <div style="font-size:20px;margin:5px 0;"><?php echo $brevo_credits['status']==='available'?esc_html(number_format_i18n((int)$brevo_credits['credits'])):esc_html__('Unavailable','koopo-appointments'); ?></div>
          <small><?php echo $brevo_credits['status']==='available'?esc_html__('Reported by Brevo; cached for five minutes.','koopo-appointments'):esc_html__('Shown when Brevo is selected and its account API returns an SMS plan balance.','koopo-appointments'); ?></small>
        </div>
        <div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff;">
          <strong>Delivery receipts (30 days)</strong>
          <div style="font-size:20px;margin:5px 0;"><?php echo esc_html(number_format_i18n($receipt_summary['delivered'])); ?> delivered</div>
          <small><?php echo esc_html(sprintf('%d accepted, %d sent to carrier, %d skipped, rejected, or bounced.', $receipt_summary['accepted'], $receipt_summary['sent'], $receipt_summary['failed'])); ?></small>
        </div>
      </div>
      <p>
        <label style="display:inline-block;margin-right:24px;"><input type="checkbox" name="<?php echo esc_attr(SMS_Usage::OPTION_DAILY_LIMIT_ENABLED); ?>" value="1" <?php checked(SMS_Usage::limit_enabled('daily')); ?> /> Enforce daily limit<br /><input type="number" min="1" max="10000000" step="1" name="<?php echo esc_attr(SMS_Usage::OPTION_DAILY_LIMIT); ?>" value="<?php echo esc_attr(SMS_Usage::limit('daily')); ?>" /></label>
        <label style="display:inline-block;"><input type="checkbox" name="<?php echo esc_attr(SMS_Usage::OPTION_MONTHLY_LIMIT_ENABLED); ?>" value="1" <?php checked(SMS_Usage::limit_enabled('monthly')); ?> /> Enforce monthly limit<br /><input type="number" min="1" max="10000000" step="1" name="<?php echo esc_attr(SMS_Usage::OPTION_MONTHLY_LIMIT); ?>" value="<?php echo esc_attr(SMS_Usage::limit('monthly')); ?>" /></label>
      </p>
      <p class="description">Koopo reserves quota before contacting the provider, so concurrent requests cannot exceed these global caps. Accepted sends count as used; definitive failures release the reservation.</p>
      <p><label for="koopo-appt-sms-provider"><strong>Provider</strong></label><br /><select id="koopo-appt-sms-provider" name="<?php echo esc_attr(SMS_Provider::OPTION_PROVIDER); ?>"><option value="brevo" <?php selected($provider,'brevo'); ?>>Brevo</option></select></p>
      <div class="koopo-sms-provider-fields" data-provider="brevo">
        <h4>Brevo</h4>
        <div class="notice notice-warning inline"><p><strong>US/Canada compliance:</strong> Brevo requires an approved toll-free number before transactional SMS can reliably reach US or Canadian recipients. API acceptance is not delivery confirmation. <a href="https://app.brevo.com/sms-compliance/client-details?country=USA%20%26%20Canada" target="_blank" rel="noopener noreferrer">Open Brevo registration</a>.</p></div>
        <p><label><strong>API key</strong><br /><input type="password" class="large-text" name="<?php echo esc_attr(SMS_Provider::OPTION_BREVO_API_KEY); ?>" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr($brevo_saved?'Saved securely — leave blank to keep current key':'Enter Brevo API key'); ?>" /></label><?php if($brevo_saved): ?><br /><label><input type="checkbox" name="<?php echo esc_attr(SMS_Provider::OPTION_BREVO_API_KEY.'_clear'); ?>" value="1" /> Remove saved key</label><?php endif; ?></p>
        <p><label><strong>Sender ID</strong><br /><input type="text" class="regular-text" maxlength="15" name="<?php echo esc_attr(SMS_Provider::OPTION_BREVO_SENDER); ?>" value="<?php echo esc_attr(SMS_Provider::brevo_sender()); ?>" /></label><br /><span class="description">Letters and numbers only. Brevo may require sender registration for the destination country.</span></p>
      </div>
      <div class="koopo-sms-provider-fields" data-provider="twilio" hidden>
        <h4>Twilio</h4>
        <div class="notice notice-warning inline"><p>Twilio is unavailable until signed delivery, STOP, and HELP webhooks are implemented.</p></div>
        <p><label><strong>Account SID</strong><br /><input type="text" class="large-text code" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_ACCOUNT_SID); ?>" value="<?php echo esc_attr(SMS_Provider::twilio_account_sid()); ?>" autocomplete="off" /></label></p>
        <p><label><strong>API Key SID</strong><br /><input type="text" class="large-text code" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_API_KEY_SID); ?>" value="<?php echo esc_attr(SMS_Provider::twilio_api_key_sid()); ?>" autocomplete="off" /></label></p>
        <p><label><strong>API Key secret</strong><br /><input type="password" class="large-text" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_API_KEY_SECRET); ?>" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr($twilio_secret_saved?'Saved securely — leave blank to keep current secret':'Enter Twilio API Key secret'); ?>" /></label><?php if($twilio_secret_saved): ?><br /><label><input type="checkbox" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_API_KEY_SECRET.'_clear'); ?>" value="1" /> Remove saved secret</label><?php endif; ?></p>
        <p><label><strong>Messaging Service SID</strong><br /><input type="text" class="large-text code" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_MESSAGING_SERVICE_SID); ?>" value="<?php echo esc_attr(SMS_Provider::twilio_messaging_service_sid()); ?>" /></label><br /><span class="description">Preferred. Starts with <code>MG</code>. If supplied, it takes precedence over From number.</span></p>
        <p><label><strong>From number</strong><br /><input type="tel" class="regular-text" name="<?php echo esc_attr(SMS_Provider::OPTION_TWILIO_FROM_NUMBER); ?>" value="<?php echo esc_attr(SMS_Provider::twilio_from_number()); ?>" placeholder="+13135550100" /></label></p>
      </div>
      <hr />
      <p><label for="koopo-appt-sms-test-phone"><strong>Test recipient</strong></label><br /><input type="tel" id="koopo-appt-sms-test-phone" class="regular-text" placeholder="+13135550100" /> <button type="button" class="button" id="koopo-appt-sms-test-button">Send test SMS</button></p>
      <p class="description">Save settings first. A test sends one real SMS and may incur provider charges. “Accepted” means the provider accepted the API request; delivery is confirmed separately by the receipt meter.</p>
    </fieldset>
    <script>jQuery(function($){function fields(){var p=$('#koopo-appt-sms-provider').val();$('.koopo-sms-provider-fields').hide().filter('[data-provider="'+p+'"]').show();}$('#koopo-appt-sms-provider').on('change',fields);fields();$('#koopo-appt-sms-test-button').on('click',function(){var phone=$('#koopo-appt-sms-test-phone').val().trim();if(!phone){window.alert('Enter a test phone number including country code.');return;}var form=$('<form>',{method:'post',action:<?php echo wp_json_encode(admin_url('admin-post.php')); ?>}).append($('<input>',{type:'hidden',name:'action',value:'koopo_appt_sms_test'}),$('<input>',{type:'hidden',name:'test_phone',value:phone}),$('<input>',{type:'hidden',name:'_wpnonce',value:<?php echo wp_json_encode(wp_create_nonce('koopo_appt_sms_test')); ?>}));$('body').append(form);form.trigger('submit');});});</script>
    <?php
  }

  public static function field_geocoder_mode(): void {
    $mode = self::geocoder_mode();
    $labels = ['auto'=>'Automatic router','geocodefarm'=>'GeocodeFarm','geoapify'=>'Geoapify','google'=>'Google Geocoding','osm'=>'OpenStreetMap / Nominatim (public addresses only)','disabled'=>'Disabled'];
    ?><select name="<?php echo esc_attr(self::OPTION_GEOCODER_MODE); ?>"><?php foreach ($labels as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($mode, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><?php
  }

  public static function field_geocoder_auto_order(): void {
    ?><input type="text" class="large-text code" name="<?php echo esc_attr(self::OPTION_GEOCODER_AUTO_ORDER); ?>" value="<?php echo esc_attr(implode(',', self::geocoder_auto_order())); ?>" />
    <p class="description">Comma-separated: <code>geocodefarm,geoapify,google,osm</code>. Providers without a key, with no remaining allowance, or in a health cooldown are skipped.</p><?php
  }

  public static function field_geocoder_keys(): void {
    $providers = [
      'geocodefarm' => ['GeocodeFarm', self::OPTION_GEOCODEFARM_KEY],
      'geoapify' => ['Geoapify', self::OPTION_GEOAPIFY_KEY],
      'google' => ['Google Geocoding', self::OPTION_GOOGLE_GEOCODING_KEY],
    ];
    ?><fieldset style="max-width:760px"><?php foreach ($providers as $provider => [$label, $option]): $saved = self::geocoder_api_key($provider) !== ''; ?>
      <p><label for="<?php echo esc_attr($option); ?>"><strong><?php echo esc_html($label); ?></strong></label><br />
      <input type="password" id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" value="" class="large-text" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr($saved ? 'Saved securely — leave blank to keep current key' : 'Enter API key'); ?>" />
      <?php if ($saved): ?><br /><label><input type="checkbox" name="<?php echo esc_attr($option . '_clear'); ?>" value="1" /> Remove saved key</label><?php endif; ?></p>
    <?php endforeach; ?></fieldset><?php
  }

  public static function field_geocoder_daily_limits(): void {
    $limits = self::geocoder_daily_limits();
    $usage = Geocoding_Router::usage_today();
    $labels = ['geocodefarm'=>'GeocodeFarm','geoapify'=>'Geoapify','google'=>'Google','osm'=>'OSM public fallback'];
    ?><fieldset><p class="description">Maximum outgoing requests per UTC day while using Automatic mode. Set a provider to 0 to exclude it from Automatic mode; explicit provider mode ignores this allowance.</p><?php foreach ($labels as $provider => $label): ?>
      <label style="display:inline-block;margin:8px 18px 0 0"><span style="display:block;font-weight:600"><?php echo esc_html($label); ?></span><input type="number" min="0" max="1000000" step="1" name="<?php echo esc_attr(self::OPTION_GEOCODER_DAILY_LIMITS . '[' . $provider . ']'); ?>" value="<?php echo esc_attr((int) ($limits[$provider] ?? 0)); ?>" /><small style="display:block"><?php echo esc_html(sprintf('%d used today', (int) ($usage[$provider] ?? 0))); ?></small></label>
    <?php endforeach; ?></fieldset><?php
  }

  public static function field_geocoder_osm_public(): void {
    ?><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_GEOCODER_OSM_PUBLIC); ?>" value="1" <?php checked(self::geocoder_osm_public_enabled()); ?> /> Permit public Nominatim only for addresses explicitly shown on public service profiles.</label>
    <p class="description">OSM remains unavailable for private customer and mobile-origin addresses regardless of this setting.</p><?php
  }

  public static function calendar_integrations_description(): void {
    ?>
    <p>
      Configure the OAuth applications used by service providers to mirror Koopo appointments into their calendars.
      Koopo remains the source of truth. Selected external events create read-only busy blocks but never change Koopo appointments.
    </p>
    <p><strong>Security:</strong> Client secrets are encrypted before storage and are never displayed again.</p>
    <?php
  }

  public static function field_google_calendar_credentials(): void {
    self::render_calendar_credentials('google');
  }

  public static function field_microsoft_calendar_credentials(): void {
    self::render_calendar_credentials('microsoft');
  }

  private static function render_calendar_credentials(string $provider): void {
    $is_google = $provider === 'google';
    $client_id_option = $is_google ? self::OPTION_GOOGLE_CALENDAR_CLIENT_ID : self::OPTION_MICROSOFT_CALENDAR_CLIENT_ID;
    $secret_option = $is_google ? self::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET : self::OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET;
    $client_id = (string) get_option($client_id_option, '');
    $has_secret = self::calendar_client_secret($provider) !== '';
    $configured = $client_id !== '' && $has_secret;
    $enabled = self::calendar_provider_enabled($provider);
    $callback = rest_url('koopo/v1/appointments/calendar/oauth/' . $provider . '/callback');
    ?>
    <fieldset style="max-width:760px;">
      <?php $enabled_option = $is_google ? self::OPTION_GOOGLE_CALENDAR_ENABLED : self::OPTION_MICROSOFT_CALENDAR_ENABLED; ?>
        <p>
          <label>
            <input type="checkbox" name="<?php echo esc_attr($enabled_option); ?>" value="1" <?php checked($enabled); ?> />
            <strong><?php echo esc_html(sprintf('Enable %s Calendar connections and synchronization', $is_google ? 'Google' : 'Microsoft')); ?></strong>
          </label>
        </p>
        <p class="description">
          Keep this off while provider approval and live acceptance testing are pending. When off, Koopo preserves the implementation and saved data but blocks new connections, provider API calls, appointment mirroring, and provider-based availability blocking.
        </p>
      <p>
        <span style="display:inline-block;padding:4px 9px;border-radius:999px;background:<?php echo $configured ? '#def9eb' : '#f0edf5'; ?>;color:<?php echo $configured ? '#1e6f5c' : '#655d78'; ?>;font-weight:600;">
          <?php echo esc_html($configured ? 'Configured' : 'Not configured'); ?>
        </span>
      </p>
      <p>
        <label for="<?php echo esc_attr($client_id_option); ?>"><strong>Client ID</strong></label><br />
        <input
          type="text"
          id="<?php echo esc_attr($client_id_option); ?>"
          name="<?php echo esc_attr($client_id_option); ?>"
          value="<?php echo esc_attr($client_id); ?>"
          class="large-text"
          autocomplete="off"
          spellcheck="false"
        />
      </p>
      <p>
        <label for="<?php echo esc_attr($secret_option); ?>"><strong>Client secret</strong></label><br />
        <input
          type="password"
          id="<?php echo esc_attr($secret_option); ?>"
          name="<?php echo esc_attr($secret_option); ?>"
          value=""
          class="large-text"
          placeholder="<?php echo esc_attr($has_secret ? 'Saved securely — leave blank to keep current secret' : 'Enter client secret'); ?>"
          autocomplete="new-password"
          spellcheck="false"
        />
        <?php if ($has_secret): ?>
          <br />
          <label>
            <input type="checkbox" name="<?php echo esc_attr($secret_option . '_clear'); ?>" value="1" />
            Remove saved secret
          </label>
        <?php endif; ?>
      </p>
      <?php if (!$is_google):
        $tenant = self::calendar_tenant();
      ?>
        <p>
          <label for="<?php echo esc_attr(self::OPTION_MICROSOFT_CALENDAR_TENANT); ?>"><strong>Tenant</strong></label><br />
          <input
            type="text"
            id="<?php echo esc_attr(self::OPTION_MICROSOFT_CALENDAR_TENANT); ?>"
            name="<?php echo esc_attr(self::OPTION_MICROSOFT_CALENDAR_TENANT); ?>"
            value="<?php echo esc_attr($tenant); ?>"
            class="regular-text"
            autocomplete="off"
            spellcheck="false"
          />
          <span class="description">Use <code>common</code> for personal and organizational Microsoft accounts, or enter a tenant ID.</span>
        </p>
      <?php endif; ?>
      <p>
        <label><strong>Authorized redirect URI</strong></label><br />
        <input type="text" value="<?php echo esc_attr($callback); ?>" class="large-text code" readonly onclick="this.select();" />
        <span class="description">Copy this exact URI into the provider's OAuth application.</span>
      </p>
      <p class="description">Changing an OAuth client ID or secret may require connected providers to reconnect their calendars.</p>
    </fieldset>
    <?php
  }

  public static function sanitize_google_calendar_secret($value): string {
    return self::sanitize_calendar_secret($value, self::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET);
  }

  public static function sanitize_microsoft_calendar_secret($value): string {
    return self::sanitize_calendar_secret($value, self::OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET);
  }

  private static function sanitize_calendar_secret($value, string $option): string {
    if (!empty($_POST[$option . '_clear'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings nonce.
      return '';
    }
    $value = trim((string) wp_unslash($value));
    if ($value === '') return (string) get_option($option, '');
    try {
      return Calendar_Crypto::encrypt(['secret' => $value]);
    } catch (\Throwable $error) {
      add_settings_error($option, 'koopo_appt_calendar_secret_error', 'The calendar secret could not be encrypted. The previous value was preserved.');
      return (string) get_option($option, '');
    }
  }

  public static function sanitize_microsoft_calendar_tenant($value): string {
    $value = trim(sanitize_text_field((string) $value));
    if ($value === '') return 'common';
    if (!preg_match('/^(common|organizations|consumers|[a-zA-Z0-9.-]{3,191})$/', $value)) {
      add_settings_error(self::OPTION_MICROSOFT_CALENDAR_TENANT, 'koopo_appt_invalid_microsoft_tenant', 'Enter common, organizations, consumers, or a valid Microsoft tenant ID/domain.');
      return self::calendar_tenant();
    }
    return $value;
  }

  public static function calendar_client_id(string $provider): string {
    $option = $provider === 'google' ? self::OPTION_GOOGLE_CALENDAR_CLIENT_ID : self::OPTION_MICROSOFT_CALENDAR_CLIENT_ID;
    return trim((string) get_option($option, ''));
  }

  /** A fail-closed release control for OAuth providers awaiting public approval. */
  public static function calendar_provider_enabled(string $provider): bool {
    $provider = sanitize_key($provider);
    if ($provider === 'google') {
      return (bool) get_option(self::OPTION_GOOGLE_CALENDAR_ENABLED, 0);
    }
    if ($provider === 'microsoft') {
      return (bool) get_option(self::OPTION_MICROSOFT_CALENDAR_ENABLED, 0);
    }
    return false;
  }

  public static function calendar_client_secret(string $provider): string {
    $option = $provider === 'google' ? self::OPTION_GOOGLE_CALENDAR_CLIENT_SECRET : self::OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET;
    $stored = (string) get_option($option, '');
    if ($stored !== '') {
      try {
        $decoded = Calendar_Crypto::decrypt($stored);
        if (!empty($decoded['secret']) && is_string($decoded['secret'])) return $decoded['secret'];
      } catch (\Throwable $error) {
        return '';
      }
    }
    return '';
  }

  public static function calendar_tenant(): string {
    $stored = trim((string) get_option(self::OPTION_MICROSOFT_CALENDAR_TENANT, ''));
    if ($stored !== '') return $stored;
    return 'common';
  }

  public static function sanitize_geocoder_mode($value): string {
    $value = Geocoding_Router::canonical_provider((string) $value);
    return in_array($value, array_merge(['auto', 'disabled'], Geocoding_Router::PROVIDERS), true) ? $value : 'auto';
  }

  public static function sanitize_geocoder_auto_order($value): array {
    if (is_string($value)) $value = preg_split('/\s*,\s*/', trim($value));
    $clean = [];
    foreach ((array) $value as $provider) {
      $provider = Geocoding_Router::canonical_provider((string) $provider);
      if (in_array($provider, Geocoding_Router::PROVIDERS, true) && !in_array($provider, $clean, true)) $clean[] = $provider;
    }
    return $clean ?: ['geocodefarm', 'geoapify', 'google', 'osm'];
  }

  public static function sanitize_geocoder_daily_limits($value): array {
    $defaults = self::default_geocoder_daily_limits();
    $value = is_array($value) ? $value : [];
    foreach ($defaults as $provider => $default) {
      $defaults[$provider] = min(1000000, max(0, absint($value[$provider] ?? $default)));
    }
    return $defaults;
  }

  public static function default_geocoder_daily_limits(): array {
    return ['geocodefarm'=>250, 'geoapify'=>3000, 'google'=>0, 'osm'=>100];
  }

  public static function geocoder_mode(): string {
    return self::sanitize_geocoder_mode((string) get_option(self::OPTION_GEOCODER_MODE, 'auto'));
  }

  public static function geocoder_auto_order(): array {
    return self::sanitize_geocoder_auto_order(get_option(self::OPTION_GEOCODER_AUTO_ORDER, ['geocodefarm', 'geoapify', 'google', 'osm']));
  }

  public static function geocoder_daily_limits(): array {
    return self::sanitize_geocoder_daily_limits(get_option(self::OPTION_GEOCODER_DAILY_LIMITS, self::default_geocoder_daily_limits()));
  }

  public static function geocoder_daily_limit(string $provider): int {
    $limits = self::geocoder_daily_limits();
    return (int) ($limits[Geocoding_Router::canonical_provider($provider)] ?? 0);
  }

  public static function geocoder_osm_public_enabled(): bool {
    return '1' === (string) get_option(self::OPTION_GEOCODER_OSM_PUBLIC, '0');
  }

  public static function sanitize_geocoder_key($value, string $option): string {
    if (!empty($_POST[$option . '_clear'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php validates the settings nonce.
      return '';
    }
    $value = trim((string) wp_unslash($value));
    if ($value === '') return (string) get_option($option, '');
    try {
      return Calendar_Crypto::encrypt(['secret' => $value]);
    } catch (\Throwable $error) {
      add_settings_error($option, 'koopo_appt_geocoder_key_error', 'The geocoding API key could not be encrypted. The previous value was preserved.');
      return (string) get_option($option, '');
    }
  }

  public static function geocoder_api_key(string $provider): string {
    $options = [
      'geocodefarm' => self::OPTION_GEOCODEFARM_KEY,
      'geoapify' => self::OPTION_GEOAPIFY_KEY,
      'google' => self::OPTION_GOOGLE_GEOCODING_KEY,
    ];
    $option = $options[Geocoding_Router::canonical_provider($provider)] ?? '';
    if ($option === '') return '';
    $stored = (string) get_option($option, '');
    if ($stored === '') return '';
    try {
      $decoded = Calendar_Crypto::decrypt($stored);
      return !empty($decoded['secret']) && is_string($decoded['secret']) ? $decoded['secret'] : '';
    } catch (\Throwable $error) {
      return '';
    }
  }

  public static function sanitize_hold_minutes($value) {
    $v = absint($value);
    if ($v < 1) $v = 10;
    if ($v > 120) $v = 120; // safety cap
    return $v;
  }

  public static function sanitize_onboarding_pack_ids($value): array {
    $requested = is_array($value) ? array_values(array_unique(array_filter(array_map('absint', $value)))) : [];
    if (!$requested || !class_exists('WooCommerce')) return [];
    $valid = [];
    foreach ($requested as $pack_id) {
      $product = function_exists('wc_get_product') ? wc_get_product($pack_id) : null;
      if (!$product || 'publish' !== get_post_status($pack_id) || 'product_pack' !== $product->get_type()) continue;
      if ('yes' === get_post_meta($pack_id, '_exclusive_for_admin_only', true)) continue;
      $valid[] = $pack_id;
    }
    return $valid;
  }

  public static function provider_onboarding_description(): void {
    echo '<p>' . esc_html__('Choose the Dokan subscription packs shown during provider registration. Koopo prepares the selected pack in the cart; WooCommerce and the configured payment gateway remain responsible for checkout and payment verification.', 'koopo-appointments') . '</p>';
  }

  public static function field_onboarding_pack_ids(): void {
    $selected = array_map('absint', (array) get_option(self::OPTION_ONBOARDING_PACK_IDS, []));
    $packs = class_exists(Provider_Onboarding::class) ? Provider_Onboarding::subscription_packs(true) : [];
    if (!$packs) {
      echo '<p class="description">' . esc_html__('No published, customer-facing Dokan subscription packs were found. Create a Vendor Subscription product first.', 'koopo-appointments') . '</p>';
      return;
    }
    echo '<fieldset>';
    foreach ($packs as $pack) {
      printf(
        '<label style="display:block;margin:0 0 12px"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s> <strong>%4$s</strong> <span style="color:#646970">%5$s</span></label>',
        esc_attr(self::OPTION_ONBOARDING_PACK_IDS),
        (int) $pack['id'],
        checked(in_array((int) $pack['id'], $selected, true), true, false),
        esc_html($pack['name']),
        wp_kses_post($pack['price_html'])
      );
    }
    echo '<p class="description">' . esc_html__('This list refreshes automatically from current published, customer-facing Dokan subscription products. New packs appear here unchecked. Only checked packs are accepted by the onboarding API; leaving all packs unchecked hides the subscription step and prevents onboarding cart preparation.', 'koopo-appointments') . '</p></fieldset>';
  }

  public static function sanitize_refund_policy_rules($value) {
    $rules = [];
    if (is_array($value)) {
      foreach ($value as $rule) {
        if (!is_array($rule)) continue;
        $hours_before = isset($rule['hours_before']) ? max(0, (int) $rule['hours_before']) : null;
        $refund_percent = isset($rule['refund_percent']) ? min(100, max(0, (int) $rule['refund_percent'])) : null;
        if ($hours_before === null || $refund_percent === null) continue;
        $fee_percent = 100 - $refund_percent;
        $reason = self::refund_reason($hours_before, $fee_percent);
        $rules[] = [
          'hours_before' => $hours_before,
          'fee_percent' => $fee_percent,
          'reason' => $reason,
        ];
      }
    }

    if (empty($rules)) {
      $rules = self::standard_refund_policy();
    }

    usort($rules, function($a, $b) {
      return (int) $b['hours_before'] <=> (int) $a['hours_before'];
    });

    return $rules;
  }

  private static function refund_reason(int $hours_before, int $fee_percent): string {
    $refund_percent = 100 - $fee_percent;
    if ($refund_percent <= 0) {
      return $hours_before > 0
        ? sprintf('No refund (less than %d hours notice)', $hours_before)
        : 'No refund (after appointment time)';
    }
    if ($refund_percent >= 100) {
      return sprintf('Full refund (%d+ hours notice)', $hours_before);
    }
    return sprintf('%d%% refund (%d+ hours notice)', $refund_percent, $hours_before);
  }

  public static function standard_refund_policy(): array {
    return [
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
  }

  public static function field_email_logo(): void {
    wp_enqueue_media();

    $selected_id = absint(get_option(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, 0));
    $active_id = absint(get_option(self::OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID, 0));
    $preview_url = $selected_id ? wp_get_attachment_image_url($selected_id, 'medium') : '';
    $active_url = self::get_active_email_logo_url();
    $status = $selected_id && $selected_id === $active_id && $active_url !== ''
      ? 'Ready on Bunny'
      : ($selected_id ? 'Waiting for Bunny offload verification' : 'No logo selected');
    ?>
    <div id="koopo-email-logo-setting">
      <input
        type="hidden"
        id="koopo-email-logo-attachment-id"
        name="<?php echo esc_attr(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID); ?>"
        value="<?php echo esc_attr($selected_id); ?>"
      />
      <div id="koopo-email-logo-preview" style="margin-bottom:10px;">
        <?php if ($preview_url): ?>
          <img src="<?php echo esc_url($preview_url); ?>" alt="Email logo preview" style="display:block;max-width:290px;max-height:140px;width:auto;height:auto;" />
        <?php endif; ?>
      </div>
      <button type="button" class="button" id="koopo-email-logo-select">
        <?php echo $selected_id ? esc_html__('Replace logo', 'koopo-appointments') : esc_html__('Select logo', 'koopo-appointments'); ?>
      </button>
      <button type="button" class="button" id="koopo-email-logo-remove" <?php disabled(!$selected_id); ?>>
        <?php esc_html_e('Remove logo', 'koopo-appointments'); ?>
      </button>
      <p class="description">
        <?php esc_html_e('Choose an image from the Media Library. Emails use it only after Bunny offload is verified; the local uploads URL is never used in outgoing email.', 'koopo-appointments'); ?>
      </p>
      <p class="description">
        <strong><?php esc_html_e('Status:', 'koopo-appointments'); ?></strong>
        <span id="koopo-email-logo-status"><?php echo esc_html($status); ?></span>
      </p>
    </div>
    <script>
      jQuery(function($) {
        let frame;
        const $id = $('#koopo-email-logo-attachment-id');
        const $preview = $('#koopo-email-logo-preview');
        const $select = $('#koopo-email-logo-select');
        const $remove = $('#koopo-email-logo-remove');
        const $status = $('#koopo-email-logo-status');

        $select.on('click', function(event) {
          event.preventDefault();
          if (frame) {
            frame.open();
            return;
          }

          frame = wp.media({
            title: '<?php echo esc_js(__('Choose email logo', 'koopo-appointments')); ?>',
            button: { text: '<?php echo esc_js(__('Use this logo', 'koopo-appointments')); ?>' },
            library: { type: 'image' },
            multiple: false
          });

          frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = attachment.sizes && attachment.sizes.medium
              ? attachment.sizes.medium.url
              : attachment.url;
            $id.val(attachment.id);
            $preview.html($('<img>', {
              src: previewUrl,
              alt: '<?php echo esc_js(__('Email logo preview', 'koopo-appointments')); ?>'
            }).css({
              display: 'block',
              maxWidth: '290px',
              maxHeight: '140px',
              width: 'auto',
              height: 'auto'
            }));
            $select.text('<?php echo esc_js(__('Replace logo', 'koopo-appointments')); ?>');
            $remove.prop('disabled', false);
            $status.text('<?php echo esc_js(__('Save settings to begin or verify Bunny offload', 'koopo-appointments')); ?>');
          });

          frame.open();
        });

        $remove.on('click', function(event) {
          event.preventDefault();
          $id.val('0');
          $preview.empty();
          $select.text('<?php echo esc_js(__('Select logo', 'koopo-appointments')); ?>');
          $remove.prop('disabled', true);
          $status.text('<?php echo esc_js(__('Logo will be removed when settings are saved', 'koopo-appointments')); ?>');
        });
      });
    </script>
    <?php
  }

  public static function sanitize_email_logo_attachment_id($value): int {
    $attachment_id = absint($value);
    if (!$attachment_id) {
      return 0;
    }

    if (get_post_type($attachment_id) !== 'attachment' || strpos((string) get_post_mime_type($attachment_id), 'image/') !== 0) {
      add_settings_error(
        self::OPTION_EMAIL_LOGO_ATTACHMENT_ID,
        'koopo_appt_invalid_email_logo',
        __('Please choose a valid image from the Media Library.', 'koopo-appointments')
      );
      return absint(get_option(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, 0));
    }

    return $attachment_id;
  }

  public static function maybe_migrate_email_logo_setting(): void {
    if (absint(get_option(self::OPTION_EMAIL_LOGO_SCHEMA_VERSION, 0)) >= 1) {
      return;
    }

    if (get_option(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, null) === null) {
      $legacy_url = esc_url_raw((string) get_option(self::OPTION_EMAIL_LOGO, ''));
      $attachment_id = $legacy_url !== '' ? attachment_url_to_postid($legacy_url) : 0;
      if ($attachment_id && strpos((string) get_post_mime_type($attachment_id), 'image/') === 0) {
        update_option(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, $attachment_id, false);
      }
    }

    update_option(self::OPTION_EMAIL_LOGO_SCHEMA_VERSION, 1, false);
  }

  public static function handle_email_logo_selection($old_value, $new_value): void {
    $attachment_id = absint($new_value);
    if (!$attachment_id) {
      update_option(self::OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID, 0, false);
      return;
    }

    if (self::is_remote_email_logo_ready($attachment_id)) {
      update_option(self::OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID, $attachment_id, false);
      return;
    }

    do_action('koopo_bbmu_offload_attachment', $attachment_id, [
      'source' => 'koopo_appointments_email_logo',
      'force' => true,
    ]);
  }

  public static function handle_email_logo_added($option, $value): void {
    unset($option);
    self::handle_email_logo_selection(null, $value);
  }

  public static function handle_email_logo_offloaded($attachment_id, $result = []): void {
    unset($result);
    $attachment_id = absint($attachment_id);
    $selected_id = absint(get_option(self::OPTION_EMAIL_LOGO_ATTACHMENT_ID, 0));
    if ($attachment_id && $attachment_id === $selected_id && self::is_remote_email_logo_ready($attachment_id)) {
      update_option(self::OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID, $attachment_id, false);
    }
  }

  public static function get_active_email_logo_url(): string {
    $attachment_id = absint(get_option(self::OPTION_EMAIL_LOGO_ACTIVE_ATTACHMENT_ID, 0));
    if (!$attachment_id || !self::is_remote_email_logo_ready($attachment_id)) {
      return '';
    }

    return esc_url((string) wp_get_attachment_image_url($attachment_id, 'full'));
  }

  private static function is_remote_email_logo_ready(int $attachment_id): bool {
    $url = (string) wp_get_attachment_image_url($attachment_id, 'full');
    if ($url === '') {
      return false;
    }

    $uploads = wp_get_upload_dir();
    $local_base = isset($uploads['baseurl']) ? untrailingslashit((string) $uploads['baseurl']) : '';
    if ($local_base !== '' && strpos($url, $local_base . '/') === 0) {
      return false;
    }

    $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    return $url_host !== '' && $url_host !== $site_host;
  }

  public static function get_default_refund_policy(): array {
    $rules = get_option(self::OPTION_REFUND_POLICY_DEFAULT, []);
    if (is_array($rules) && !empty($rules)) {
      return $rules;
    }
    return self::standard_refund_policy();
  }

  public static function field_hold_minutes() {
    $val = absint(get_option(self::OPTION_HOLD_MINUTES, 10));
    if ($val < 1) $val = 10;
    ?>
    <input
      type="number"
      min="1"
      max="120"
      step="1"
      name="<?php echo esc_attr(self::OPTION_HOLD_MINUTES); ?>"
      value="<?php echo esc_attr($val); ?>"
      class="small-text"
    />
    <p class="description">
      How long a selected appointment time is reserved while the customer completes checkout.
      Default is <strong>10</strong> minutes.
    </p>
    <?php
  }

  public static function field_refund_policy_default() {
    $rules = get_option(self::OPTION_REFUND_POLICY_DEFAULT, []);
    if (!is_array($rules) || empty($rules)) {
      $rules = self::standard_refund_policy();
    }
    ?>
    <div class="koopo-refund-policy-default">
      <p class="description">Set the default refund policy used when vendors do not specify a custom policy.</p>
      <table class="widefat" style="max-width:680px;">
        <thead>
          <tr>
            <th>Hours Before</th>
            <th>Refund %</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="koopo-refund-policy-rows">
          <?php foreach ($rules as $idx => $rule):
            $hours = (int) ($rule['hours_before'] ?? 0);
            $fee = (int) ($rule['fee_percent'] ?? 0);
            $refund = max(0, min(100, 100 - $fee));
          ?>
          <tr>
            <td>
              <input type="number" min="0" step="1"
                name="<?php echo esc_attr(self::OPTION_REFUND_POLICY_DEFAULT); ?>[<?php echo esc_attr($idx); ?>][hours_before]"
                value="<?php echo esc_attr($hours); ?>" />
            </td>
            <td>
              <input type="number" min="0" max="100" step="1"
                name="<?php echo esc_attr(self::OPTION_REFUND_POLICY_DEFAULT); ?>[<?php echo esc_attr($idx); ?>][refund_percent]"
                value="<?php echo esc_attr($refund); ?>" />
            </td>
            <td><button type="button" class="button koopo-refund-policy-remove">Remove</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p><button type="button" class="button koopo-refund-policy-add">Add rule</button></p>
    </div>
    <script>
      (function(){
        const rows = document.getElementById('koopo-refund-policy-rows');
        const addBtn = document.querySelector('.koopo-refund-policy-add');
        if (!rows || !addBtn) return;
        function nextIndex() {
          return rows.children.length;
        }
        addBtn.addEventListener('click', function(){
          const idx = nextIndex();
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td><input type="number" min="0" step="1" name="<?php echo esc_js(self::OPTION_REFUND_POLICY_DEFAULT); ?>[${idx}][hours_before]" value="0" /></td>
            <td><input type="number" min="0" max="100" step="1" name="<?php echo esc_js(self::OPTION_REFUND_POLICY_DEFAULT); ?>[${idx}][refund_percent]" value="0" /></td>
            <td><button type="button" class="button koopo-refund-policy-remove">Remove</button></td>
          `;
          rows.appendChild(tr);
        });
        rows.addEventListener('click', function(e){
          if (e.target && e.target.classList.contains('koopo-refund-policy-remove')) {
            const tr = e.target.closest('tr');
            if (tr) tr.remove();
          }
        });
      })();
    </script>
    <?php
  }

  public static function render_page() {
    if (!current_user_can('manage_options')) return;
    global $wp_settings_sections;
    $sections = (array) ($wp_settings_sections['koopo-appointments-settings'] ?? []);
    ?>
    <div class="wrap koopo-admin-settings">
      <header class="koopo-admin-settings__masthead">
        <div class="koopo-admin-settings__identity"><span class="koopo-admin-settings__mark" aria-hidden="true">K</span><div><span><?php esc_html_e('Koopo operations', 'koopo-appointments'); ?></span><h1><?php esc_html_e('Appointments control room', 'koopo-appointments'); ?></h1><p><?php esc_html_e('Release features deliberately, connect providers, and manage how booking operations behave.', 'koopo-appointments'); ?></p></div></div>
        <div class="koopo-admin-settings__health" aria-label="Feature release status">
          <span class="<?php echo Features::waitlist_enabled() ? 'is-live' : 'is-held'; ?>"><i></i><?php echo esc_html(Features::waitlist_enabled() ? __('Waitlist live', 'koopo-appointments') : __('Waitlist held', 'koopo-appointments')); ?></span>
          <span class="<?php echo Features::client_forms_enabled() ? 'is-live' : 'is-held'; ?>"><i></i><?php echo esc_html(Features::client_forms_enabled() ? __('Client forms live', 'koopo-appointments') : __('Client forms held', 'koopo-appointments')); ?></span>
        </div>
      </header>
      <?php settings_errors(); ?>
      <form method="post" action="options.php">
        <?php settings_fields('koopo_appt_settings'); ?>
        <div class="koopo-admin-settings__layout">
          <nav class="koopo-admin-settings__nav" aria-label="<?php esc_attr_e('Settings sections', 'koopo-appointments'); ?>">
            <strong><?php esc_html_e('Settings', 'koopo-appointments'); ?></strong>
            <?php foreach ($sections as $section): ?><a href="#<?php echo esc_attr($section['id']); ?>" data-koopo-settings-nav><?php echo esc_html($section['title']); ?></a><?php endforeach; ?>
          </nav>
          <main class="koopo-admin-settings__content">
            <?php $position = 0; foreach ($sections as $section): $position++; ?>
              <section class="koopo-settings-section" id="<?php echo esc_attr($section['id']); ?>" style="--koopo-section-index:<?php echo esc_attr($position - 1); ?>">
                <header><span><?php echo esc_html(sprintf('%02d', $position)); ?></span><h2><?php echo esc_html($section['title']); ?></h2></header>
                <div class="koopo-settings-section__description"><?php if (!empty($section['callback']) && is_callable($section['callback'])) call_user_func($section['callback'], $section); ?></div>
                <table class="form-table" role="presentation"><tbody><?php do_settings_fields('koopo-appointments-settings', $section['id']); ?></tbody></table>
              </section>
            <?php endforeach; ?>
          </main>
        </div>
        <div class="koopo-admin-settings__save"><span data-koopo-save-state><?php esc_html_e('Settings are up to date', 'koopo-appointments'); ?></span><?php submit_button(__('Save appointment settings', 'koopo-appointments'), 'primary', 'submit', false); ?></div>
      </form>
    </div>
    <?php
  }
}
