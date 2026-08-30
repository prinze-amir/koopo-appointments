<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$template = $source('templates/dokan/provider-profile.php');
$javascript = $source('assets/vendor-provider-profile.js');
$core = $source('assets/vendor-core.js');
$profiles = $source('includes/providers/class-kgaw-provider-profiles.php');
$access = $source('includes/core/class-kgaw-access.php');
$pack = $source('includes/admin/class-kgaw-pack-features-admin.php');
$dashboard = $source('includes/dokan/class-kgaw-dokan-dashboard.php');
$css = $source('assets/vendor.css');

$expect(strpos($template, 'data-koopo-create-toggle') !== false && strpos($template, 'koopo-provider-create-panel') !== false, 'The create-profile disclosure is missing.');
$expect(strpos($template, 'data-koopo-provider-grid') !== false && strpos($javascript, 'renderCards') !== false, 'The profile thumbnail library is missing.');
$expect(strpos($template, 'role="dialog"') !== false && strpos($javascript, 'openEditor') !== false && strpos($javascript, "event.key === 'Escape'") !== false, 'The accessible profile editor modal is incomplete.');
$expect(strpos($javascript, "event.key !== 'Tab'") !== false && strpos($javascript, 'modalReturnFocus') !== false, 'Modal focus management is incomplete.');
$expect(strpos($core, 'koopo:provider-created') !== false && strpos($javascript, 'koopo:provider-created') !== false, 'New profiles do not update the library without a reload.');
$expect(strpos($pack, "'product_pack' === \$type") !== false && strpos($pack, '_koopo_service_profile_limit') !== false && strpos($pack, '_koopo_service_profiles_unlimited') !== false, 'Dokan pack profile limits are not configurable.');
$expect(strpos($pack, "'service_profiles' =>") !== false && strpos($access, 'vendor_feature_limit') !== false, 'Profile quantities are not persisted as pack entitlements.');
$expect(strpos($access, "'service_profiles' === \$feature_key && empty(\$features['appointments'])") !== false && strpos($pack, 'wc_enqueue_js') !== false, 'Profile quantities are not coupled to the Appointments pack flag.');
$expect(strpos($profiles, 'profile_limit_reached') !== false && strpos($profiles, 'SELECT GET_LOCK') !== false && strpos($profiles, 'profile_entitlement') !== false, 'Profile limits are not enforced atomically by the creation service.');
$expect(strpos($dashboard, "'profileEntitlement'") !== false && strpos($dashboard, "'upgradeUrl'") !== false, 'The seller dashboard does not receive current profile entitlement state.');
$expect(strpos($css, '@media(prefers-reduced-motion:reduce)') !== false && strpos($css, '.koopo-provider-modal__dialog') !== false, 'Profile library motion or modal styling is incomplete.');

echo "provider profile library tests passed\n";
