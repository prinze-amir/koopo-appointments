<?php

$root = dirname(__DIR__);
$template = file_get_contents($root . '/templates/dokan/appointments.php');
$script = file_get_contents($root . '/assets/vendor-appointments.js');
$styles = file_get_contents($root . '/assets/vendor.css');
$vendor_core = file_get_contents($root . '/assets/vendor-core.js');
$bookings_api = file_get_contents($root . '/includes/vendor/class-kgaw-vendor-bookings-api.php');
$failures = [];

$expect = static function ($condition, $message) use (&$failures): void {
  if (!$condition) {
    $failures[] = $message;
  }
};

$expect(strpos($template, 'koopo-appt-details-dialog') !== false, 'The compact appointment dialog class is missing.');
$expect(strpos($template, 'role="dialog"') !== false && strpos($template, 'aria-modal="true"') !== false, 'The appointment dialog accessibility attributes are missing.');
$expect(substr_count($template, 'koopo-appt-details__section--customer') === 1, 'Customer information must render in one section.');
$expect(substr_count($template, 'koopo-appt-details__section--schedule') === 1, 'Date, time, and duration must render in one section.');
$expect(substr_count($template, 'koopo-appt-details__section--service') === 1, 'Service, add-ons, and pricing must render in one section.');
$expect(strpos($template, 'koopo-appt-details__operations') !== false, 'Cancellation and refund must render in the compact activity strip.');
$expect(strpos($template, 'koopo-appt-details__row') === false, 'The legacy one-card-per-field appointment layout is still present.');
$expect(strpos($script, "Appointment #\${Number(b.id || 0)}") !== false, 'The appointment reference is not populated.');
$expect(strpos($script, "$('#koopo-appt-details-addons-wrap').toggle(addonTitles.length > 0)") !== false, 'Empty add-ons are not hidden.');
$expect(strpos($script, "actionMarkup === '—' ? '' : actionMarkup") !== false, 'The empty action placeholder is not suppressed.');
$expect(strpos($script, 'b.customer_message_url') !== false && strpos($script, '>Message</a>') !== false, 'The registered-customer Message action is missing.');
$expect(strpos($bookings_api, 'private static function customer_message_url') !== false, 'The BuddyBoss compose URL helper is missing.');
$expect(strpos($bookings_api, "'customer_message_url' => \$customer_message_url") !== false, 'The booking response does not expose the customer message URL.');
$expect(strpos($bookings_api, "\$customer_id <= 0 || \$customer_id === get_current_user_id()") !== false, 'Guest and self-message links are not fail-closed.');
$expect(strpos($styles, '#koopo-appt-details-modal .koopo-appt-details-dialog') !== false, 'Compact modal sizing is missing.');
$expect(strpos($styles, '@media (prefers-reduced-motion: reduce)') !== false, 'Reduced-motion behavior is missing.');
$expect(strpos($styles, 'max-height: 92dvh') !== false, 'The mobile bottom-sheet viewport constraint is missing.');
$expect(strpos($vendor_core, 'decoder.innerHTML = String(rawName)') !== false && strpos($vendor_core, 'const name = decoder.value') !== false, 'Customer names are not decoded before dashboard rendering.');

if ($failures) {
  fwrite(STDERR, "Appointment details modal checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Appointment details modal checks passed.\n";
