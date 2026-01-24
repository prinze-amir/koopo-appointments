<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class User_Feature_Overrides {

  public static function init(): void {
    add_action('show_user_profile', [__CLASS__, 'render']);
    add_action('edit_user_profile', [__CLASS__, 'render']);
    add_action('personal_options_update', [__CLASS__, 'save']);
    add_action('edit_user_profile_update', [__CLASS__, 'save']);
  }

  private static function feature_map(): array {
    return apply_filters('koopo_appt_user_feature_override_keys', [
      'appointments' => '_koopo_feature_appointments',
    ]);
  }

  public static function render(\WP_User $user): void {
    if (!current_user_can('edit_users')) return;
    $map = self::feature_map();
    if (empty($map)) return;
    ?>
    <h2><?php esc_html_e('Koopo Feature Overrides', 'koopo-appointments'); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label><?php esc_html_e('Manual Access', 'koopo-appointments'); ?></label></th>
        <td>
          <fieldset>
            <?php foreach ($map as $label => $meta_key): ?>
              <?php
                $checked = get_user_meta($user->ID, $meta_key, true) === '1';
                $pretty = ucwords(str_replace('_', ' ', $label));
              ?>
              <label style="display:block; margin-bottom:6px;">
                <input type="checkbox" name="<?php echo esc_attr($meta_key); ?>" value="1" <?php checked($checked); ?> />
                <?php echo esc_html($pretty); ?>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <p class="description">
            <?php esc_html_e('Grants access even if the user’s vendor pack does not include the feature.', 'koopo-appointments'); ?>
          </p>
        </td>
      </tr>
    </table>
    <?php
  }

  public static function save(int $user_id): void {
    if (!current_user_can('edit_user', $user_id)) return;
    $map = self::feature_map();
    foreach ($map as $meta_key) {
      $value = isset($_POST[$meta_key]) ? '1' : '0';
      update_user_meta($user_id, $meta_key, $value);
    }
  }
}
