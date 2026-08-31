<?php

$root = dirname(__DIR__);
$integration = file_get_contents($root . '/includes/integrations/class-kgaw-buddyboss-service-activity.php');
$loader = file_get_contents($root . '/includes/core/class-kgaw-module-loader.php');
$css = file_get_contents($root . '/assets/buddyboss-service-activity.css');
$failures = [];

$expect = static function ($condition, $message) use (&$failures): void {
  if (!$condition) $failures[] = $message;
};

$expect(strpos($loader, 'class-kgaw-buddyboss-service-activity.php') !== false, 'BuddyBoss service activity integration is not loaded.');
$expect(strpos($loader, 'BuddyBoss_Service_Activity::class') !== false, 'BuddyBoss service activity integration is not initialized.');
$expect(strpos($integration, "'new_blog_product'") !== false, 'The override is not scoped to BuddyBoss product activity.');
$expect(strpos($integration, "'_koopo_service_id'") !== false && strpos($integration, "'_koopo_wc_product_id'") !== false, 'The product/service reverse-link validation is missing.');
$expect(strpos($integration, 'Services_API::META_PROVIDER_ID') !== false && strpos($integration, 'Provider_Profiles::POST_TYPE') !== false, 'The override is not restricted to provider-profile services.');
$expect(strpos($integration, "Provider_Profiles::gallery(") !== false && strpos($integration, 'Provider_Profiles::image_url') !== false, 'Portfolio-first profile-image fallback is missing.');
$expect(strpos($integration, "'bp_get_activity_content_body'") !== false && strpos($integration, "'bb_nouveau_get_activity_inner_buttons'") !== false, 'BuddyBoss web activity overrides are incomplete.');
$expect(strpos($integration, "[__CLASS__, 'filter_content'], 10020, 2") !== false, 'The service card must run after BuddyBoss rebuilds CPT content at priority 9999.');
$expect(strpos($integration, "'bp_rest_activity_prepare_value'") !== false && strpos($integration, "'koopo_service_profile'") !== false, 'BuddyBoss REST activity projection is missing.');
$expect(strpos($integration, "View Service Profile") !== false && strpos($integration, "View service profile") !== false, 'The provider-profile calls to action are missing.');
$expect(strpos($css, '.koopo-service-activity__media') !== false && strpos($css, '@media (max-width: 560px)') !== false, 'Responsive service activity card styling is missing.');

if ($failures) {
  fwrite(STDERR, "BuddyBoss service activity checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "BuddyBoss service activity checks passed.\n";
