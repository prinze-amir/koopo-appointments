<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Updated BuddyBoss/BuddyPress Integration - Uses Commit 23 Customer Dashboard
 * Replaces old koopo-appointments endpoint with new customer dashboard template
 */
class BuddyBoss_Appointments {

  public static function init(): void {
    // Remove old endpoint registration
    // add_action('bp_setup_nav', [__CLASS__, 'setup_nav'], 100);
    
    // Use new registration
    add_action('bp_setup_nav', [__CLASS__, 'setup_appointments_tab'], 100);
    add_action('bp_setup_nav', [__CLASS__, 'setup_services_tab'], 101);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_services_assets'], 30);
  }

  public static function enqueue_services_assets(): void {
    if (!function_exists('bp_is_user') || !bp_is_user() || 'services' !== (string) bp_current_component()) return;
    wp_enqueue_style('koopo-buddyboss-services', KOOPO_APPT_URL . 'assets/buddyboss-services.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-buddyboss-services', KOOPO_APPT_URL . 'assets/buddyboss-services.js', [], KOOPO_APPT_VERSION, true);
  }

  public static function setup_services_tab(): void {
    if (!function_exists('bp_core_new_nav_item')) return;
    $user_id = (int) bp_displayed_user_id();
    if (!$user_id) return;
    $providers = get_posts([
      'post_type' => Provider_Profiles::POST_TYPE,
      'post_status' => 'publish',
      'author' => $user_id,
      'posts_per_page' => 1,
      'fields' => 'ids',
    ]);
    if (!$providers) return;
    bp_core_new_nav_item([
      'name' => __('Services', 'koopo-appointments'),
      'slug' => 'services',
      'screen_function' => [__CLASS__, 'services_screen'],
      'position' => 74,
    ]);
  }

  public static function services_screen(): void {
    add_action('bp_template_content', [__CLASS__, 'services_content']);
    bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
  }

  public static function services_content(): void {
    $user_id = (int) bp_displayed_user_id();
    $providers = get_posts([
      'post_type' => Provider_Profiles::POST_TYPE,
      'post_status' => 'publish',
      'author' => $user_id,
      'posts_per_page' => 20,
    ]);
    $all_categories = [];
    $profiles = array_values(array_filter(array_map(static function($provider) use (&$all_categories) {
      $profile = Provider_Profiles::format((int) $provider->ID, false);
      $profile['services'] = array_values(array_filter(Provider_Profiles::public_services((int) $provider->ID), static fn($service) => empty($service['is_addon'])));
      foreach ($profile['services'] as $service) foreach ($service['categories'] as $category) $all_categories[$category['slug']] = $category;
      return $profile['services'] ? $profile : null;
    }, $providers)));
    echo '<div class="koopo-buddyboss-services" data-koopo-bb-services>';
    echo '<header class="koopo-bb-services-header"><span>' . esc_html__('Services', 'koopo-appointments') . '</span><h2>' . esc_html(sprintf(__('Book with %s', 'koopo-appointments'), bp_core_get_user_displayname($user_id))) . '</h2><p>' . esc_html__('Compare services, see transparent pricing, and continue to the professional calendar.', 'koopo-appointments') . '</p></header>';
    if ($all_categories) {
      echo '<nav class="koopo-bb-service-filters" aria-label="' . esc_attr__('Filter services', 'koopo-appointments') . '"><button type="button" class="is-active" data-service-filter="all">' . esc_html__('All', 'koopo-appointments') . '</button>';
      foreach ($all_categories as $category) echo '<button type="button" data-service-filter="' . esc_attr($category['slug']) . '">' . esc_html($category['name']) . '</button>';
      echo '</nav>';
    }
    foreach ($profiles as $profile) {
      $location = $profile['locations'][0] ?? null;
      echo '<article class="koopo-bb-provider-storefront">';
      echo '<div class="koopo-bb-provider-intro">';
      if ($profile['image_url']) echo '<a href="' . esc_url($profile['permalink']) . '" class="koopo-bb-provider-image"><img src="' . esc_url($profile['image_url']) . '" alt="' . esc_attr($profile['name']) . '"></a>';
      echo '<div><span class="koopo-bb-provider-kicker">' . esc_html__('Service profile', 'koopo-appointments') . '</span><h3>' . esc_html($profile['name']) . '</h3>';
      if ($profile['headline']) echo '<p>' . esc_html($profile['headline']) . '</p>';
      if ($profile['reviews']['count']) echo '<small class="koopo-bb-provider-rating">★ ' . esc_html(number_format_i18n($profile['reviews']['average'], 1)) . ' · ' . esc_html(sprintf(_n('%s review', '%s reviews', $profile['reviews']['count'], 'koopo-appointments'), number_format_i18n($profile['reviews']['count']))) . '</small>';
      if ($location) echo '<small>' . esc_html($location['name']) . ($location['address'] ? ' · ' . esc_html($location['address']) : '') . '</small>';
      echo '</div><a class="koopo-bb-provider-link" href="' . esc_url($profile['permalink']) . '">' . esc_html($profile['gallery'] ? sprintf(__('View %d photos', 'koopo-appointments'), count($profile['gallery'])) : __('View profile', 'koopo-appointments')) . ' ↗</a></div>';
      echo '<div class="koopo-bb-service-list">';
      foreach ($profile['services'] as $service) {
        $slugs = wp_list_pluck($service['categories'], 'slug');
        $category_name = $service['categories'][0]['name'] ?? __('Service', 'koopo-appointments');
        echo '<a href="' . esc_url($profile['permalink'] . '#services') . '" class="koopo-bb-service-row" data-service-categories="' . esc_attr(implode(' ', $slugs)) . '"><div><span>' . esc_html($category_name) . '</span><h4>' . esc_html($service['title']) . '</h4>';
        if ($service['description']) echo '<p>' . esc_html($service['description']) . '</p>';
        echo '</div><div class="koopo-bb-service-price"><strong>' . wp_kses_post($service['price_label'] ?: wc_price($service['price'])) . '</strong><small>' . esc_html($service['duration_minutes'] . ' min') . '</small><b>' . esc_html__('Book', 'koopo-appointments') . ' →</b></div></a>';
      }
      echo '</div></article>';
    }
    echo '<p class="koopo-bb-services-empty" hidden>' . esc_html__('No services match this category.', 'koopo-appointments') . '</p>';
    echo '</div>';
  }

  /**
   * Register Appointments tab in BuddyBoss/BuddyPress profile
   */
  public static function setup_appointments_tab(): void {
    
    if (!function_exists('bp_core_new_nav_item')) {
      return;
    }

    $user_id = bp_displayed_user_id();
    
    // Only show to the profile owner
    if ($user_id !== get_current_user_id()) {
      return;
    }

    // Register main nav item
    bp_core_new_nav_item([
      'name'                => __('Appointments', 'koopo-appointments'),
      'slug'                => 'appointments',
      'screen_function'     => [__CLASS__, 'appointments_screen'],
      'position'            => 75,
      'default_subnav_slug' => 'my-appointments',
    ]);

    // Register subnav item
    bp_core_new_subnav_item([
      'name'            => __('My Appointments', 'koopo-appointments'),
      'slug'            => 'my-appointments',
      'parent_url'      => bp_core_get_user_domain($user_id) . 'appointments/',
      'parent_slug'     => 'appointments',
      'screen_function' => [__CLASS__, 'appointments_screen'],
      'position'        => 10,
    ]);
  }

  /**
   * Screen function for appointments tab
   */
  public static function appointments_screen(): void {
    add_action('bp_template_content', [__CLASS__, 'appointments_content']);
    
    // Load BuddyPress template
    bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
  }

  /**
   * Content for appointments tab - uses new customer dashboard template
   */
  public static function appointments_content(): void {
    
    if (!is_user_logged_in()) {
      echo '<p>' . esc_html__('Please log in to view your appointments.', 'koopo-appointments') . '</p>';
      return;
    }

    // Load the same template used by WooCommerce My Account and shortcode
    $template_path = KOOPO_APPT_PATH . 'templates/customer/my-appointments.php';
    
    if (file_exists($template_path)) {
      // Wrap in BuddyBoss-friendly container
      echo '<div class="koopo-buddyboss-appointments-wrapper">';
      include $template_path;
      echo '</div>';
    } else {
      echo '<p>' . esc_html__('Appointments template not found.', 'koopo-appointments') . '</p>';
    }
  }

  /**
   * DEPRECATED: Old setup_nav function (kept for reference, not used)
   * Remove this in future versions
   */
  public static function OLD_setup_nav(): void {
    // This function is replaced by setup_appointments_tab()
    // Keeping for backward compatibility documentation only
  }
}
