<?php
/** Guarded WordPress runtime test for service-product BuddyBoss presentation. */

use Koopo_Appointments\BuddyBoss_Service_Activity;
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\Services_API;

if (!defined('ABSPATH')) exit(1);

$expect = static function ($condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$suffix = strtolower(wp_generate_password(8, false, false));
$user_id = wp_create_user('koopo_activity_' . $suffix, wp_generate_password(24), 'koopo-activity-' . $suffix . '@example.invalid');
$expect(!is_wp_error($user_id), 'Could not create runtime test user.');

$created = ['user' => (int) $user_id, 'provider' => 0, 'service' => 0, 'product' => 0, 'ordinary_product' => 0];

try {
  $created['provider'] = (int) wp_insert_post([
    'post_type' => Provider_Profiles::POST_TYPE,
    'post_status' => 'publish',
    'post_title' => 'Activity Profile ' . $suffix,
    'post_excerpt' => 'Professional profile headline',
    'post_author' => $created['user'],
  ]);
  $created['service'] = (int) wp_insert_post([
    'post_type' => 'koopo_service',
    'post_status' => 'draft',
    'post_title' => 'Activity Service ' . $suffix,
    'post_author' => $created['user'],
  ]);
  $created['product'] = (int) wp_insert_post([
    'post_type' => 'product',
    'post_status' => 'draft',
    'post_title' => 'Private Checkout Product ' . $suffix,
    'post_author' => $created['user'],
  ]);
  $created['ordinary_product'] = (int) wp_insert_post([
    'post_type' => 'product',
    'post_status' => 'draft',
    'post_title' => 'Ordinary Product ' . $suffix,
    'post_author' => $created['user'],
  ]);
  foreach (['provider', 'service', 'product', 'ordinary_product'] as $key) {
    $expect($created[$key] > 0, 'Could not create runtime ' . $key . ' fixture.');
  }

  update_post_meta($created['service'], Services_API::META_PROVIDER_ID, $created['provider']);
  update_post_meta($created['service'], '_koopo_wc_product_id', $created['product']);
  update_post_meta($created['product'], '_koopo_service_id', $created['service']);

  $activity = (object) [
    'id' => 0,
    'user_id' => $created['user'],
    'component' => 'blogs',
    'type' => 'new_blog_product',
    'secondary_item_id' => $created['product'],
  ];
  $profile_url = get_permalink($created['provider']);
  $product_url = get_permalink($created['product']);

  $action = BuddyBoss_Service_Activity::filter_action('Original product action', $activity);
  $expect(strpos($action, 'added a new service') !== false && strpos($action, $profile_url) !== false, 'Activity action did not identify the service profile.');
  $content = BuddyBoss_Service_Activity::filter_content('Original product content', $activity);
  $expect(strpos($content, 'Activity Service ' . $suffix) !== false, 'Activity card omitted the service name.');
  $expect(strpos($content, 'Activity Profile ' . $suffix) !== false, 'Activity card omitted the profile name.');
  $expect(strpos($content, $profile_url) !== false && strpos($content, $product_url) === false, 'Activity card still links to the checkout product.');

  $expect(has_filter('bp_get_activity_content_body', [BuddyBoss_Service_Activity::class, 'filter_content']) === 10020, 'The service card is not registered after the BuddyBoss CPT renderer.');
  $filtered_content = apply_filters('bp_get_activity_content_body', '', $activity);
  $expect(strpos($filtered_content, 'koopo-service-activity') !== false, 'The full BuddyBoss content filter chain did not render the service card.');
  $expect(strpos($filtered_content, $product_url) === false, 'The full BuddyBoss content filter chain restored the checkout-product URL.');

  global $activities_template;
  $previous_template = $activities_template ?? null;
  $activities_template = (object) ['activity' => $activity];
  $buttons = BuddyBoss_Service_Activity::filter_buttons([
    'activity_post' => ['button_attr' => ['href' => $product_url]],
  ]);
  $activities_template = $previous_template;
  $expect(($buttons['activity_post']['button_attr']['href'] ?? '') === esc_url($profile_url), 'View Product was not replaced with the service-profile URL.');
  $expect(strpos($buttons['activity_post']['link_text'] ?? '', 'View Service Profile') !== false, 'Service-profile button label is missing.');

  $response = BuddyBoss_Service_Activity::filter_rest_response(new WP_REST_Response(['feature_media' => '']), new WP_REST_Request('GET'), $activity);
  $payload = $response->get_data();
  $expect(($payload['koopo_service_profile']['url'] ?? '') === $profile_url, 'REST activity payload omitted the service-profile URL.');
  $expect(($payload['koopo_service_profile']['service_id'] ?? 0) === $created['service'], 'REST activity payload omitted the service identity.');

  $ordinary_activity = clone $activity;
  $ordinary_activity->secondary_item_id = $created['ordinary_product'];
  $expect(BuddyBoss_Service_Activity::filter_content('Ordinary content', $ordinary_activity) === 'Ordinary content', 'Ordinary WooCommerce product activity was changed.');

  echo wp_json_encode([
    'provider_id' => $created['provider'],
    'service_id' => $created['service'],
    'product_id' => $created['product'],
    'web_card' => 'verified',
    'button_target' => 'provider_profile',
    'rest_projection' => 'verified',
    'ordinary_product' => 'unchanged',
  ], JSON_PRETTY_PRINT) . "\n";
} finally {
  foreach (['ordinary_product', 'product', 'service', 'provider'] as $key) {
    if ($created[$key]) wp_delete_post($created[$key], true);
  }
  if ($created['user']) {
    if (is_multisite() && function_exists('wpmu_delete_user')) wpmu_delete_user($created['user']);
    elseif (function_exists('wp_delete_user')) wp_delete_user($created['user']);
  }
}
