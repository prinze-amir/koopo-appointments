<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$onboarding = (string) file_get_contents($root . '/includes/onboarding/class-kgaw-provider-onboarding.php');
$profiles = (string) file_get_contents($root . '/includes/providers/class-kgaw-provider-profiles.php');
$loader = (string) file_get_contents($root . '/includes/core/class-kgaw-module-loader.php');
$archive = (string) file_get_contents($root . '/templates/provider/archive.php');
$single = (string) file_get_contents($root . '/templates/provider/single.php');
$javascript = (string) file_get_contents($root . '/assets/provider-onboarding.js');

$expect = static function(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$expect(strpos($profiles, "'has_archive' => 'bookable'") !== false, 'Provider archive is not registered at /bookable/.');
$expect(strpos($profiles, "['professionals', 'book']") !== false && strpos($profiles, 'wp_safe_redirect') !== false, 'Legacy /professionals/ and /book/ redirects are missing.');
$expect(strpos($profiles, 'CollectionPage') !== false && strpos($profiles, 'Koopo Booking') !== false, 'Booking directory SEO metadata is missing.');
$expect(strpos($profiles, 'prepare_archive_seo') !== false && strpos($profiles, 'output_seo_meta') !== false, 'BuddyBoss fallback SEO metadata is not replaced on the Bookable archive.');
$expect(strpos($profiles, 'og:description') !== false && strpos($profiles, 'twitter:description') !== false, 'Bookable social metadata is missing.');
$expect(strpos($loader, "'includes/onboarding/class-kgaw-provider-onboarding.php'") !== false && strpos($loader, "[Provider_Onboarding::class, 'init']") !== false, 'Provider onboarding is not bootstrapped.');
$expect(strpos($onboarding, "add_action('bp_signup_validate'") !== false, 'BuddyBoss validation hook is missing.');
$expect(strpos($onboarding, "add_filter('bp_signup_usermeta'") !== false, 'Pending BuddyBoss signup metadata is not captured.');
$expect(strpos($onboarding, "add_action('bp_core_activated_user'") !== false, 'Verified-email activation hook is missing.');
$expect(strpos($onboarding, 'dokan_user_update_to_seller') !== false && strpos($onboarding, 'make_active') !== false, 'Dokan seller activation is incomplete.');
$expect(strpos($onboarding, 'Provider_Profiles::create_for_user') !== false, 'Activation does not create the provider profile through the shared service.');
$expect(strpos($onboarding, "home_url('/new-seller/')") !== false, 'Signed-in non-vendors do not have a safe provider-upgrade route.');
$expect(strpos($profiles, "dokan_is_user_seller(\$user_id)") !== false, 'Sellers cannot create their base service profile without an appointment plan.');
$expect(strpos($archive, 'Provider_Onboarding::cta()') !== false, 'Provider CTA is missing from the directory.');
$expect(strpos($single, 'Provider_Onboarding::edit_url()') !== false && strpos($single, 'get_current_user_id() === $owner_id') !== false, 'Owner-only frontend edit link is missing.');
$expect(strpos((string) file_get_contents($root . '/includes/dokan/class-kgaw-dokan-dashboard.php'), 'render_verification_notice') !== false, 'Seller verification dashboard notice is missing.');
$expect(strpos($javascript, "#basic-details-section") !== false && strpos($javascript, "#profile-details-section") !== false, 'Multistep flow does not reuse BuddyBoss account/profile fields.');
$expect(strpos($javascript, 'input[name="koopo_service_modes[]"]') !== false, 'Service-mode client validation is missing.');

echo "provider onboarding tests passed\n";
