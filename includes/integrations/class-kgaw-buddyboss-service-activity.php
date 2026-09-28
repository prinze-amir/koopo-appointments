<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Presents provider-owned service products as service-profile activity cards.
 *
 * WooCommerce products remain the private checkout objects. BuddyBoss activity
 * should advertise the public provider profile instead of exposing those
 * product permalinks.
 */
final class BuddyBoss_Service_Activity {
  private static array $contexts = [];

  public static function init(): void {
    add_filter('bp_blogs_format_activity_action_new_custom_post_type_feed', [__CLASS__, 'filter_action'], 20, 2);
    add_filter('bp_activity_custom_post_type_post_action', [__CLASS__, 'filter_action'], 20, 2);
    // BuddyBoss rebuilds blog/CPT content at 9999, so the public-profile card
    // must run afterward to prevent it restoring the product permalink.
    add_filter('bp_get_activity_content_body', [__CLASS__, 'filter_content'], 10020, 2);
    add_filter('bb_nouveau_get_activity_inner_buttons', [__CLASS__, 'filter_buttons'], 20, 3);
    add_filter('bp_rest_activity_prepare_value', [__CLASS__, 'filter_rest_response'], 20, 3);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 30);
  }

  public static function enqueue_assets(): void {
    if (!function_exists('bp_is_active') || !bp_is_active('activity')) return;
    wp_enqueue_style(
      'koopo-buddyboss-service-activity',
      KOOPO_APPT_URL . 'assets/buddyboss-service-activity.css',
      [],
      KOOPO_APPT_VERSION
    );
  }

  public static function filter_action($action, $activity): string {
    $context = self::activity_context($activity);
    if (!$context) return (string) $action;

    $user_link = function_exists('bp_core_get_userlink')
      ? (string) bp_core_get_userlink((int) $activity->user_id)
      : esc_html((string) get_the_author_meta('display_name', (int) $activity->user_id));
    $profile_link = sprintf(
      '<a href="%1$s">%2$s</a>',
      esc_url($context['profile_url']),
      esc_html($context['profile_name'])
    );

    return sprintf(
      __('%1$s added a new service to %2$s.', 'koopo-appointments'),
      $user_link,
      $profile_link
    );
  }

  public static function filter_content($content, $activity): string {
    $context = self::activity_context($activity);
    if (!$context) return (string) $content;

    $image = '';
    if ($context['image_url']) {
      $image = '<span class="koopo-service-activity__media"><img src="' . esc_url($context['image_url']) . '" alt="" loading="lazy" /></span>';
    }

    $headline = $context['headline']
      ? '<span class="koopo-service-activity__headline">' . esc_html($context['headline']) . '</span>'
      : '';

    return '<a class="koopo-service-activity" href="' . esc_url($context['profile_url']) . '">'
      . $image
      . '<span class="koopo-service-activity__body">'
      . '<span class="koopo-service-activity__eyebrow">' . esc_html__('New service', 'koopo-appointments') . '</span>'
      . '<strong class="koopo-service-activity__service">' . esc_html($context['service_name']) . '</strong>'
      . '<span class="koopo-service-activity__provider">' . esc_html($context['profile_name']) . '</span>'
      . $headline
      . '<span class="koopo-service-activity__cta">' . esc_html__('View service profile', 'koopo-appointments') . ' <span aria-hidden="true">→</span></span>'
      . '</span></a>';
  }

  public static function filter_buttons($buttons, $activity_id = 0, $args = []): array {
    unset($activity_id, $args);
    global $activities_template;

    $activity = is_object($activities_template ?? null) && isset($activities_template->activity)
      ? $activities_template->activity
      : null;
    $context = self::activity_context($activity);
    if (!$context) return is_array($buttons) ? $buttons : [];

    $buttons = is_array($buttons) ? $buttons : [];
    $buttons['activity_post'] = [
      'id' => 'activity_post_link_wrap',
      'position' => 4,
      'component' => 'activity',
      'button_element' => 'a',
      'link_text' => '<span class="text">' . esc_html__('View Service Profile', 'koopo-appointments') . '</span>',
      'button_attr' => [
        'href' => esc_url($context['profile_url']),
        'class' => 'button bb-icon-arrow-down bb-icons bp-secondary-action koopo-service-activity-button',
      ],
    ];
    return $buttons;
  }

  public static function filter_rest_response($response, $request, $activity) {
    unset($request);
    if (!($response instanceof \WP_REST_Response)) return $response;

    $context = self::activity_context($activity);
    if (!$context) return $response;

    $data = $response->get_data();
    if (!is_array($data)) return $response;

    $data['feature_media'] = $context['image_url'];
    $data['koopo_service_profile'] = [
      'service_id' => $context['service_id'],
      'service_name' => $context['service_name'],
      'provider_id' => $context['provider_id'],
      'provider_name' => $context['profile_name'],
      'url' => $context['profile_url'],
      'image_url' => $context['image_url'],
      'headline' => $context['headline'],
    ];
    $response->set_data($data);
    return $response;
  }

  private static function activity_context($activity): ?array {
    if (!is_object($activity) || 'blogs' !== (string) ($activity->component ?? '')) return null;
    if ('new_blog_product' !== (string) ($activity->type ?? '')) return null;
    return self::product_context(absint($activity->secondary_item_id ?? 0));
  }

  private static function product_context(int $product_id): ?array {
    if (!$product_id || 'product' !== get_post_type($product_id)) return null;
    if (array_key_exists($product_id, self::$contexts)) return self::$contexts[$product_id] ?: null;

    $service_id = (int) get_post_meta($product_id, '_koopo_service_id', true);
    if (!$service_id || Services_CPT::POST_TYPE !== get_post_type($service_id)) {
      self::$contexts[$product_id] = [];
      return null;
    }
    if ($product_id !== (int) get_post_meta($service_id, '_koopo_wc_product_id', true)) {
      self::$contexts[$product_id] = [];
      return null;
    }

    $provider_id = (int) get_post_meta($service_id, Services_API::META_PROVIDER_ID, true);
    $provider = $provider_id ? get_post($provider_id) : null;
    if (!$provider || Provider_Profiles::POST_TYPE !== $provider->post_type || 'publish' !== $provider->post_status) {
      self::$contexts[$product_id] = [];
      return null;
    }

    $service = get_post($service_id);
    if (!$service || (int) $service->post_author !== (int) $provider->post_author) {
      self::$contexts[$product_id] = [];
      return null;
    }

    $gallery = Provider_Profiles::gallery($provider_id);
    $portfolio_image = (string) ($gallery[0]['full_url'] ?? $gallery[0]['url'] ?? '');
    self::$contexts[$product_id] = [
      'service_id' => $service_id,
      'service_name' => (string) get_the_title($service_id),
      'provider_id' => $provider_id,
      'profile_name' => (string) get_the_title($provider_id),
      'profile_url' => (string) get_permalink($provider_id),
      'headline' => (string) (get_post_meta($provider_id, Provider_Profiles::META_HEADLINE, true) ?: get_post_field('post_excerpt', $provider_id)),
      'image_url' => $portfolio_image ?: Provider_Profiles::image_url($provider_id, 'large'),
    ];
    return self::$contexts[$product_id];
  }
}
