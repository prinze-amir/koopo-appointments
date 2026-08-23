<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('WP_DEBUG', false);

$captured = [];
function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function apply_filters($hook, $value) { return $value; }
function do_action($hook, ...$args) {
  global $captured;
  if ($hook === 'koopo_appt_log') $captured[] = $args;
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
  if (!$condition) $failures[] = $message;
};

require_once $root . '/includes/core/class-kgaw-logger.php';
require_once $root . '/includes/core/class-kgaw-module-loader.php';

\Koopo_Appointments\Logger::info('privacy_probe', [
  'booking_id' => 42,
  'email' => 'private@example.com',
  'access_token' => 'secret-token',
  'nested' => ['phone' => '+13135551212', 'status' => 'pending'],
]);

$context = $captured[0][2] ?? [];
$expect(($context['booking_id'] ?? null) === 42, 'Operational identifiers should remain observable.');
$expect(($context['email'] ?? '') === '[redacted]', 'Email values must be redacted.');
$expect(($context['access_token'] ?? '') === '[redacted]', 'Token values must be redacted.');
$expect(($context['nested']['phone'] ?? '') === '[redacted]', 'Nested phone values must be redacted.');
$expect(($context['nested']['status'] ?? '') === 'pending', 'Non-sensitive nested status should remain observable.');

$main = file_get_contents($root . '/koopo-geo-appointments-wc.php');
$loader = file_get_contents($root . '/includes/core/class-kgaw-module-loader.php');
$expect(strpos($main, 'Module_Loader::load_foundation()') !== false, 'Main bootstrap must use the foundation module manifest.');
$expect(strpos($main, 'Module_Loader::load_features()') !== false, 'Main bootstrap must use the feature module manifest.');
$expect(strpos($main, 'Module_Loader::initialize()') !== false, 'Main bootstrap must use centralized initialization.');
$expect(strpos($loader, "[Bookings::class, 'init_cleanup_cron']") !== false, 'Booking cleanup initialization must be preserved.');
$plugin = $main;
$expect(strpos($plugin, "'koopo_appt_send_reminders'") !== false && strpos($plugin, "'koopo_appt_send_review_invites'") !== false, 'Deactivation does not clear notification schedules.');
$expect(strpos($plugin, 'as_unschedule_all_actions') !== false, 'Deactivation does not clear Action Scheduler jobs.');

preg_match_all("/'(includes\/[a-z0-9_\-\/]+\.php)'/", $loader, $matches);
foreach (array_unique($matches[1] ?? []) as $relative_path) {
  $expect(is_readable($root . '/' . $relative_path), 'Manifest file is missing: ' . $relative_path);
}

$raw_log_files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes'));
foreach ($iterator as $file) {
  if (!$file->isFile() || $file->getExtension() !== 'php') continue;
  $source = file_get_contents($file->getPathname());
  if (strpos($source, 'error_log(') !== false && $file->getFilename() !== 'class-kgaw-logger.php') {
    $raw_log_files[] = $file->getPathname();
  }
}
$expect($raw_log_files === [], 'Raw error_log calls must remain behind Logger.');

if ($failures) {
  fwrite(STDERR, "Bootstrap organization checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Bootstrap organization checks passed.\n";
