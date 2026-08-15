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

    $current_id = (int) get_the_ID();
    $post_type = get_post_type($current_id);
    $listing_id = $post_type === 'gd_place' ? $current_id : 0;
    $provider_id = $post_type === Provider_Profiles::POST_TYPE ? $current_id : 0;
    $resource_id = $provider_id ? Resources::ensure_for_provider($provider_id) : ($listing_id ? Resources::ensure_for_listing($listing_id) : 0);
    $localize = [
      'restUrl' => esc_url_raw(rest_url('koopo/v1')),
      'nonce'   => wp_create_nonce('wp_rest'),
      'userId'  => get_current_user_id(),
      'listingId' => $listing_id,
      'providerId' => $provider_id,
      'resourceId' => $resource_id,
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
    if (!in_array($post_type, ['gd_place', Provider_Profiles::POST_TYPE], true)) return false;

    if (!self::is_listing_enabled((int) get_the_ID())) return false;

    // Optional: only load if shortcode exists on the page
    // but for listing templates, shortcodes might be injected by Elementor/blocks.
    return true;
  }

  public static function shortcode($atts = []) {
    return self::render_booking((array) $atts);
  }

  public static function render_booking(array $atts = []): string {
    $provider_id = absint($atts['provider_id'] ?? 0);
    $listing_id = absint($atts['listing_id'] ?? 0);
    if (!$provider_id && !$listing_id) {
      if (!is_singular()) return '';
      $current_id = (int) get_the_ID();
      if (get_post_type($current_id) === Provider_Profiles::POST_TYPE) $provider_id = $current_id;
      if (get_post_type($current_id) === 'gd_place') $listing_id = $current_id;
    }
    $subject_id = $provider_id ?: $listing_id;
    if (!$subject_id) return '';

    $hide_if_empty = !empty($atts['hide_if_empty']) && $atts['hide_if_empty'] !== '0';
    $require_enabled = !isset($atts['require_enabled']) || $atts['require_enabled'] !== '0';

    if ($require_enabled && !self::is_listing_enabled($subject_id)) {
      return '';
    }

    if ($hide_if_empty) {

      $vendor_id = (int) get_post_field('post_author', $subject_id);

      $has = new \WP_Query([
        'post_type' => Services_CPT::POST_TYPE,
        'post_status' => 'publish',
        'author' => $vendor_id,
        'posts_per_page' => 1,
        'meta_query' => [
          ['key' => $provider_id ? Services_API::META_PROVIDER_ID : Services_API::META_LISTING_ID, 'value' => $subject_id, 'compare' => '='],
        ],
        'fields' => 'ids',
      ]);
      if (empty($has->posts)) return '';
    }


    $post_type = get_post_type($subject_id);
    if (!in_array($post_type, ['gd_place', Provider_Profiles::POST_TYPE], true)) return '';
    $resource_id = $provider_id ? Resources::ensure_for_provider($provider_id) : Resources::ensure_for_listing($listing_id);
    if (!$resource_id) return '';
    $service_modes = $provider_id ? (array) get_post_meta($provider_id, Provider_Profiles::META_SERVICE_MODES, true) : ['at_location'];
    if (!$service_modes) $service_modes = ['at_location'];
    $service_area = $provider_id && class_exists(Service_Areas::class) ? Service_Areas::public_area($provider_id) : null;

    if (!is_user_logged_in()) {
      $login_url = wp_login_url(get_permalink($subject_id));
      $register_url = wp_registration_url();
      return sprintf(
        '<div class="koopo-appt koopo-appt--login-required"><p>%s</p></div>',
        wp_kses_post(
          sprintf(
            'Please <a href="%s">log in</a> or <a href="%s">sign up</a> to book.',
            esc_url($login_url),
            esc_url($register_url)
          )
        )
      );
    }
    $booking_user = wp_get_current_user();
    $booking_phone = (string) get_user_meta((int) $booking_user->ID, 'billing_phone', true);

    $atts = shortcode_atts([
      'button_text' => 'Book Now',
    ], $atts, 'koopo_appointments');

    ob_start();
    ?>
    <div class="koopo-appt" data-listing-id="<?php echo esc_attr($listing_id); ?>" data-provider-id="<?php echo esc_attr($provider_id); ?>" data-resource-id="<?php echo esc_attr($resource_id); ?>" data-service-modes="<?php echo esc_attr(wp_json_encode(array_values($service_modes))); ?>" data-service-area-label="<?php echo esc_attr((string) ($service_area['public_label'] ?? '')); ?>">
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
              <fieldset class="koopo-appt__delivery">
                <legend>How should this appointment happen?</legend>
                <div class="koopo-appt__delivery-options"></div>
                <div class="koopo-appt__mobile-address" hidden>
                  <p class="koopo-appt__delivery-note"></p>
                  <div class="koopo-appt__form-grid">
                    <label class="koopo-appt__label koopo-appt__label--full">Service address *<input class="koopo-appt__field koopo-appt__service-address-1" autocomplete="street-address" /></label>
                    <label class="koopo-appt__label">Apt, suite, or unit<input class="koopo-appt__field koopo-appt__service-address-2" /></label>
                    <label class="koopo-appt__label">City *<input class="koopo-appt__field koopo-appt__service-city" autocomplete="address-level2" /></label>
                    <label class="koopo-appt__label">State or region *<input class="koopo-appt__field koopo-appt__service-region" autocomplete="address-level1" /></label>
                    <label class="koopo-appt__label">Postal code *<input class="koopo-appt__field koopo-appt__service-postal" autocomplete="postal-code" /></label>
                    <label class="koopo-appt__label">Country *<input class="koopo-appt__field koopo-appt__service-country" value="United States" autocomplete="country-name" /></label>
                  </div>
                  <button type="button" class="koopo-appt__coverage-check">Check service area</button>
                  <span class="koopo-appt__coverage-status" role="status"></span>
                </div>
                <div class="koopo-appt__virtual-note" hidden><strong>Online appointment</strong><span>Private joining details are shared only with the confirmed customer.</span></div>
              </fieldset>
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
                <div><strong>Appointment type:</strong> <span class="koopo-appt__summary-delivery">—</span></div>
                <div><strong>Duration:</strong> <span class="koopo-appt__duration">—</span></div>
                <div><strong>Price:</strong> <span class="koopo-appt__price">—</span></div>
              </div>

              <button type="button" class="koopo-appt__next-step koopo-appt__next-step--schedule" disabled>
                Continue to Your Information
              </button>
            </div>

            <!-- STEP 3: Customer Information -->
            <div class="koopo-appt__panel" data-panel="3">
              <div class="koopo-appt__form-grid">
                <label class="koopo-appt__label">
                  Koopo account name
                  <input type="text" class="koopo-appt__field koopo-appt__customer-name" value="<?php echo esc_attr((string) $booking_user->display_name); ?>" required readonly aria-readonly="true" />
                </label>

                <label class="koopo-appt__label">
                  Koopo account email
                  <input type="email" class="koopo-appt__field koopo-appt__customer-email" value="<?php echo esc_attr((string) $booking_user->user_email); ?>" required readonly aria-readonly="true" />
                </label>

                <label class="koopo-appt__label">
                  Your contact phone *
                  <input type="tel" class="koopo-appt__field koopo-appt__customer-phone" value="<?php echo esc_attr($booking_phone); ?>" required />
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
                <div><strong>Appointment type:</strong> <span class="koopo-appt__summary-delivery">—</span></div>
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
