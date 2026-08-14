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
  const OPTION_MICROSOFT_CALENDAR_CLIENT_ID = 'koopo_appt_microsoft_calendar_client_id';
  const OPTION_MICROSOFT_CALENDAR_CLIENT_SECRET = 'koopo_appt_microsoft_calendar_client_secret';
  const OPTION_MICROSOFT_CALENDAR_TENANT = 'koopo_appt_microsoft_calendar_tenant';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
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
  }

  public static function calendar_integrations_description(): void {
    ?>
    <p>
      Configure the OAuth applications used by service providers to mirror Koopo appointments into their calendars.
      Koopo remains the source of truth; external calendars never change availability or appointments.
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
    $callback = rest_url('koopo/v1/appointments/calendar/oauth/' . $provider . '/callback');
    ?>
    <fieldset style="max-width:760px;">
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

  public static function sanitize_hold_minutes($value) {
    $v = absint($value);
    if ($v < 1) $v = 10;
    if ($v > 120) $v = 120; // safety cap
    return $v;
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
    ?>
    <div class="wrap">
      <h1>Koopo Appointments</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('koopo_appt_settings');
          do_settings_sections('koopo-appointments-settings');
          submit_button();
        ?>
      </form>
    </div>
    <?php
  }
}
