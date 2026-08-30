<?php

$root = dirname(__DIR__);
$source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$features = $source('includes/core/class-kgaw-features.php');
$admin = $source('includes/admin/class-kgaw-admin-settings.php');
$dokan = $source('includes/dokan/class-kgaw-dokan-dashboard.php');
$waitlist = $source('includes/waitlist/class-kgaw-waitlist.php');
$clients = $source('includes/clients/class-kgaw-client-records.php');
$media = $source('includes/media/class-kgaw-service-profile-media-adapter.php');
$ui = $source('includes/ui/class-kgaw-ui.php');
$customer = $source('includes/customer/class-kgaw-customer-dashboard.php');
$customer_template = $source('templates/customer/my-appointments.php');
$appointments_js = $source('assets/appointments.js');
$customer_js = $source('assets/customer-dashboard-app.js');
$admin_css = $source('assets/admin-settings.css');
$admin_js = $source('assets/admin-settings.js');
$failures = [];
$expect = static function(bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

$expect(strpos($features, "OPTION_WAITLIST_ENABLED = 'koopo_appt_waitlist_enabled'") !== false && strpos($features, "OPTION_CLIENT_FORMS_ENABLED = 'koopo_appt_client_forms_enabled'") !== false, 'Central feature options are missing.');
$expect(substr_count($features, 'get_option(') >= 2 && substr_count($features, ', 0)') >= 2, 'Release gates do not default off.');
$expect(strpos($admin, 'koopo_appt_feature_availability') !== false && strpos($admin, 'field_feature_availability') !== false, 'Admin release controls are missing.');
$expect(strpos($admin, '$position = 0; foreach ($sections as $section): $position++;') !== false && strpos($admin, '$index + 1') === false, 'Admin section numbering must not perform arithmetic on associative WordPress section keys.');
$expect(strpos($dokan, 'if (Features::waitlist_enabled())') !== false && strpos($dokan, 'if (Features::client_forms_enabled())') !== false, 'Disabled provider navigation is not hidden.');
$expect(strpos($dokan, "render_feature_unavailable('waitlist')") !== false && strpos($dokan, "render_feature_unavailable('client_forms')") !== false, 'Direct disabled dashboard routes do not fail closed.');
$expect(substr_count($waitlist, "'permission_callback'=>[__CLASS__,'permission']") >= 8 && strpos($waitlist, "Features::unavailable_error('waitlist')") !== false, 'Waitlist REST routes are not gated.');
$expect(strpos($waitlist, 'if(!Features::waitlist_enabled())return;') !== false, 'Waitlist background automation is not gated.');
$expect(substr_count($clients, "'permission_callback'=>[__CLASS__,'permission']") >= 8 && strpos($clients, "Features::unavailable_error('client_forms')") !== false, 'Client REST routes are not gated.');
$expect(strpos($clients, 'if (!Features::client_forms_enabled()) return;') !== false && strpos($clients, 'if(!Features::client_forms_enabled())return;') !== false, 'Client capture or scheduled requests are not gated.');
$expect(substr_count($media, "Features::unavailable_error('client_forms')") >= 1 && strpos($media, 'if(!Features::client_forms_enabled())wp_die') !== false, 'Private client-file routes are not gated.');
$expect(strpos($ui, "'waitlist' => Features::waitlist_enabled()") !== false && strpos($appointments_js, 'KOOPO_APPT.features.waitlist') !== false, 'Customer waitlist presentation is not gated.');
$expect(strpos($customer, "'clientForms' => Features::client_forms_enabled()") !== false && strpos($customer_template, 'Features::client_forms_enabled()') !== false && strpos($customer_js, 'KOOPO_CUSTOMER.features.clientForms') !== false, 'Customer forms presentation is not gated.');
$expect(strpos($admin_css, '.koopo-admin-settings__masthead') !== false && strpos($admin_css, '.koopo-feature-toggle') !== false && strpos($admin_css, 'prefers-reduced-motion') !== false, 'Modern responsive admin styling is missing.');
$expect(strpos($admin_js, 'IntersectionObserver') !== false && strpos($admin_js, 'Unsaved changes') !== false, 'Admin interaction feedback is missing.');

if ($failures) {
  fwrite(STDERR, "Feature release gate checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}
echo "Feature release gate checks passed.\n";
