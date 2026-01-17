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

    $ver = defined('KOOPO_APPT_VERSION') ? KOOPO_APPT_VERSION : time();

    wp_register_style(
      'koopo-appointments-ui',
      KOOPO_APPT_URL . 'assets/appointments.css',
      [],
      $ver
    );

    wp_register_script(
      'koopo-appointments-ui',
      KOOPO_APPT_URL . 'assets/appointments.js',
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

    if (!self::is_listing_enabled((int) get_the_ID())) return false;

    // Optional: only load if shortcode exists on the page
    // but for listing templates, shortcodes might be injected by Elementor/blocks.
    return true;
  }

  public static function shortcode($atts = []) {
    if (!is_singular()) return '';
    
    $hide_if_empty = !empty($atts['hide_if_empty']) && $atts['hide_if_empty'] !== '0';
    $require_enabled = !isset($atts['require_enabled']) || $atts['require_enabled'] !== '0';

    $listing_id = (int) get_the_ID();
    $post_type  = get_post_type($listing_id);

    if ($require_enabled && !self::is_listing_enabled($listing_id)) {
      return '';
    }

    if ($hide_if_empty) {

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
            <div class="koopo-appt__steps">
              <div class="koopo-appt__step koopo-appt__step--active" data-step="1">
                <span class="koopo-appt__step-num">1</span>
                <span class="koopo-appt__step-label">Select Service</span>
              </div>
              <div class="koopo-appt__step" data-step="2">
                <span class="koopo-appt__step-num">2</span>
                <span class="koopo-appt__step-label">Date & Time</span>
              </div>
              <div class="koopo-appt__step" data-step="3">
                <span class="koopo-appt__step-num">3</span>
                <span class="koopo-appt__step-label">Your Information</span>
              </div>
            </div>
          </div>

          <div class="koopo-appt__body">
            <div class="koopo-appt__notice koopo-appt__notice--hidden"></div>

            <!-- STEP 1: Service Selection -->
            <div class="koopo-appt__panel koopo-appt__panel--active" data-panel="1">
              <input type="hidden" class="koopo-appt__service" />
              <div class="koopo-appt__services-grid">
                <!-- Services will be loaded here dynamically -->
              </div>
              <div class="koopo-appt__addons koopo-appt__addons--hidden">
                <h4>Optional Add-ons</h4>
                <div class="koopo-appt__addons-options"></div>
                <div class="koopo-appt__addons-selected"></div>
              </div>
              <button type="button" class="koopo-appt__next-step koopo-appt__next-step--service" disabled>
                Continue to Date & Time
              </button>
            </div>

            <!-- STEP 2: Date & Time Selection -->
            <div class="koopo-appt__panel" data-panel="2">
              <div class="calendar-slots flex gap-3">
                <div class="koopo-appt__calendar-section">
                <div class="koopo-appt__calendar-header">
                  <button type="button" class="koopo-appt__month-nav koopo-appt__month-prev" aria-label="Previous month">&lsaquo;</button>
                  <div class="koopo-appt__month-title"></div>
                  <button type="button" class="koopo-appt__month-nav koopo-appt__month-next" aria-label="Next month">&rsaquo;</button>
                </div>
                <div class="koopo-appt__calendar"></div>
              </div>

              <input type="hidden" class="koopo-appt__date" />

              <div class="koopo-appt__label">
                Available Times
                <div class="koopo-appt__slots koopo-appt__slots--grouped">
                  <div class="koopo-appt__slots-empty">Select a service to view availability.</div>
                </div>
              </div>
              </div>
              

              <input type="hidden" class="koopo-appt__slot-start" />
              <input type="hidden" class="koopo-appt__slot-end" />

              <div class="koopo-appt__summary">
                <div><strong>Service:</strong> <span class="koopo-appt__summary-service">—</span></div>
                <div><strong>Add-ons:</strong> <span class="koopo-appt__summary-addons">—</span></div>
                <div><strong>Date & Time:</strong> <span class="koopo-appt__summary-datetime">—</span></div>
                <div><strong>Duration:</strong> <span class="koopo-appt__duration">—</span></div>
                <div><strong>Price:</strong> <span class="koopo-appt__price">—</span></div>
              </div>

              <button type="button" class="koopo-appt__next-step koopo-appt__next-step--schedule" disabled>
                Continue to Your Information
              </button>
            </div>

            <!-- STEP 3: Customer Information -->
            <div class="koopo-appt__panel" data-panel="3">
              <div class="koopo-appt__booking-for">
                <label class="koopo-appt__checkbox-label">
                  <input type="checkbox" class="koopo-appt__booking-for-other" />
                  <span>Booking for someone else</span>
                </label>
              </div>

              <div class="koopo-appt__form-grid">
                <label class="koopo-appt__label">
                  Name *
                  <input type="text" class="koopo-appt__field koopo-appt__customer-name" required />
                </label>

                <label class="koopo-appt__label">
                  Email *
                  <input type="email" class="koopo-appt__field koopo-appt__customer-email" required />
                </label>

                <label class="koopo-appt__label">
                  Phone *
                  <input type="tel" class="koopo-appt__field koopo-appt__customer-phone" required />
                </label>
              </div>

              <label class="koopo-appt__label koopo-appt__label--full">
                Additional Notes (Optional)
                <textarea class="koopo-appt__field koopo-appt__customer-notes" rows="3" placeholder="Any special requests or information we should know..."></textarea>
              </label>

              <div class="koopo-appt__summary koopo-appt__summary--review">
                <h4>Booking Summary</h4>
                <div><strong>Service:</strong> <span class="koopo-appt__summary-service">—</span></div>
                <div><strong>Date & Time:</strong> <span class="koopo-appt__summary-datetime">—</span></div>
                <div><strong>Duration:</strong> <span class="koopo-appt__duration">—</span></div>
                <div><strong>Price:</strong> <span class="koopo-appt__price">—</span></div>
              </div>

              <div class="koopo-appt__actions">
                <button type="button" class="koopo-appt__prev-step">
                  Back to Date & Time
                </button>
                <button type="button" class="koopo-appt__submit" disabled>
                  Continue to Checkout
                </button>
              </div>

              <div class="koopo-appt__hold-note">
                <?php
                  $mins = (int) apply_filters('koopo_appt_pending_expire_minutes', 10);
                  echo esc_html(sprintf('Your selected time will be held for %d minutes while you complete checkout.', max(1, $mins)));
                ?>
              </div>
            </div>

            <div class="koopo-appt__loading koopo-appt__loading--hidden">
              <span class="koopo-appt__loading-spinner" aria-hidden="true"></span>
              <span>Processing booking…</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function is_listing_enabled(int $listing_id): bool {
    if (!$listing_id) return false;
    return get_post_meta($listing_id, '_koopo_appt_enabled', true) === '1';
  }
}
