<?php

$root = dirname(__DIR__);
$front_css = [
  'assets/appointments.css',
  'assets/appointments-settings.css',
  'assets/buddyboss-services.css',
  'assets/customer-dashboard.css',
  'assets/provider-directory.css',
  'assets/provider-onboarding.css',
  'assets/provider-onboarding-checkout.css',
  'assets/provider-owner.css',
  'assets/vendor.css',
];
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
  if (!$condition) $failures[] = $message;
};
$source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

foreach ($front_css as $path) {
  $css = $source($path);
  preg_match_all('/(?:^|[;{])\s*font-family\s*:\s*([^;}]+)/i', $css, $families);
  foreach ($families[1] as $family) {
    $expect('inherit' === strtolower(trim($family)), $path . ' still hardcodes a font family instead of inheriting the theme.');
  }
  $expect(!preg_match('/(?:^|[;{])\s*font\s*:/i', $css), $path . ' still uses a font shorthand that overrides the theme family.');
}

$appointments = $source('assets/appointments.css');
$settings = $source('assets/appointments-settings.css');
$customer = $source('assets/customer-dashboard.css');
$directory = $source('assets/provider-directory.css');
$owner = $source('assets/provider-owner.css');
$vendor = $source('assets/vendor.css');

$expect(strpos($customer, "content: '\\1F4C5';") !== false && strpos($customer, 'bb-icons') === false, 'Customer navigation still depends on an icon font override.');
$expect(strpos($appointments, 'place-items: center;') !== false && strpos($customer, 'place-items: center;') !== false, 'Booking or customer modal close buttons are not centered.');
$expect(strpos($settings, '.koopo-appt-settings__close { position:absolute; right:14px; top:14px; display:grid; place-items:center;') !== false, 'Appointment settings close button is not centered.');
$expect(strpos($directory, '.koopo-pro-lightbox button{display:grid;place-items:center;padding:0;line-height:1}') !== false, 'Portfolio lightbox controls are not centered.');
$expect(strpos($owner, '.koopo-owner-dialog{font-size:15px;line-height:1.5}') !== false, 'Profile-owner settings typography was not enlarged.');
$expect(strpos($vendor, '.koopo-provider-library,.koopo-provider-modal__body{font-size:15px;line-height:1.5}') !== false, 'Seller service-profile settings typography was not enlarged.');
$expect(strpos($settings, '.kas { padding: 10px 0; font-size:15px; line-height:1.5; }') !== false && strpos($settings, 'font-size:14px; line-height:1.5;') !== false, 'Appointment settings typography was not enlarged.');
$expect(strpos($vendor, '.koopo-vendor-page button,.koopo-vendor-page input,.koopo-vendor-page select,.koopo-vendor-page textarea{font-family:inherit}') !== false, 'Seller controls do not inherit the active theme font.');

if ($failures) {
  fwrite(STDERR, "Front-end style consistency checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Front-end style consistency checks passed.\n";
