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
    if ('product_pack' === $type || stripos($type, 'dokan') !== false) return true;

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

    self::checkbox('_koopo_features[appointments]', 'Appointments', !empty($features['appointments']));
    $profile_limit = $features['service_profiles'] ?? 1;
    $unlimited_profiles = 'unlimited' === $profile_limit || -1 === (int) $profile_limit;
    woocommerce_wp_text_input([
      'id' => '_koopo_service_profile_limit',
      'label' => __('Service profile limit', 'koopo-appointments'),
      'description' => __('Maximum service profiles included with this pack. Use 0 when the pack supports appointments only for business listings.', 'koopo-appointments'),
      'type' => 'number',
      'value' => $unlimited_profiles ? 1 : min(1000, max(0, (int) $profile_limit)),
      'custom_attributes' => ['min'=>'0', 'max'=>'1000', 'step'=>'1'],
    ]);
    woocommerce_wp_checkbox([
      'id' => '_koopo_service_profiles_unlimited',
      'label' => __('Unlimited service profiles', 'koopo-appointments'),
      'description' => __('Ignore the numeric limit for vendors on this pack.', 'koopo-appointments'),
      'value' => $unlimited_profiles ? 'yes' : 'no',
    ]);
    self::checkbox('_koopo_features[event_tickets]', 'Event Tickets', !empty($features['event_tickets']));

    if (function_exists('wc_enqueue_js')) {
      wc_enqueue_js("(function(){var a=jQuery('input[name=\"_koopo_features[appointments]\"]'),u=jQuery('#_koopo_service_profiles_unlimited'),l=jQuery('#_koopo_service_profile_limit');function sync(){var enabled=a.is(':checked'),unlimited=u.is(':checked');u.prop('disabled',!enabled);l.prop('disabled',!enabled||unlimited);l.closest('.form-field').toggleClass('koopo-field-disabled',!enabled||unlimited);}a.add(u).on('change',sync);sync();})();");
    }

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
    $existing = $product->get_meta('_koopo_features', true);
    if (is_string($existing)) $existing = json_decode($existing, true);
    $features = is_array($existing) ? $existing : [];
    $profile_limit = isset($_POST['_koopo_service_profile_limit']) ? min(1000, max(0, absint(wp_unslash($_POST['_koopo_service_profile_limit'])))) : 1;
    $features = array_merge($features, [
      'appointments'     => !empty($posted['appointments']),
      'service_profiles' => isset($_POST['_koopo_service_profiles_unlimited']) ? 'unlimited' : $profile_limit,
      'event_tickets'    => !empty($posted['event_tickets']),
    ]);

    $product->update_meta_data('_koopo_features', wp_json_encode($features));
  }
}
