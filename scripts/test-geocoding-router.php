<?php
/** Lightweight parser and security regression tests for the geocoding gateway. */
define('ABSPATH', __DIR__ . '/');
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function __($value) { return $value; }
class WP_Error {
  public function __construct(private string $code = '', private string $message = '', private array $data = []) {}
  public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }

require_once dirname(__DIR__) . '/includes/geocoding/class-kgaw-geocoding-router.php';

use Koopo_Appointments\Geocoding_Router;

function geocoder_expect($condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$parse = new ReflectionMethod(Geocoding_Router::class, 'parse');

$farm = $parse->invoke(null, 'geocodefarm', [
  'STATUS'=>['status'=>'SUCCESS'],
  'RESULTS'=>['result'=>['coordinates'=>['lat'=>'42.3314','lon'=>'-83.0458'],'address'=>['full_address'=>'Detroit, MI'],'accuracy'=>'EXACT_MATCH']],
]);
geocoder_expect(!is_wp_error($farm) && $farm['latitude'] === 42.3314 && $farm['longitude'] === -83.0458, 'GeocodeFarm response parsing failed.');

$geoapify = $parse->invoke(null, 'geoapify', ['features'=>[['geometry'=>['coordinates'=>[-83.0458,42.3314]],'properties'=>['formatted'=>'Detroit, MI','rank'=>['confidence'=>0.98]]]]]);
geocoder_expect(!is_wp_error($geoapify) && $geoapify['latitude'] === 42.3314 && $geoapify['longitude'] === -83.0458, 'Geoapify response parsing failed.');

$google = $parse->invoke(null, 'google', ['status'=>'OK','results'=>[['formatted_address'=>'Detroit, MI','geometry'=>['location'=>['lat'=>42.3314,'lng'=>-83.0458],'location_type'=>'ROOFTOP']]]]);
geocoder_expect(!is_wp_error($google) && $google['accuracy'] === 'rooftop', 'Google response parsing failed.');

$osm = $parse->invoke(null, 'osm', [['lat'=>'42.3314','lon'=>'-83.0458','display_name'=>'Detroit, Michigan','type'=>'city']]);
geocoder_expect(!is_wp_error($osm) && $osm['formatted_address'] === 'Detroit, Michigan', 'OSM response parsing failed.');

$invalid = $parse->invoke(null, 'osm', [['lat'=>'999','lon'=>'-83.0']]);
geocoder_expect(is_wp_error($invalid), 'Out-of-range coordinates were accepted.');
geocoder_expect(Geocoding_Router::canonical_provider('geofarm') === 'geocodefarm', 'GeocodeFarm alias failed.');
geocoder_expect(Geocoding_Router::canonical_provider('apify') === 'geoapify', 'Geoapify alias failed.');

$router_source = file_get_contents(dirname(__DIR__) . '/includes/geocoding/class-kgaw-geocoding-router.php');
$settings_source = file_get_contents(dirname(__DIR__) . '/includes/admin/class-kgaw-admin-settings.php');
geocoder_expect(str_contains($router_source, "privacy_class'] !== self::PUBLIC_PRIVACY_CLASS"), 'Public OSM privacy guard is missing.');
geocoder_expect(str_contains($router_source, "\$privacy_class !== 'customer_private'"), 'Customer-coordinate cache privacy guard is missing.');
geocoder_expect(str_contains($router_source, "'sslverify' => true"), 'TLS verification guard is missing.');
geocoder_expect(str_contains($settings_source, "Calendar_Crypto::encrypt(['secret' => \$value])"), 'Encrypted API-key storage guard is missing.');

echo "geocoding router tests passed\n";
