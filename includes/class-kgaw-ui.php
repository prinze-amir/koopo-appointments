<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class UI {

  public static function init() {
    add_shortcode('koopo_appointments', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
  }

  /**
   * Load assets only where needed:
   * - GeoDirectory single listing pages for gd_place (and optionally gd_event later)
   */
  public static function enqueue_assets() {
    if (!self::should_load_assets()) return;

    $ver = defined('KOOPO_VERSION') ? KOOPO_VERSION : time(); // or plugin version constant

    wp_register_style(
      'koopo-appointments-ui',
      plugins_url('../assets/appointments.css', __FILE__),
      [],
      $ver
    );

    wp_register_script(
      'koopo-appointments-ui',
      plugins_url('../assets/appointments.js', __FILE__),
      ['jquery'],
      $ver,
      true
    );

    wp_enqueue_style('koopo-appointments-ui');
    wp_enqueue_script('koopo-appointments-ui');

    $localize = [
      'restUrl' => esc_url_raw(rest_url('koopo/v1')),
      'nonce'   => wp_create_nonce('wp_rest'),
      'userId'  => get_current_user_id(),
      'listingId' => (int) get_the_ID(),
      'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
      'checkoutSuccess' => self::is_order_received_page(),
      'loginUrl' => wp_login_url(),
      'holdMinutes' => (int) apply_filters('koopo_appt_pending_expire_minutes', 10),
    ];

    wp_localize_script('koopo-appointments-ui', 'KOOPO_APPT', $localize);
  }

  private static function is_order_received_page(): bool {
    return function_exists('is_order_received_page') && is_order_received_page();
  }

  private static function should_load_assets(): bool {
    // Only on single GD listing pages
    if (!is_singular()) return false;

    $post_type = get_post_type(get_the_ID());
    if (!in_array($post_type, ['gd_place'], true)) return false;

    // Optional: only load if shortcode exists on the page
    // but for listing templates, shortcodes might be injected by Elementor/blocks.
    return true;
  }

  public static function shortcode($atts = []) {
    if (!is_singular()) return '';
    
    $hide_if_empty = !empty($atts['hide_if_empty']) && $atts['hide_if_empty'] !== '0';

    $listing_id = (int) get_the_ID();
    $post_type  = get_post_type($listing_id);

    if ($hide_if_empty) {

      $enabled = get_post_meta($listing_id, '_koopo_appt_enabled', true) === '1';
      if (!$enabled) return '';

      $vendor_id = (int) get_post_field('post_author', $listing_id);

      $has = new \WP_Query([
        'post_type' => Services_CPT::POST_TYPE,
        'post_status' => 'publish',
        'author' => $vendor_id,
        'posts_per_page' => 1,
        'meta_query' => [
          ['key' => '_koopo_listing_id', 'value' => $listing_id, 'compare' => '='],
        ],
        'fields' => 'ids',
      ]);
      if (empty($has->posts)) return '';
    }


    $post_type  = get_post_type($listing_id);

    if (!in_array($post_type, ['gd_place'], true)) return '';

    $atts = shortcode_atts([
      'button_text' => 'Book Now',
    ], $atts, 'koopo_appointments');

    ob_start();
    ?>
    <div class="koopo-appt" data-listing-id="<?php echo esc_attr($listing_id); ?>">
      <button type="button" class="koopo-appt__open">
        <?php echo esc_html($atts['button_text']); ?>
      </button>

      <!-- Modal Overlay -->
      <div class="koopo-appt__overlay" aria-hidden="true">
        <div class="koopo-appt__modal" role="dialog" aria-modal="true" aria-label="Book appointment">
          <button type="button" class="koopo-appt__close" aria-label="Close">&times;</button>

          <div class="koopo-appt__header">
            <h3 class="koopo-appt__title">Book an Appointment</h3>
            <div class="koopo-appt__subtitle">Select a service and time</div>
          </div>

          <div class="koopo-appt__body">
            <div class="koopo-appt__notice koopo-appt__notice--hidden"></div>

            <label class="koopo-appt__label">
              Service
              <select class="koopo-appt__field koopo-appt__service">
                <option value="">Loading services…</option>
              </select>
            </label>
          <div class="koopo-appt__datebar">
            <button type="button" class="koopo-appt__nav koopo-appt__prev" aria-label="Previous week">&lsaquo;</button>
            <div class="koopo-appt__week"></div>
            <button type="button" class="koopo-appt__nav koopo-appt__next" aria-label="Next week">&rsaquo;</button>
          </div>

          <input type="hidden" class="koopo-appt__date" />

          <div class="koopo-appt__label">
            Available Times
            <div class="koopo-appt__slots koopo-appt__slots--grouped">
              <div class="koopo-appt__slots-empty">Select a service to view availability.</div>
            </div>
          </div>

          <input type="hidden" class="koopo-appt__slot-start" />
          <input type="hidden" class="koopo-appt__slot-end" />


            <div class="koopo-appt__summary">
              <div><strong>Price:</strong> <span class="koopo-appt__price">—</span></div>
              <div><strong>Duration:</strong> <span class="koopo-appt__duration">—</span></div>
            </div>

            <button type="button" class="koopo-appt__submit" disabled>
              Continue to Checkout
            </button>

            

            <div class="koopo-appt__hold-note">
              <?php
                $mins = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
                echo esc_html(sprintf('Your selected time will be held for %d minutes while you complete checkout.', max(1, $mins)));
              ?>
            </div>

<div class="koopo-appt__loading koopo-appt__loading--hidden">
              Processing…
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}
