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

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);

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
    register_setting('koopo_appt_settings', self::OPTION_EMAIL_LOGO, [
      'type' => 'string',
      'sanitize_callback' => 'esc_url_raw',
      'default' => 'https://koopoonline.com/wp-content/uploads/2024/09/short-block-white-black.png',
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
      self::OPTION_EMAIL_LOGO,
      'Email logo URL',
      [__CLASS__, 'field_email_logo'],
      'koopo-appointments-settings',
      'koopo_appt_general'
    );
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
    $value = esc_url(get_option(self::OPTION_EMAIL_LOGO, ''));
    ?>
    <input type="url" name="<?php echo esc_attr(self::OPTION_EMAIL_LOGO); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="https://example.com/logo.png" />
    <p class="description">Used at the top of Koopo appointment emails.</p>
    <?php
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
