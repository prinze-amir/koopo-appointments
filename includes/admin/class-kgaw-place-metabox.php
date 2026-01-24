<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Place_Metabox {

  public static function init(): void {
    add_action('add_meta_boxes', [__CLASS__, 'register']);
    add_action('save_post_gd_place', [__CLASS__, 'save']);
  }

  public static function register(): void {
    add_meta_box(
      'koopo-appt-place-settings',
      __('Koopo Appointments', 'koopo-appointments'),
      [__CLASS__, 'render'],
      'gd_place',
      'side',
      'default'
    );
  }

  public static function render(\WP_Post $post): void {
    wp_nonce_field('koopo_appt_place_meta', 'koopo_appt_place_meta_nonce');
    $enabled = get_post_meta($post->ID, '_koopo_appt_enabled', true) === '1';
    ?>
    <label>
      <input type="checkbox" name="koopo_appt_enabled" value="1" <?php checked($enabled); ?> />
      <?php esc_html_e('Enable appointments for this place', 'koopo-appointments'); ?>
    </label>
    <?php
  }

  public static function save(int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['koopo_appt_place_meta_nonce']) || !wp_verify_nonce($_POST['koopo_appt_place_meta_nonce'], 'koopo_appt_place_meta')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $enabled = isset($_POST['koopo_appt_enabled']) ? '1' : '0';
    update_post_meta($post_id, '_koopo_appt_enabled', $enabled);
  }
}
