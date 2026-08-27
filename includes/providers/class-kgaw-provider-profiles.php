<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

final class Provider_Profiles {
  const POST_TYPE = 'koopo_provider';
  const META_HEADLINE = '_koopo_provider_headline';
  const META_SERVICE_MODES = '_koopo_provider_service_modes';
  const META_PHONE = '_koopo_provider_phone';
  const META_STATUS = '_koopo_provider_status';
  const META_LOCATION_NAME = '_koopo_provider_location_name';
  const META_ADDRESS = '_koopo_provider_address';
  const META_CITY = '_koopo_provider_city';
  const META_REGION = '_koopo_provider_region';
  const META_POSTAL_CODE = '_koopo_provider_postal_code';
  const META_COUNTRY = '_koopo_provider_country';
  const META_LATITUDE = '_koopo_provider_latitude';
  const META_LONGITUDE = '_koopo_provider_longitude';
  const META_GEOCODE_PROVIDER = '_koopo_provider_geocode_provider';
  const META_GEOCODED_AT = '_koopo_provider_geocoded_at';
  const META_GEOCODE_ACCURACY = '_koopo_provider_geocode_accuracy';
  const META_LOCATION_PUBLIC = '_koopo_provider_location_public';
  const META_VIRTUAL_METHOD = '_koopo_provider_virtual_method';
  const META_VIRTUAL_URL = '_koopo_provider_virtual_url';
  const META_VIRTUAL_INSTRUCTIONS = '_koopo_provider_virtual_instructions';
  const META_GALLERY_IDS = '_koopo_provider_gallery_ids';
  const GALLERY_MEDIA_ROLE = 'service_profile_gallery';
  const GALLERY_LIMIT = 12;
  private static $using_public_template = false;

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_post_type']);
    add_action('template_redirect', [__CLASS__, 'redirect_legacy_archive'], 1);
    add_action('wp', [__CLASS__, 'prepare_archive_seo'], 20);
    add_filter('document_title_parts', [__CLASS__, 'archive_title']);
    add_action('wp_head', [__CLASS__, 'archive_seo_meta'], 2);
    add_filter('wpseo_title', [__CLASS__, 'seo_title']);
    add_filter('wpseo_metadesc', [__CLASS__, 'seo_description']);
    add_filter('wpseo_canonical', [__CLASS__, 'seo_canonical']);
    add_filter('rank_math/frontend/title', [__CLASS__, 'seo_title']);
    add_filter('rank_math/frontend/description', [__CLASS__, 'seo_description']);
    add_filter('rank_math/frontend/canonical', [__CLASS__, 'seo_canonical']);
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_directory_assets']);
    add_filter('template_include', [__CLASS__, 'public_template'], 99);
    add_action('pre_get_posts', [__CLASS__, 'filter_archive_query']);
    add_filter('the_content', [__CLASS__, 'append_public_profile']);
    add_action('before_delete_post', [__CLASS__, 'deactivate_owned_data'], 25, 2);
  }

  public static function deactivate_owned_data(int $post_id, ?\WP_Post $post = null): void {
    $post = $post ?: get_post($post_id);
    if (!$post || $post->post_type !== self::POST_TYPE) return;
    global $wpdb;
    $resource = Resources::for_subject('provider', $post_id);
    if ($resource) {
      $wpdb->update(DB::resources_table(), ['status' => 'inactive', 'updated_at' => current_time('mysql')], ['id' => (int) $resource->id]);
      $wpdb->update(DB::client_forms_table(), ['enabled' => 0], ['resource_id' => (int) $resource->id]);
      $wpdb->update(DB::waitlist_table(), ['status' => 'closed', 'updated_at' => current_time('mysql')], ['resource_id' => (int) $resource->id]);
    }
    $wpdb->delete(DB::affiliations_table(), ['provider_id' => $post_id], ['%d']);
    $wpdb->delete(DB::service_index_table(), ['provider_id' => $post_id], ['%d']);
    $service_ids = get_posts([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'any',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_key' => Services_API::META_PROVIDER_ID,
      'meta_value' => $post_id,
    ]);
    foreach ($service_ids as $service_id) {
      if ('trash' !== get_post_status($service_id)) wp_trash_post((int) $service_id);
    }
  }

  public static function register_post_type(): void {
    register_post_type(self::POST_TYPE, [
      'labels' => ['name' => 'Service Profiles', 'singular_name' => 'Service Profile'],
      'public' => true,
      'show_ui' => true,
      'show_in_menu' => 'koopo-appointments',
      'show_in_rest' => false,
      'has_archive' => 'bookable',
      'rewrite' => ['slug' => 'bookable', 'with_front' => false],
      'supports' => ['title', 'editor', 'excerpt', 'author', 'thumbnail', 'comments'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
    if ((string) get_option('koopo_appt_provider_rewrite_version', '') !== KOOPO_APPT_VERSION) {
      flush_rewrite_rules(false);
      update_option('koopo_appt_provider_rewrite_version', KOOPO_APPT_VERSION);
    }
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/providers', [
      [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'list_public'],
        'permission_callback' => '__return_true',
        'args' => [
          'page' => ['type'=>'integer','default'=>1,'minimum'=>1,'maximum'=>10000,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'per_page' => ['type'=>'integer','default'=>20,'minimum'=>1,'maximum'=>50,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'search' => ['type'=>'string','maxLength'=>100,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
        ],
      ],
      [
        'methods' => 'POST',
        'callback' => [__CLASS__, 'create'],
        'permission_callback' => [__CLASS__, 'can_create'],
      ],
    ]);
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)', [
      [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'read_or_update'],
        'permission_callback' => '__return_true',
        'args' => ['id'=>['type'=>'integer','minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint']],
      ],
      [
        'methods' => 'POST',
        'callback' => [__CLASS__, 'read_or_update'],
        'permission_callback' => static fn(\WP_REST_Request $request): bool => self::can_manage(absint($request['id'])),
        'args' => ['id'=>['type'=>'integer','minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint']],
      ],
    ]);
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)/gallery', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'reorder_gallery'],
      'permission_callback' => fn(\WP_REST_Request $request) => self::can_manage(absint($request['id'])),
    ]);
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)/gallery/(?P<attachment_id>\d+)', [
      'methods' => 'DELETE',
      'callback' => [__CLASS__, 'delete_gallery_image'],
      'permission_callback' => fn(\WP_REST_Request $request) => self::can_manage(absint($request['id'])),
    ]);
    register_rest_route('koopo/v1', '/vendor/booking-contexts', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'booking_contexts'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  public static function can_create(): bool {
    $user_id = get_current_user_id();
    return $user_id > 0 && (Access::is_admin_bypass($user_id) || Access::vendor_has_feature($user_id, 'appointments') || (function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id)));
  }

  private static function can_manage(int $provider_id): bool {
    $post = get_post($provider_id);
    return (bool) ($post && $post->post_type === self::POST_TYPE && (Access::is_admin_bypass() || (int) $post->post_author === get_current_user_id()));
  }

  public static function create(\WP_REST_Request $request): \WP_REST_Response {
    $payload = (array) $request->get_json_params();
    $provider_id = self::create_for_user(get_current_user_id(), $payload);
    if (is_wp_error($provider_id)) {
      $status = in_array($provider_id->get_error_code(), ['profile_name_required', 'service_mode_required'], true) ? 422 : 500;
      return new \WP_REST_Response(['error' => $provider_id->get_error_message(), 'code' => $provider_id->get_error_code()], $status);
    }
    return new \WP_REST_Response(self::format((int) $provider_id, true), 201);
  }

  /** Create one provider-owned service profile and its scheduling resource. */
  public static function create_for_user(int $user_id, array $payload) {
    $title = sanitize_text_field((string) ($payload['name'] ?? $payload['title'] ?? ''));
    if (!$user_id) return new \WP_Error('invalid_provider_owner', __('A valid profile owner is required.', 'koopo-appointments'));
    if (!$title) return new \WP_Error('profile_name_required', __('Service profile name is required.', 'koopo-appointments'));
    if (empty($payload['service_modes']) || !is_array($payload['service_modes'])) return new \WP_Error('service_mode_required', __('Choose at least one service delivery option.', 'koopo-appointments'));
    $provider_id = wp_insert_post([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_content' => wp_kses_post((string) ($payload['bio'] ?? '')),
      'post_excerpt' => sanitize_textarea_field((string) ($payload['headline'] ?? '')),
      'post_author' => $user_id,
      'comment_status' => 'open',
    ], true);
    if (is_wp_error($provider_id)) return $provider_id;
    $saved = self::save_meta((int) $provider_id, $payload);
    if (is_wp_error($saved)) {
      wp_delete_post((int) $provider_id, true);
      return $saved;
    }
    update_post_meta((int) $provider_id, '_koopo_appt_enabled', '1');
    $resource_id = Resources::ensure_for_provider((int) $provider_id);
    update_post_meta((int) $provider_id, Resources::META_RESOURCE_ID, $resource_id);
    return (int) $provider_id;
  }

  public static function owned_profile_id(int $user_id): int {
    if (!$user_id) return 0;
    $ids = get_posts([
      'post_type' => self::POST_TYPE,
      'post_status' => ['publish', 'draft', 'pending', 'private'],
      'author' => $user_id,
      'posts_per_page' => 1,
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    return (int) ($ids[0] ?? 0);
  }

  /** Keep indexed and bookmarked legacy directory URLs working after the slug change. */
  public static function redirect_legacy_archive(): void {
    if (is_admin()) return;
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    $request_path = untrailingslashit((string) wp_parse_url($request_uri, PHP_URL_PATH));
    $matches = [];
    $is_legacy = false;
    foreach (['professionals', 'book'] as $legacy_slug) {
      $legacy_path = untrailingslashit((string) wp_parse_url(home_url('/' . $legacy_slug . '/'), PHP_URL_PATH));
      if ($request_path === $legacy_path || preg_match('#^' . preg_quote($legacy_path, '#') . '/page/([0-9]+)$#', $request_path, $matches)) {
        $is_legacy = true;
        break;
      }
    }
    if (!$is_legacy) return;

    $target = get_post_type_archive_link(self::POST_TYPE) ?: home_url('/bookable/');
    if (!empty($matches[1])) $target = trailingslashit($target) . 'page/' . absint($matches[1]) . '/';
    $query = [];
    foreach (['s', 'service_category'] as $key) {
      if (isset($_GET[$key]) && !is_array($_GET[$key])) $query[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
    }
    wp_safe_redirect($query ? add_query_arg($query, $target) : $target, 301, 'Koopo Appointments');
    exit;
  }

  public static function archive_title(array $parts): array {
    if (is_post_type_archive(self::POST_TYPE)) $parts['title'] = __('Koopo Booking — Book Local Services & Professionals', 'koopo-appointments');
    return $parts;
  }

  public static function seo_title(string $title): string {
    return is_post_type_archive(self::POST_TYPE) ? __('Koopo Booking | Book Local Services & Professionals', 'koopo-appointments') : $title;
  }

  public static function seo_description(string $description): string {
    return is_post_type_archive(self::POST_TYPE) ? __('Discover bookable service providers and local businesses on Koopo. Compare services, locations, reviews, availability, and prices, then book online.', 'koopo-appointments') : $description;
  }

  public static function seo_canonical(string $canonical): string {
    return is_post_type_archive(self::POST_TYPE) ? (get_post_type_archive_link(self::POST_TYPE) ?: home_url('/bookable/')) : $canonical;
  }

  /** Replace BuddyBoss Sharing's site-wide fallback metadata on this archive only. */
  public static function prepare_archive_seo(): void {
    if (!is_post_type_archive(self::POST_TYPE)) return;

    $class = '\\BuddyBoss\\Sharing\\Modules\\Site_SEO';
    if (!class_exists($class) || !is_callable([$class, 'instance'])) return;

    $site_seo = $class::instance();
    remove_action('wp_head', [$site_seo, 'output_seo_meta'], 1);
    remove_action('wp_head', [$site_seo, 'output_open_graph_meta'], 5);
  }

  public static function archive_seo_meta(): void {
    if (!is_post_type_archive(self::POST_TYPE)) return;
    $url = get_post_type_archive_link(self::POST_TYPE) ?: home_url('/bookable/');
    $title = self::seo_title('');
    $description = self::seo_description('');
    if (!defined('WPSEO_VERSION') && !defined('RANK_MATH_VERSION')) {
      echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
      echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
      echo '<meta property="og:type" content="website">' . "\n";
      echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
      echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
      echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
      echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
      echo '<meta name="twitter:card" content="summary">' . "\n";
      echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
      echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'CollectionPage',
      'name' => $title,
      'description' => $description,
      'url' => $url,
      'isPartOf' => ['@type' => 'WebSite', 'name' => get_bloginfo('name'), 'url' => home_url('/')],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
  }

  public static function read_or_update(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    $post = get_post($provider_id);
    if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
      return new \WP_REST_Response(['error' => 'Professional not found.'], 404);
    }
    if ($request->get_method() === 'POST') {
      if (!self::can_manage($provider_id)) return new \WP_REST_Response(['error' => 'Forbidden'], 403);
      $payload = (array) $request->get_json_params();
      $update = ['ID' => $provider_id];
      if (isset($payload['name'])) $update['post_title'] = sanitize_text_field((string) $payload['name']);
      if (isset($payload['bio'])) $update['post_content'] = wp_kses_post((string) $payload['bio']);
      if (isset($payload['headline'])) $update['post_excerpt'] = sanitize_textarea_field((string) $payload['headline']);
      $saved = self::save_meta($provider_id, $payload);
      if (is_wp_error($saved)) return new \WP_REST_Response(['error' => $saved->get_error_message(), 'code' => $saved->get_error_code()], 422);
      $updated = wp_update_post($update, true);
      if (is_wp_error($updated)) return new \WP_REST_Response(['error' => $updated->get_error_message(), 'code' => $updated->get_error_code()], 500);
    }
    return new \WP_REST_Response(self::format($provider_id, self::can_manage($provider_id)), 200);
  }

  public static function list_public(\WP_REST_Request $request): \WP_REST_Response {
    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = min(50, max(1, absint($request->get_param('per_page')) ?: 20));
    $query = new \WP_Query([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $per_page,
      'paged' => $page,
      's' => sanitize_text_field((string) $request->get_param('search')),
    ]);
    $response = new \WP_REST_Response(array_map(fn($post) => self::format((int) $post->ID, false), $query->posts), 200);
    $response->header('X-WP-Total', (string) $query->found_posts);
    $response->header('X-WP-TotalPages', (string) $query->max_num_pages);
    return $response;
  }

  public static function booking_contexts(): \WP_REST_Response {
    $user_id = get_current_user_id();
    foreach (Vendor_Listings_API::get_listings_for_user($user_id) as $listing) {
      Resources::ensure_for_listing((int) $listing['id']);
    }
    return new \WP_REST_Response(Resources::contexts_for_user($user_id), 200);
  }

  public static function save_meta(int $provider_id, array $payload) {
    $validated_modes = null;
    if (isset($payload['service_modes']) && is_array($payload['service_modes'])) {
      $allowed = ['at_location', 'mobile', 'virtual'];
      $validated_modes = array_values(array_intersect($allowed, array_map('sanitize_key', $payload['service_modes'])));
      if (!$validated_modes) return new \WP_Error('service_mode_required', __('Choose at least one service delivery option.', 'koopo-appointments'));
    }
    $validated_virtual = null;
    if (array_key_exists('virtual_delivery', $payload) && is_array($payload['virtual_delivery'])) {
      $virtual = $payload['virtual_delivery'];
      $method = sanitize_key((string) ($virtual['method'] ?? 'provider_sends'));
      if (!in_array($method, ['provider_sends', 'custom_link', 'google_meet', 'zoom'], true)) $method = 'provider_sends';
      $join_url = esc_url_raw((string) ($virtual['join_url'] ?? ''));
      if (in_array($method, ['custom_link', 'google_meet', 'zoom'], true)
        && (!$join_url || 'https' !== strtolower((string) wp_parse_url($join_url, PHP_URL_SCHEME)))) {
        return new \WP_Error('virtual_url_required', __('Enter a valid HTTPS meeting URL or choose “Provider sends details”.', 'koopo-appointments'));
      }
      $validated_virtual = ['method' => $method, 'join_url' => $join_url, 'instructions' => sanitize_textarea_field((string) ($virtual['instructions'] ?? ''))];
    }
    $address_keys = ['address', 'city', 'region', 'postal_code', 'country'];
    $address_changed = (bool) array_intersect($address_keys, array_keys($payload));
    if ($address_changed) {
      $address = Service_Areas::sanitize_address([
        'address' => array_key_exists('address', $payload) ? $payload['address'] : get_post_meta($provider_id, self::META_ADDRESS, true),
        'city' => array_key_exists('city', $payload) ? $payload['city'] : get_post_meta($provider_id, self::META_CITY, true),
        'region' => array_key_exists('region', $payload) ? $payload['region'] : get_post_meta($provider_id, self::META_REGION, true),
        'postal_code' => array_key_exists('postal_code', $payload) ? $payload['postal_code'] : get_post_meta($provider_id, self::META_POSTAL_CODE, true),
        'country' => array_key_exists('country', $payload) ? $payload['country'] : get_post_meta($provider_id, self::META_COUNTRY, true),
      ]);
      $manual_lat = array_key_exists('latitude', $payload) ? self::coordinate($payload['latitude'], -90, 90) : '';
      $manual_lng = array_key_exists('longitude', $payload) ? self::coordinate($payload['longitude'], -180, 180) : '';
      if ($manual_lat !== '' && $manual_lng !== '') {
        update_post_meta($provider_id, self::META_GEOCODE_PROVIDER, 'manual');
        update_post_meta($provider_id, self::META_GEOCODED_AT, current_time('mysql', true));
        delete_post_meta($provider_id, self::META_GEOCODE_ACCURACY);
      } elseif ($address['city'] !== '' && ($address['address_1'] !== '' || $address['postal_code'] !== '')) {
        $is_public = array_key_exists('location_public', $payload) ? !empty($payload['location_public']) : '1' === (string) get_post_meta($provider_id, self::META_LOCATION_PUBLIC, true);
        $geocoded = Service_Areas::geocode($address, $is_public ? Geocoding_Router::PUBLIC_PRIVACY_CLASS : 'provider_private', 'provider_profile_location');
        if (is_wp_error($geocoded)) return $geocoded;
        $payload['latitude'] = $geocoded['latitude'];
        $payload['longitude'] = $geocoded['longitude'];
        update_post_meta($provider_id, self::META_GEOCODE_PROVIDER, sanitize_key((string) ($geocoded['provider'] ?? 'custom')));
        update_post_meta($provider_id, self::META_GEOCODED_AT, sanitize_text_field((string) ($geocoded['geocoded_at'] ?? current_time('mysql', true))));
        update_post_meta($provider_id, self::META_GEOCODE_ACCURACY, sanitize_text_field((string) ($geocoded['accuracy'] ?? '')));
      } else {
        $payload['latitude'] = '';
        $payload['longitude'] = '';
        delete_post_meta($provider_id, self::META_GEOCODE_PROVIDER);
        delete_post_meta($provider_id, self::META_GEOCODED_AT);
        delete_post_meta($provider_id, self::META_GEOCODE_ACCURACY);
      }
    }
    if (array_key_exists('service_area', $payload) && is_array($payload['service_area']) && class_exists(Service_Areas::class)) {
      $area = Service_Areas::save_for_provider($provider_id, $payload['service_area']);
      if (is_wp_error($area)) return $area;
    }
    if (isset($payload['headline'])) update_post_meta($provider_id, self::META_HEADLINE, sanitize_text_field((string) $payload['headline']));
    if (isset($payload['phone'])) update_post_meta($provider_id, self::META_PHONE, sanitize_text_field((string) $payload['phone']));
    if (null !== $validated_modes) update_post_meta($provider_id, self::META_SERVICE_MODES, $validated_modes);
    $location_fields = [
      'location_name' => self::META_LOCATION_NAME,
      'address' => self::META_ADDRESS,
      'city' => self::META_CITY,
      'region' => self::META_REGION,
      'postal_code' => self::META_POSTAL_CODE,
      'country' => self::META_COUNTRY,
    ];
    foreach ($location_fields as $field => $meta_key) {
      if (array_key_exists($field, $payload)) update_post_meta($provider_id, $meta_key, sanitize_text_field((string) $payload[$field]));
    }
    if (array_key_exists('latitude', $payload)) update_post_meta($provider_id, self::META_LATITUDE, self::coordinate($payload['latitude'], -90, 90));
    if (array_key_exists('longitude', $payload)) update_post_meta($provider_id, self::META_LONGITUDE, self::coordinate($payload['longitude'], -180, 180));
    if (array_key_exists('location_public', $payload)) update_post_meta($provider_id, self::META_LOCATION_PUBLIC, !empty($payload['location_public']) ? '1' : '0');
    if (null !== $validated_virtual) {
      update_post_meta($provider_id, self::META_VIRTUAL_METHOD, $validated_virtual['method']);
      update_post_meta($provider_id, self::META_VIRTUAL_URL, $validated_virtual['join_url']);
      update_post_meta($provider_id, self::META_VIRTUAL_INSTRUCTIONS, $validated_virtual['instructions']);
    }
    if (array_key_exists('category_id', $payload)) {
      $category_id = absint($payload['category_id']);
      $valid = $category_id ? get_term($category_id, Service_Categories::TAXONOMY) : null;
      wp_set_object_terms($provider_id, $valid && !is_wp_error($valid) ? [$category_id] : [], Service_Categories::TAXONOMY, false);
      if (class_exists(Bookable_Listings_API::class)) {
        self::queue_service_index_updates($provider_id);
      }
    }
    update_post_meta($provider_id, self::META_STATUS, 'active');
    return true;
  }

  private static function coordinate($value, float $min, float $max): string {
    if ('' === trim((string) $value) || !is_numeric($value)) return '';
    $number = (float) $value;
    return $number >= $min && $number <= $max ? (string) round($number, 7) : '';
  }

  private static function queue_service_index_updates(int $provider_id): void {
    $page = 1;
    $batch_size = 200;
    do {
      $service_ids = get_posts([
        'post_type'=>Services_CPT::POST_TYPE,
        'post_status'=>'any',
        'posts_per_page'=>$batch_size,
        'paged'=>$page,
        'fields'=>'ids',
        'orderby'=>'ID',
        'order'=>'ASC',
        'no_found_rows'=>true,
        'meta_key'=>Services_API::META_PROVIDER_ID,
        'meta_value'=>$provider_id,
      ]);
      foreach ($service_ids as $service_id) Bookable_Listings_API::queue_service_sync((int) $service_id);
      $page++;
    } while (count($service_ids) === $batch_size);
  }

  public static function direct_location(int $provider_id, bool $include_private = false): ?array {
    $is_public = '1' === (string) get_post_meta($provider_id, self::META_LOCATION_PUBLIC, true);
    if (!$include_private && !$is_public) return null;
    $lat = (float) get_post_meta($provider_id, self::META_LATITUDE, true);
    $lng = (float) get_post_meta($provider_id, self::META_LONGITUDE, true);
    $address_parts = array_filter([
      (string) get_post_meta($provider_id, self::META_ADDRESS, true),
      (string) get_post_meta($provider_id, self::META_CITY, true),
      (string) get_post_meta($provider_id, self::META_REGION, true),
      (string) get_post_meta($provider_id, self::META_POSTAL_CODE, true),
      (string) get_post_meta($provider_id, self::META_COUNTRY, true),
    ]);
    if (!$address_parts && !$lat && !$lng) return null;
    return [
      'listing_id' => 0,
      'name' => (string) (get_post_meta($provider_id, self::META_LOCATION_NAME, true) ?: __('Independent location', 'koopo-appointments')),
      'url' => '',
      'relationship_type' => 'independent_location',
      'address' => implode(', ', array_unique(array_map('sanitize_text_field', $address_parts))),
      'latitude' => $lat,
      'longitude' => $lng,
      'source' => 'service_profile',
    ];
  }

  public static function format(int $provider_id, bool $private = false): array {
    $post = get_post($provider_id);
    $resource = Resources::for_subject('provider', $provider_id);
    $resource_id = $resource ? (int) $resource->id : 0;
    $owner_id = $post ? (int) $post->post_author : 0;
    $categories = self::categories($provider_id);
    $data = [
      'id' => $provider_id,
      'name' => get_the_title($provider_id),
      'headline' => (string) (get_post_meta($provider_id, self::META_HEADLINE, true) ?: get_post_field('post_excerpt', $provider_id)),
      'bio' => wp_kses_post((string) get_post_field('post_content', $provider_id)),
      'image_url' => self::image_url($provider_id, 'large'),
      'image_source' => get_post_thumbnail_id($provider_id) ? 'service_profile' : 'member_avatar',
      'permalink' => get_permalink($provider_id),
      'resource_id' => $resource_id,
      'service_modes' => (array) get_post_meta($provider_id, self::META_SERVICE_MODES, true),
      'service_area' => class_exists(Service_Areas::class) ? Service_Areas::public_area($provider_id) : null,
      'virtual_delivery' => self::virtual_delivery($provider_id, false),
      'locations' => array_values(array_filter(array_merge([self::direct_location($provider_id, $private)], Provider_Affiliations::approved_locations($provider_id)))),
      'buddyboss_profile_url' => function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($owner_id) : get_author_posts_url($owner_id),
      'gallery' => self::gallery($provider_id),
      'reviews' => Provider_Reviews::summary($provider_id),
      'category' => $categories[0] ?? null,
      'categories' => $categories,
    ];
    if ($private) {
      $data['owner_user_id'] = $owner_id;
      $data['image_attachment_id'] = (int) get_post_thumbnail_id($provider_id);
      $data['location'] = self::direct_location($provider_id, true);
      $data['location_public'] = '1' === (string) get_post_meta($provider_id, self::META_LOCATION_PUBLIC, true);
      $data['location_fields'] = [
        'address' => (string) get_post_meta($provider_id, self::META_ADDRESS, true),
        'city' => (string) get_post_meta($provider_id, self::META_CITY, true),
        'region' => (string) get_post_meta($provider_id, self::META_REGION, true),
        'postal_code' => (string) get_post_meta($provider_id, self::META_POSTAL_CODE, true),
        'country' => (string) get_post_meta($provider_id, self::META_COUNTRY, true),
      ];
      $data['service_area'] = class_exists(Service_Areas::class) && Service_Areas::get_for_provider($provider_id) ? self::private_service_area($provider_id) : null;
      $data['virtual_delivery'] = self::virtual_delivery($provider_id, true);
    }
    return $data;
  }

  private static function private_service_area(int $provider_id): ?array {
    $row = Service_Areas::get_for_provider($provider_id);
    if (!$row) return null;
    $request = new \WP_REST_Request('GET');
    $request->set_url_params(['id' => $provider_id]);
    return Service_Areas::read($request)->get_data();
  }

  public static function virtual_delivery(int $provider_id, bool $private = false): array {
    $method = (string) get_post_meta($provider_id, self::META_VIRTUAL_METHOD, true);
    if ($method === '') $method = 'provider_sends';
    $data = [
      'enabled' => in_array('virtual', (array) get_post_meta($provider_id, self::META_SERVICE_MODES, true), true),
      'method' => $method,
      'label' => $method === 'google_meet' ? __('Google Meet', 'koopo-appointments') : ($method === 'zoom' ? __('Zoom', 'koopo-appointments') : __('Online meeting', 'koopo-appointments')),
    ];
    if ($private) {
      $data['join_url'] = (string) get_post_meta($provider_id, self::META_VIRTUAL_URL, true);
      $data['instructions'] = (string) get_post_meta($provider_id, self::META_VIRTUAL_INSTRUCTIONS, true);
    }
    return $data;
  }

  public static function image_url(int $provider_id, $size = 'large'): string {
    $url = (string) get_the_post_thumbnail_url($provider_id, $size);
    if ($url) return $url;
    $owner_id = (int) get_post_field('post_author', $provider_id);
    if (!$owner_id) return '';
    if (function_exists('bp_core_fetch_avatar')) {
      $url = (string) bp_core_fetch_avatar(['item_id' => $owner_id, 'type' => 'full', 'html' => false, 'no_grav' => false]);
      if ($url) return $url;
    }
    return (string) get_avatar_url($owner_id, ['size' => 640]);
  }

  public static function categories(int $provider_id): array {
    $terms = wp_get_object_terms($provider_id, Service_Categories::TAXONOMY);
    if (is_wp_error($terms)) return [];
    return array_map(static fn($term): array => ['id'=>(int)$term->term_id,'name'=>(string)$term->name,'slug'=>(string)$term->slug,'glyph'=>(string)get_term_meta($term->term_id,'glyph',true)], array_slice($terms, 0, 1));
  }

  public static function gallery_ids(int $provider_id): array {
    $stored = get_post_meta($provider_id, self::META_GALLERY_IDS, true);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', is_array($stored) ? $stored : [])))), 0, self::GALLERY_LIMIT);
    return array_values(array_filter($ids, static function(int $attachment_id) use ($provider_id): bool {
      return 'attachment' === get_post_type($attachment_id)
        && $provider_id === (int) get_post_field('post_parent', $attachment_id)
        && '1' === (string) get_post_meta($attachment_id, '_koopo_media_remote_only', true)
        && 'profile' === (string) get_post_meta($attachment_id, '_koopo_media_context', true)
        && self::GALLERY_MEDIA_ROLE === (string) get_post_meta($attachment_id, '_koopo_media_role', true);
    }));
  }

  public static function gallery(int $provider_id): array {
    return array_values(array_filter(array_map(static function(int $attachment_id): ?array {
      $url = (string) wp_get_attachment_image_url($attachment_id, 'large');
      if (!$url) return null;
      $metadata = (array) wp_get_attachment_metadata($attachment_id);
      return [
        'id' => $attachment_id,
        'url' => $url,
        'full_url' => (string) wp_get_attachment_image_url($attachment_id, 'full'),
        'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        'width' => absint($metadata['width'] ?? 0),
        'height' => absint($metadata['height'] ?? 0),
      ];
    }, self::gallery_ids($provider_id))));
  }

  public static function add_gallery_image(int $provider_id, int $attachment_id): bool {
    $ids = self::gallery_ids($provider_id);
    if (in_array($attachment_id, $ids, true)) return true;
    if (count($ids) >= self::GALLERY_LIMIT) return false;
    $ids[] = $attachment_id;
    return (bool) update_post_meta($provider_id, self::META_GALLERY_IDS, $ids);
  }

  public static function reorder_gallery(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    $payload = (array) $request->get_json_params();
    $owned = self::gallery_ids($provider_id);
    $requested = array_values(array_unique(array_filter(array_map('absint', (array) ($payload['attachment_ids'] ?? [])))));
    if (array_diff($requested, $owned) || array_diff($owned, $requested)) return new \WP_REST_Response(['error' => 'The gallery order contains invalid images.'], 422);
    update_post_meta($provider_id, self::META_GALLERY_IDS, array_slice($requested, 0, self::GALLERY_LIMIT));
    return new \WP_REST_Response(['gallery' => self::gallery($provider_id)], 200);
  }

  public static function delete_gallery_image(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    $attachment_id = absint($request['attachment_id']);
    $ids = self::gallery_ids($provider_id);
    if (!in_array($attachment_id, $ids, true)) return new \WP_REST_Response(['error' => 'Gallery image not found.'], 404);
    update_post_meta($provider_id, self::META_GALLERY_IDS, array_values(array_diff($ids, [$attachment_id])));
    wp_delete_attachment($attachment_id, true);
    return new \WP_REST_Response(['gallery' => self::gallery($provider_id)], 200);
  }

  public static function public_services(int $provider_id, int $limit = 100): array {
    $provider_categories = self::categories($provider_id);
    $posts = get_posts([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => max(1, $limit),
      'orderby' => 'menu_order title',
      'order' => 'ASC',
      'meta_query' => [[
        'key' => Services_API::META_PROVIDER_ID,
        'value' => $provider_id,
        'compare' => '=',
      ]],
    ]);
    $services = [];
    foreach ($posts as $service) {
      if ('inactive' === (string) get_post_meta($service->ID, Services_API::META_STATUS, true)) continue;
      $product_id = (int) get_post_meta($service->ID, '_koopo_wc_product_id', true);
      if (!$product_id || 'product' !== get_post_type($product_id)) continue;
      $price = get_post_meta($service->ID, Services_API::META_PRICE, true);
      $duration = get_post_meta($service->ID, Services_API::META_DURATION, true);
      $services[] = [
        'id' => (int) $service->ID,
        'title' => get_the_title($service),
        'description' => (string) get_post_meta($service->ID, Services_API::META_DESC, true),
        'price' => (float) $price,
        'price_label' => (string) get_post_meta($service->ID, Services_API::META_PRICE_LABEL, true),
        'duration_minutes' => (int) $duration,
        'wc_product_id' => $product_id,
        'is_addon' => '1' === (string) get_post_meta($service->ID, Services_API::META_ADDON, true),
        'categories' => $provider_categories,
      ];
    }
    return $services;
  }

  public static function public_template(string $template): string {
    if (is_post_type_archive(self::POST_TYPE)) {
      $candidate = KOOPO_APPT_PATH . 'templates/provider/archive.php';
      if (file_exists($candidate)) { self::$using_public_template = true; return $candidate; }
    }
    if (is_singular(self::POST_TYPE)) {
      $candidate = KOOPO_APPT_PATH . 'templates/provider/single.php';
      if (file_exists($candidate)) { self::$using_public_template = true; return $candidate; }
    }
    return $template;
  }

  public static function filter_archive_query(\WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive(self::POST_TYPE)) return;
    $category = sanitize_title((string) ($_GET['service_category'] ?? ''));
    if (!$category) return;
    $query->set('tax_query', [[ 'taxonomy' => Service_Categories::TAXONOMY, 'field' => 'slug', 'terms' => $category ]]);
  }

  public static function enqueue_directory_assets(): void {
    if (!is_post_type_archive(self::POST_TYPE) && !is_singular(self::POST_TYPE)) return;
    wp_enqueue_style('koopo-provider-directory', KOOPO_APPT_URL . 'assets/provider-directory.css', [], KOOPO_APPT_VERSION);
    wp_enqueue_style('koopo-provider-leaflet', plugins_url('geodirectory/assets/leaflet/leaflet.css'), [], KOOPO_APPT_VERSION);
    wp_enqueue_script('koopo-provider-leaflet', plugins_url('geodirectory/assets/leaflet/leaflet.min.js'), [], KOOPO_APPT_VERSION, true);
    wp_enqueue_script('koopo-provider-directory', KOOPO_APPT_URL . 'assets/provider-directory.js', ['koopo-provider-leaflet'], KOOPO_APPT_VERSION, true);
    wp_localize_script('koopo-provider-directory', 'KOOPO_PROVIDER', [
      'rest' => esc_url_raw(rest_url('koopo/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'loggedIn' => is_user_logged_in(),
      'tileUrl' => (string) apply_filters('koopo_appt_provider_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ]);
    if (is_singular(self::POST_TYPE)) {
      $provider_id = (int) get_queried_object_id();
      $owner_id = (int) get_post_field('post_author', $provider_id);
      if (is_user_logged_in() && (get_current_user_id() === $owner_id || Access::is_admin_bypass())) {
        wp_enqueue_style('koopo-provider-owner', KOOPO_APPT_URL . 'assets/provider-owner.css', [], KOOPO_APPT_VERSION);
        wp_enqueue_script('koopo-appt-provider-owner-core', KOOPO_APPT_URL . 'assets/vendor-core.js', ['jquery'], KOOPO_APPT_VERSION, true);
        wp_localize_script('koopo-appt-provider-owner-core', 'KOOPO_APPT_VENDOR', [
          'rest' => esc_url_raw(rest_url('koopo/v1')),
          'mediaGatewayRest' => esc_url_raw(rest_url('koopo-media-gateway/v1')),
          'serviceProfileImageMaxBytes' => (int) apply_filters('koopo_appt_service_profile_image_max_bytes', 6 * MB_IN_BYTES),
          'nonce' => wp_create_nonce('wp_rest'),
          'userId' => get_current_user_id(),
          'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        ]);
        wp_enqueue_script('koopo-provider-owner', KOOPO_APPT_URL . 'assets/provider-owner.js', ['koopo-appt-provider-owner-core'], KOOPO_APPT_VERSION, true);
        wp_localize_script('koopo-provider-owner', 'KOOPO_PROVIDER_OWNER', [
          'providerId' => $provider_id,
          'profile' => self::format($provider_id, true),
          'categories' => Service_Categories::get_all_categories(),
          'canManageServices' => Access::is_admin_bypass() || Access::vendor_has_feature(get_current_user_id(), 'appointments'),
          'dashboardUrl' => Provider_Onboarding::edit_url(),
        ]);
      }
    }
  }

  public static function append_public_profile(string $content): string {
    if (!is_singular(self::POST_TYPE) || !in_the_loop() || !is_main_query()) return $content;
    if (self::$using_public_template) return $content;
    $provider_id = get_the_ID();
    $profile = self::format($provider_id, false);
    $modes = array_map(fn($mode) => ucwords(str_replace('_', ' ', $mode)), $profile['service_modes']);
    $booking = UI::render_booking(['provider_id' => $provider_id, 'button_text' => 'Book an Appointment']);
    $details = '<section class="koopo-provider-profile">';
    if ($profile['headline']) $details .= '<p class="koopo-provider-profile__headline">' . esc_html($profile['headline']) . '</p>';
    if ($modes) $details .= '<p class="koopo-provider-profile__modes">' . esc_html(implode(' · ', $modes)) . '</p>';
    if ($profile['locations']) {
      $links = array_map(static fn(array $location): string => '<a href="' . esc_url($location['url']) . '">' . esc_html($location['name']) . '</a>', $profile['locations']);
      $details .= '<p class="koopo-provider-profile__locations"><strong>' . esc_html__('Available at:', 'koopo-appointments') . '</strong> ' . implode(', ', $links) . '</p>';
    }
    $details .= $booking . '</section>';
    return $content . $details;
  }
}
