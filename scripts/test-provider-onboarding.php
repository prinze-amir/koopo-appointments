<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$onboarding = (string) file_get_contents($root . '/includes/onboarding/class-kgaw-provider-onboarding.php');
$profiles = (string) file_get_contents($root . '/includes/providers/class-kgaw-provider-profiles.php');
$loader = (string) file_get_contents($root . '/includes/core/class-kgaw-module-loader.php');
$archive = (string) file_get_contents($root . '/templates/provider/archive.php');
$single = (string) file_get_contents($root . '/templates/provider/single.php');
$javascript = (string) file_get_contents($root . '/assets/provider-onboarding.js');
$admin = (string) file_get_contents($root . '/includes/admin/class-kgaw-admin-settings.php');
$ownerJavascript = (string) file_get_contents($root . '/assets/provider-owner.js');

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
$expect(strpos($onboarding, "provider-onboarding/verify-email") !== false && strpos($onboarding, 'verification_code') !== false, 'Inline email-code verification is missing.');
$expect(strpos($onboarding, 'VERIFY_MAX_ATTEMPTS') !== false && strpos($onboarding, 'rate_limit') !== false, 'Verification brute-force protection is missing.');
$expect(strpos($onboarding, 'provider-onboarding/prepare-checkout') !== false && strpos($onboarding, "'product_pack' === \$existing->get_type()") !== false, 'Secure onboarding pack cart preparation is missing.');
$expect(strpos($onboarding, "add_query_arg('provider_onboarding', '1', wc_get_checkout_url())") !== false && strpos($onboarding, 'render_checkout_intro') !== false, 'The secure WooCommerce checkout is not presented as the final onboarding step.');
$expect(strpos($onboarding, 'is_allowed_pack') !== false && strpos($onboarding, 'OPTION_ONBOARDING_PACK_IDS') !== false, 'Onboarding pack allowlist enforcement is missing.');
$expect(strpos($admin, 'field_onboarding_pack_ids') !== false && strpos($admin, "'product_pack' !== \$product->get_type()") !== false, 'Admin Dokan pack selection is missing or does not validate product type.');
$expect(strpos($onboarding, "'posts_per_page' => -1") !== false && strpos($onboarding, "'terms' => ['product_pack']") !== false, 'Admin subscription choices are not dynamically sourced from all published Dokan packs.');
$expect(strpos($onboarding, "'features_html'") !== false && strpos($onboarding, 'koopo-onboard-plan__toggle') !== false && strpos($onboarding, "aria-expanded=\"false\"") !== false, 'Product descriptions are not available through an accessible plan feature disclosure.');
$expect(strpos($javascript, "toggle.setAttribute('aria-expanded'") !== false && strpos($javascript, 'features.hidden = expanded') !== false, 'Plan feature disclosure behavior is incomplete.');
$expect(strpos($onboarding, 'add_verification_code_to_email') !== false && strpos($onboarding, 'core-user-registration') !== false, 'BuddyBoss activation email does not include the provider verification code.');
$expect(strpos($onboarding, 'dokan_user_update_to_seller') !== false && strpos($onboarding, 'make_active') !== false, 'Dokan seller activation is incomplete.');
$expect(strpos($onboarding, 'Provider_Profiles::create_for_user') !== false, 'Activation does not create the provider profile through the shared service.');
$expect(strpos($onboarding, "home_url('/new-seller/')") !== false, 'Signed-in non-vendors do not have a safe provider-upgrade route.');
$expect(strpos($profiles, "dokan_is_user_seller(\$user_id)") !== false, 'Sellers cannot create their base service profile without an appointment plan.');
$expect(strpos($archive, 'Provider_Onboarding::cta()') !== false, 'Provider CTA is missing from the directory.');
$expect(strpos($single, 'Provider_Onboarding::edit_url()') !== false && strpos($single, 'data-koopo-owner-open') !== false, 'Owner-only frontend management controls are missing.');
$expect(strpos($single, 'data-koopo-owner-dialog="images"') !== false && strpos($single, 'data-koopo-owner-dialog="services"') !== false && strpos($single, 'data-koopo-owner-dialog="settings"') !== false, 'Owner image, service, and settings dialogs are incomplete.');
$expect(strpos($ownerJavascript, 'uploadServiceProfileImage') !== false && strpos($ownerJavascript, 'uploadServiceProfileGalleryImage') !== false, 'Owner image dialogs do not use the Direct Offload helpers.');
$expect(strpos($ownerJavascript, "`/services/by-provider/\${providerId}?include_inactive=1`") !== false, 'Owner service management does not load the profile service menu.');
$expect(strpos((string) file_get_contents($root . '/includes/dokan/class-kgaw-dokan-dashboard.php'), 'render_verification_notice') !== false, 'Seller verification dashboard notice is missing.');
$expect(strpos($javascript, "#basic-details-section") !== false && strpos($javascript, "#profile-details-section") !== false, 'Multistep flow does not reuse BuddyBoss account/profile fields.');
$expect(strpos($javascript, 'input[name="koopo_service_modes[]"]') !== false, 'Service-mode client validation is missing.');
$expect(strpos($javascript, 'first_and_lastname') !== false && strpos($javascript, 'First and last name') !== false, 'Display-name options are not humanized.');
$expect(strpos($javascript, "nativeSubmit.value = 'Register'") !== false, 'Provider account action is not labelled Register.');
$expect(strpos($javascript, 'buddyPhone.required = true') !== false && strpos($onboarding, 'phone_field_id') !== false, 'BuddyBoss phone is not enforced for provider registration.');
$expect(strpos($javascript, 'configuredPhoneId') !== false && strpos($onboarding, "'phoneFieldId' => self::phone_field_id()") !== false, 'BuddyBoss phone auto-fill is not resilient to custom xProfile wrapper classes.');
$expect(strpos($javascript, 'const liveBuddyPhone =') !== false, 'Storefront phone synchronization does not recover when BuddyBoss replaces the xProfile input node.');
$expect(strpos($javascript, "syncIdentity();\n      show(current + 1") !== false, 'BuddyBoss identity values are not synchronized before advancing onboarding steps.');
$expect(strpos($javascript, "if (!buddyPhone.hasAttribute('required'))") !== false, 'Phone-field mutation observer is not guarded against recursive writes.');
$expect(strpos($javascript, "{node: service, label: 'Services'") < strpos($javascript, "{node: store, label: 'Store'"), 'Service profile must precede storefront setup.');
$expect(strpos($javascript, "{node: subscription, label: 'Plan'") > strpos($javascript, "{node: store, label: 'Store'"), 'Subscription selection must be the final registration panel.');
$expect(strpos($javascript, 'koopo-onboard-account-link') !== false && strpos($onboarding, "'loginUrl'") !== false, 'The in-shell sign-in action is missing.');
$expect(strpos((string) file_get_contents($root . '/assets/provider-onboarding.css'), 'article.bp_register>.entry-header') !== false, 'BuddyBoss registration chrome is not suppressed for provider onboarding.');

echo "provider onboarding tests passed\n";
