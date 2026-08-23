<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function wp_unslash($value) { return $value; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }

require_once dirname(__DIR__) . '/includes/dokan/class-kgaw-dokan-dashboard.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
  if (!$condition) $failures[] = $message;
};

$wp = (object) ['query_vars' => ['koopo-professional-profile' => '']];
$_SERVER['REQUEST_URI'] = '/unrelated/';
$expect(
  \Koopo_Appointments\Dokan_Dashboard::current_endpoint() === 'koopo-professional-profile',
  'Registered query vars must identify the Service Profile endpoint.'
);

$wp = (object) ['query_vars' => []];
$_SERVER['REQUEST_URI'] = '/seller-dashboard/koopo-appointment-settings/?source=uat';
$expect(
  \Koopo_Appointments\Dokan_Dashboard::current_endpoint() === 'koopo-appointment-settings',
  'The dashboard path fallback must identify custom Dokan shells.'
);

$_SERVER['REQUEST_URI'] = '/articles/koopo-appointment-settings-explained/';
$expect(
  \Koopo_Appointments\Dokan_Dashboard::current_endpoint() === '',
  'Endpoint routing must match exact path segments, not substrings.'
);

if ($failures) {
  fwrite(STDERR, "Dashboard asset routing checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Dashboard asset routing checks passed.\n";
