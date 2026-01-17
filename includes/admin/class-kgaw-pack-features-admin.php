<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Pack_Features_Admin {

  public static function init() {
    // Only in WP admin
    if (!is_admin()) return;

    add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_fields']);
    add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
  }

  private static function is_dokan_pack_product($product): bool {
    // Dokan subscription packs are Woo products with special type in most installs.
    // We'll keep this flexible: show panel only if admin toggles it OR product type matches.
    $type = method_exists($product, 'get_type') ? $product->get_type() : '';
    if (stripos($type, 'dokan') !== false) return true;

    // Fallback: allow manually enabling for any product
    $enabled = $product->get_meta('_koopo_pack_features_enabled', true);
    return (bool) $enabled;
  }

  public static function render_fields() {
    global $product_object;
    if (!$product_object) return;

    // Only show if Dokan subscriptions module exists (but admin still sees product fields even if module off)
    // We'll still allow it to be set even if module is off.
    if (!self::is_dokan_pack_product($product_object)) return;

    echo '<div class="options_group">';

    woocommerce_wp_checkbox([
      'id' => '_koopo_pack_features_enabled',
      'label' => __('Enable Koopo Tier Features', 'koopo'),
      'description' => __('Attach Koopo feature flags to this Dokan subscription pack.', 'koopo'),
      'value' => $product_object->get_meta('_koopo_pack_features_enabled', true) ? 'yes' : 'no',
    ]);

    echo '<p><strong>' . esc_html__('Koopo Features (Tier Flags)', 'koopo') . '</strong></p>';

    $features = $product_object->get_meta('_koopo_features', true);
    if (is_string($features)) {
      $decoded = json_decode($features, true);
      if (is_array($decoded)) $features = $decoded;
    }
    if (!is_array($features)) $features = [];

    self::checkbox('_koopo_features[booking_calendar]', 'Booking Calendar', !empty($features['booking_calendar']));
    self::checkbox('_koopo_features[memberships]', 'Appointment Memberships', !empty($features['memberships']));
    self::checkbox('_koopo_features[event_tickets]', 'Event Tickets', !empty($features['event_tickets']));
    self::checkbox('_koopo_features[print_on_demand]', 'Print on Demand', !empty($features['print_on_demand']));

    echo '</div>';
  }

  private static function checkbox($name, $label, $checked) {
    echo '<p class="form-field">';
    echo '<label>' . esc_html($label) . '</label> ';
    echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . ' />';
    echo '</p>';
  }

  public static function save_fields($product) {
    // Admin only; safe.
    $enabled = isset($_POST['_koopo_pack_features_enabled']) ? 'yes' : 'no';
    $product->update_meta_data('_koopo_pack_features_enabled', $enabled);

    $posted = isset($_POST['_koopo_features']) && is_array($_POST['_koopo_features']) ? $_POST['_koopo_features'] : [];
    $features = [
      'booking_calendar' => !empty($posted['booking_calendar']),
      'memberships'      => !empty($posted['memberships']),
      'event_tickets'    => !empty($posted['event_tickets']),
      'print_on_demand'  => !empty($posted['print_on_demand']),
    ];

    $product->update_meta_data('_koopo_features', wp_json_encode($features));
  }
}
