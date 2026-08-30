<?php

$root = dirname(__DIR__);
$dashboard_template = file_get_contents($root . '/templates/dokan/provider-profile.php');
$public_template = file_get_contents($root . '/templates/provider/single.php');
$dashboard_js = file_get_contents($root . '/assets/vendor-provider-profile.js');
$owner_js = file_get_contents($root . '/assets/provider-owner.js');
$dashboard_css = file_get_contents($root . '/assets/vendor.css');
$owner_css = file_get_contents($root . '/assets/provider-owner.css');
$public_css = file_get_contents($root . '/assets/provider-directory.css');
$profiles = file_get_contents($root . '/includes/providers/class-kgaw-provider-profiles.php');
$media = file_get_contents($root . '/includes/media/class-kgaw-service-profile-media-adapter.php');
$failures = [];

$expect = static function ($condition, $message) use (&$failures): void {
  if (!$condition) $failures[] = $message;
};

$expect(strpos($dashboard_template, 'data-koopo-provider-gallery-drop') !== false, 'The seller portfolio drop zone is missing.');
$expect(strpos($public_template, "esc_html_e('Portfolio'") !== false && strpos($public_template, 'data-koopo-gallery') !== false, 'The public portfolio gallery is missing.');
$expect(strpos($public_template, 'data-koopo-owner-gallery-drop') !== false, 'The owner portfolio drop zone is missing.');
$expect(strpos($dashboard_js, 'uploadPortfolioFiles') !== false && strpos($owner_js, 'uploadPortfolio') !== false, 'Multi-file portfolio upload handlers are missing.');
$expect(strpos($dashboard_js, 'URL.createObjectURL') !== false && strpos($owner_js, 'URL.createObjectURL') !== false, 'Immediate portfolio previews are missing.');
$expect(strpos($dashboard_js, 'draggable="true"') !== false && strpos($owner_js, 'draggable="true"') !== false, 'Portfolio photos are not draggable in both editors.');
$expect(strpos($dashboard_js, 'persistGalleryOrder') !== false && strpos($owner_js, 'persistPortfolioOrder') !== false, 'Portfolio ordering is not persisted in both editors.');
$expect(strpos($dashboard_js, 'data-gallery-remove') !== false && strpos($owner_js, 'data-koopo-gallery-remove') !== false, 'Portfolio removal controls are missing.');
$expect(strpos($dashboard_css, '.koopo-provider-portfolio-drop') !== false && strpos($owner_css, '.koopo-owner-portfolio-drop') !== false, 'Portfolio drop-zone styling is missing.');
$expect(strpos($profiles, 'array_diff($requested, $owned)') !== false && strpos($profiles, 'array_diff($owned, $requested)') !== false, 'Server-side portfolio reorder ownership validation is missing.');
$expect(strpos($media, "const GALLERY_ROUTE_BASE = '/appointments/service-profiles/(?P<provider_id>\\d+)/gallery/upload-sessions';") !== false, 'Portfolio uploads are not routed through Direct Offload.');
$expect(strpos($media, "'koopo_appointments' !== sanitize_key((string) (\$meta['origin'] ?? ''))") === false, 'Portfolio finalization incorrectly requires optional gateway origin metadata.');
$expect(strpos($media, "\$role !== sanitize_key((string) (\$binding['role'] ?? ''))") !== false, 'Portfolio finalization is not bound to the server-side upload role.');
$expect(strpos($public_template, "apply_filters('koopo_appt_provider_portfolio_hero_enabled', true") !== false, 'The reversible portfolio hero feature filter is missing.');
$expect(strpos($public_template, 'koopo-pro-hero__content--portfolio') !== false && strpos($public_template, 'koopo-pro-hero__portfolio--') !== false, 'The portfolio-backed hero markup is missing.');
$expect(strpos($public_css, '.koopo-pro-hero__portfolio') !== false, 'The portfolio hero mosaic styling is missing.');

if ($failures) {
  fwrite(STDERR, "Provider portfolio checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Provider portfolio checks passed.\n";
