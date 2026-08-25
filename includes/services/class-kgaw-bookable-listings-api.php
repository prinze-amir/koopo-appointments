<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Bookable_Listings_API {
  const REBUILD_HOOK = 'koopo_appt_rebuild_bookable_index';
  const SYNC_SERVICE_HOOK = 'koopo_appt_sync_bookable_service';
  const SYNC_LISTING_HOOK = 'koopo_appt_sync_bookable_listing';
  private static bool $syncing = false;
  private static array $queued = [];

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('save_post_' . Services_CPT::POST_TYPE, [__CLASS__, 'queue_service_sync'], 20, 1);
    add_action('save_post_gd_place', [__CLASS__, 'queue_listing_sync'], 20, 1);
    add_action('trashed_post', [__CLASS__, 'handle_post_removed'], 20, 1);
    add_action('untrashed_post', [__CLASS__, 'handle_post_restored'], 20, 1);
    add_action('before_delete_post', [__CLASS__, 'handle_post_removed'], 20, 1);
    add_action('added_post_meta', [__CLASS__, 'handle_post_meta_change'], 20, 4);
    add_action('updated_post_meta', [__CLASS__, 'handle_post_meta_change'], 20, 4);
    add_action('deleted_post_meta', [__CLASS__, 'handle_post_meta_change'], 20, 4);
    add_action('set_object_terms', [__CLASS__, 'handle_terms_change'], 20, 6);
    add_action(self::REBUILD_HOOK, [__CLASS__, 'run_rebuild_batch'], 10, 1);
    add_action(self::SYNC_SERVICE_HOOK, [__CLASS__, 'sync_service'], 10, 1);
    add_action(self::SYNC_LISTING_HOOK, [__CLASS__, 'sync_listing'], 10, 1);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/providers/discovery', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_provider_discovery'],
      'permission_callback' => '__return_true',
      'args' => [
        'page' => ['type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1, 'maximum' => 10000, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'per_page' => ['type' => 'integer', 'required' => false, 'default' => 12, 'minimum' => 1, 'maximum' => 24, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'search' => ['type' => 'string', 'required' => false, 'maxLength' => 100, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_text_field'],
        'category' => ['type' => 'string', 'required' => false, 'maxLength' => 100, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_text_field'],
        'service_mode' => ['type' => 'string', 'required' => false, 'enum' => ['at_location', 'mobile', 'virtual'], 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_key'],
      ],
    ]);

    register_rest_route('koopo/v1', '/bookable-listings', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_bookable_listings'],
      'permission_callback' => '__return_true',
      'args' => [
        'page' => ['type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1, 'maximum' => 10000, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'per_page' => ['type' => 'integer', 'required' => false, 'default' => 12, 'minimum' => 1, 'maximum' => 24, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'absint'],
        'search' => ['type' => 'string', 'required' => false, 'maxLength' => 100, 'validate_callback'=>'rest_validate_request_arg', 'sanitize_callback' => 'sanitize_text_field'],
      ],
    ]);
  }

  /** Lightweight provider cards backed by the service index. */
  public static function get_provider_discovery(\WP_REST_Request $req): \WP_REST_Response {
    self::maybe_seed_index();

    global $wpdb;
    $page = max(1, absint($req->get_param('page')));
    $per_page = min(24, max(1, absint($req->get_param('per_page')) ?: 12));
    $offset = ($page - 1) * $per_page;
    $search = sanitize_text_field((string) $req->get_param('search'));
    $category = sanitize_text_field((string) $req->get_param('category'));
    $service_mode = sanitize_key((string) $req->get_param('service_mode'));

    $service_table = DB::service_index_table();
    $resource_table = DB::resources_table();
    $where = "si.provider_id IS NOT NULL
      AND si.provider_id > 0
      AND si.status != 'inactive'
      AND si.is_addon = 0
      AND si.wc_product_id IS NOT NULL
      AND si.wc_product_id > 0
      AND p.post_type = %s
      AND p.post_status = 'publish'
      AND r.subject_type = 'provider'
      AND r.status = 'active'";
    $params = [Provider_Profiles::POST_TYPE];

    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $where .= ' AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR si.title LIKE %s OR si.description LIKE %s)';
      array_push($params, $like, $like, $like, $like);
    }

    if ($category !== '') {
      $category_term = ctype_digit($category)
        ? get_term(absint($category), Service_Categories::TAXONOMY)
        : get_term_by('slug', sanitize_title($category), Service_Categories::TAXONOMY);
      if (!$category_term || is_wp_error($category_term)) {
        return self::empty_provider_discovery($page, $per_page);
      }
      $where .= " AND EXISTS (
        SELECT 1 FROM {$wpdb->term_relationships} tr
        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE tr.object_id = si.provider_id AND tt.taxonomy = %s AND tt.term_id = %d
      )";
      $params[] = Service_Categories::TAXONOMY;
      $params[] = (int) $category_term->term_id;
    }

    if (in_array($service_mode, ['at_location', 'mobile', 'virtual'], true)) {
      $where .= " AND EXISTS (
        SELECT 1 FROM {$wpdb->postmeta} pm
        WHERE pm.post_id = si.provider_id AND pm.meta_key = %s AND pm.meta_value LIKE %s
      )";
      $params[] = Provider_Profiles::META_SERVICE_MODES;
      $params[] = '%"' . $wpdb->esc_like($service_mode) . '"%';
    }

    $from = "FROM {$service_table} si
      INNER JOIN {$wpdb->posts} p ON p.ID = si.provider_id
      INNER JOIN {$resource_table} r ON r.id = si.resource_id AND r.subject_id = si.provider_id";
    $count_sql = "SELECT COUNT(DISTINCT si.provider_id) {$from} WHERE {$where}";
    $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

    $items_sql = "SELECT si.provider_id,
        MAX(si.resource_id) AS resource_id,
        MAX(si.vendor_id) AS vendor_id,
        COUNT(*) AS service_count,
        MIN(si.price) AS min_price,
        MAX(si.price) AS max_price,
        MAX(si.currency) AS currency,
        MAX(si.updated_at) AS service_updated_at
      {$from}
      WHERE {$where}
      GROUP BY si.provider_id
      ORDER BY service_updated_at DESC, si.provider_id DESC
      LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($items_sql, array_merge($params, [$per_page, $offset])), ARRAY_A) ?: [];
    $provider_ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'provider_id'))));
    if ($provider_ids) {
      update_meta_cache('post', $provider_ids);
      update_object_term_cache($provider_ids, Provider_Profiles::POST_TYPE);
    }

    $items = array_map(static function(array $row): array {
      $provider_id = (int) $row['provider_id'];
      $categories = Provider_Profiles::categories($provider_id);
      $location = Provider_Profiles::direct_location($provider_id, false);
      return [
        'provider_id' => $provider_id,
        'resource_id' => (int) $row['resource_id'],
        'vendor_id' => (int) $row['vendor_id'],
        'name' => get_the_title($provider_id),
        'headline' => (string) (get_post_meta($provider_id, Provider_Profiles::META_HEADLINE, true) ?: get_post_field('post_excerpt', $provider_id)),
        'image_url' => Provider_Profiles::image_url($provider_id, 'large'),
        'permalink' => get_permalink($provider_id),
        'service_modes' => array_values((array) get_post_meta($provider_id, Provider_Profiles::META_SERVICE_MODES, true)),
        'service_area' => class_exists(Service_Areas::class) ? Service_Areas::public_area($provider_id) : null,
        'locations' => $location ? [$location] : [],
        'reviews' => Provider_Reviews::summary($provider_id),
        'category' => $categories[0] ?? null,
        'categories' => $categories,
        'service_count' => (int) $row['service_count'],
        'min_price' => isset($row['min_price']) ? (float) $row['min_price'] : null,
        'max_price' => isset($row['max_price']) ? (float) $row['max_price'] : null,
        'currency' => (string) ($row['currency'] ?: 'USD'),
      ];
    }, $rows);

    return new \WP_REST_Response([
      'items' => $items,
      'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
        'has_more' => ($offset + count($items)) < $total,
      ],
    ], 200);
  }

  private static function empty_provider_discovery(int $page, int $per_page): \WP_REST_Response {
    return new \WP_REST_Response([
      'items' => [],
      'pagination' => ['page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0, 'has_more' => false],
    ], 200);
  }

  public static function queue_service_sync(int $service_id): void {
    self::queue_entity_sync(self::SYNC_SERVICE_HOOK, $service_id);
  }

  public static function queue_listing_sync(int $listing_id): void {
    self::queue_entity_sync(self::SYNC_LISTING_HOOK, $listing_id);
  }

  public static function get_bookable_listings(\WP_REST_Request $req): \WP_REST_Response {
    self::maybe_seed_index();

    global $wpdb;
    $listing_table = DB::listing_index_table();
    $service_table = DB::service_index_table();

    $page = max(1, absint($req->get_param('page')));
    $per_page = min(24, max(1, absint($req->get_param('per_page')) ?: 12));
    $offset = ($page - 1) * $per_page;
    $search = sanitize_text_field((string) $req->get_param('search'));

    $where = 'li.enabled = 1 AND li.service_count > 0';
    $params = [];
    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $where .= ' AND (p.post_title LIKE %s OR EXISTS (SELECT 1 FROM ' . DB::service_index_table() . ' si WHERE si.listing_id = li.listing_id AND si.status != "inactive" AND si.is_addon = 0 AND (si.title LIKE %s OR si.description LIKE %s)))';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }

    $count_sql = "SELECT COUNT(*)
      FROM {$listing_table} li
      INNER JOIN {$wpdb->posts} p ON p.ID = li.listing_id
      WHERE {$where} AND p.post_status = 'publish'";
    $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

    $items_sql = "SELECT li.*
      FROM {$listing_table} li
      INNER JOIN {$wpdb->posts} p ON p.ID = li.listing_id
      WHERE {$where} AND p.post_status = 'publish'
      ORDER BY li.sort_score DESC, li.updated_at DESC
      LIMIT %d OFFSET %d";
    $item_params = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($items_sql, $item_params), ARRAY_A) ?: [];

    $listing_ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'listing_id'))));
    $services_by_listing = [];
    if ($listing_ids) {
      $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
      $service_sql = $wpdb->prepare(
        "SELECT * FROM {$service_table}
         WHERE listing_id IN ({$placeholders})
           AND status != 'inactive'
           AND is_addon = 0
           AND wc_product_id IS NOT NULL
           AND wc_product_id > 0
         ORDER BY sort_order ASC, title ASC",
        $listing_ids
      );
      $service_rows = $wpdb->get_results($service_sql, ARRAY_A) ?: [];
      $product_ids = array_values(array_filter(array_map('absint', wp_list_pluck($service_rows, 'wc_product_id'))));
      if ($product_ids) {
        update_meta_cache('post', $product_ids);
      }
      foreach ($service_rows as $service_row) {
        $listing_id = (int) ($service_row['listing_id'] ?? 0);
        if (!isset($services_by_listing[$listing_id])) {
          $services_by_listing[$listing_id] = [];
        }
        $services_by_listing[$listing_id][] = self::format_service_row($service_row);
      }
    }

    $items = [];
    foreach ($rows as $row) {
      $listing_id = (int) ($row['listing_id'] ?? 0);
      $services = $services_by_listing[$listing_id] ?? [];
      if (!$listing_id || !$services) {
        continue;
      }
      $items[] = self::format_listing_row($row, $services);
    }

    return new \WP_REST_Response([
      'items' => $items,
      'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
        'has_more' => ($offset + $per_page) < $total,
      ],
    ], 200);
  }

  public static function sync_service(int $service_id): void {
    if (self::$syncing || wp_is_post_revision($service_id)) {
      return;
    }

    $service_id = absint($service_id);
    if (!$service_id) {
      return;
    }

    self::$syncing = true;
    try {
      $post = get_post($service_id);
      $listing_id = self::service_listing_id($service_id);
      $provider_id = (int) get_post_meta($service_id, Services_API::META_PROVIDER_ID, true);
      $resource = Resources::for_service($service_id);
      if (!$post || $post->post_type !== Services_CPT::POST_TYPE || $post->post_status !== 'publish' || !$resource) {
        self::delete_service_index($service_id);
        if ($listing_id) {
          self::sync_listing($listing_id);
        }
        return;
      }

      $price = self::service_price($service_id);
      $duration = self::service_duration($service_id);
      $status = self::service_status($service_id);
      $wc_product_id = (int) get_post_meta($service_id, '_koopo_wc_product_id', true);
      $category_ids = self::service_category_ids($service_id);
      $now = current_time('mysql');

      global $wpdb;
      $wpdb->replace(
        DB::service_index_table(),
        [
          'service_id' => $service_id,
          'listing_id' => $listing_id ?: null,
          'provider_id' => $provider_id ?: null,
          'resource_id' => (int) $resource->id,
          'vendor_id' => (int) $post->post_author,
          'wc_product_id' => $wc_product_id ?: null,
          'title' => get_the_title($service_id),
          'description' => (string) get_post_meta($service_id, Services_API::META_DESC, true),
          'price' => $price,
          'currency' => 'USD',
          'duration_minutes' => $duration ?: 30,
          'status' => $status,
          'is_addon' => get_post_meta($service_id, Services_API::META_ADDON, true) === '1' ? 1 : 0,
          'instant' => get_post_meta($service_id, Services_API::META_INSTANT, true) === '0' ? 0 : 1,
          'color' => (string) get_post_meta($service_id, Services_API::META_COLOR, true),
          'category_ids' => wp_json_encode($category_ids),
          'sort_order' => (int) $post->menu_order,
          'updated_at' => $now,
        ],
        ['%d','%d','%d','%d','%d','%d','%s','%s','%f','%s','%d','%s','%d','%d','%s','%s','%d','%s']
      );

      if ($listing_id) self::sync_listing($listing_id);
    } finally {
      self::$syncing = false;
    }
  }

  public static function sync_listing(int $listing_id): void {
    $listing_id = absint($listing_id);
    if (!$listing_id) {
      return;
    }

    $post = get_post($listing_id);
    if (!$post || $post->post_type !== 'gd_place') {
      self::delete_listing_index($listing_id);
      return;
    }

    global $wpdb;
    $service_table = DB::service_index_table();
    $stats = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT COUNT(*) AS service_count,
                MIN(price) AS min_price,
                MAX(price) AS max_price,
                MAX(updated_at) AS last_service_updated_at
         FROM {$service_table}
         WHERE listing_id = %d
           AND status != 'inactive'
           AND is_addon = 0
           AND wc_product_id IS NOT NULL
           AND wc_product_id > 0",
        $listing_id
      ),
      ARRAY_A
    ) ?: [];

    $service_count = (int) ($stats['service_count'] ?? 0);
    $enabled = get_post_meta($listing_id, '_koopo_appt_enabled', true) === '1' ? 1 : 0;
    $last_booking_at = self::last_booking_at($listing_id);
    $sort_score = self::calculate_sort_score($service_count, $last_booking_at, (string) ($stats['last_service_updated_at'] ?? ''));
    $now = current_time('mysql');

    $wpdb->replace(
      DB::listing_index_table(),
      [
        'listing_id' => $listing_id,
        'listing_author_id' => (int) $post->post_author,
        'enabled' => $enabled,
        'status' => $post->post_status === 'publish' ? 'active' : $post->post_status,
        'service_count' => $service_count,
        'min_price' => $service_count > 0 ? (float) ($stats['min_price'] ?? 0) : null,
        'max_price' => $service_count > 0 ? (float) ($stats['max_price'] ?? 0) : null,
        'currency' => 'USD',
        'next_available_at' => null,
        'last_service_updated_at' => !empty($stats['last_service_updated_at']) ? $stats['last_service_updated_at'] : null,
        'last_booking_at' => $last_booking_at ?: null,
        'sort_score' => $sort_score,
        'updated_at' => $now,
      ],
      ['%d','%d','%d','%s','%d','%f','%f','%s','%s','%s','%s','%f','%s']
    );
  }

  public static function handle_post_removed(int $post_id): void {
    $post = get_post($post_id);
    if (!$post) {
      return;
    }
    if ($post->post_type === Services_CPT::POST_TYPE) {
      $listing_id = self::service_listing_id($post_id);
      self::delete_service_index($post_id);
      if ($listing_id) {
        self::sync_listing($listing_id);
      }
    } elseif ($post->post_type === 'gd_place') {
      self::delete_listing_index($post_id);
    } elseif ($post->post_type === Provider_Profiles::POST_TYPE) {
      global $wpdb;
      $wpdb->delete(DB::service_index_table(), ['provider_id' => $post_id], ['%d']);
    }
  }

  public static function handle_post_restored(int $post_id): void {
    $post = get_post($post_id);
    if (!$post) {
      return;
    }
    if ($post->post_type === Services_CPT::POST_TYPE) {
      self::queue_service_sync($post_id);
    } elseif ($post->post_type === 'gd_place') {
      self::queue_listing_sync($post_id);
    }
  }

  public static function handle_post_meta_change($meta_id, $object_id, $meta_key, $_meta_value): void {
    $key = (string) $meta_key;
    if ($key === '_koopo_appt_enabled') {
      if (get_post_type((int) $object_id) === 'gd_place') self::queue_listing_sync((int) $object_id);
      return;
    }

    $service_keys = [
      Services_API::META_PRICE,
      Services_API::META_DURATION,
      Services_API::META_LISTING_ID,
      Services_API::META_PROVIDER_ID,
      Resources::META_RESOURCE_ID,
      Services_API::META_DESC,
      Services_API::META_COLOR,
      Services_API::META_STATUS,
      Services_API::META_BUF_BEFORE,
      Services_API::META_BUF_AFTER,
      Services_API::META_INSTANT,
      Services_API::META_ADDON,
      '_koopo_price',
      '_koopo_duration_minutes',
      '_koopo_listing_id',
      '_koopo_wc_product_id',
    ];

    if (in_array($key, $service_keys, true)) {
      if (get_post_type((int) $object_id) === Services_CPT::POST_TYPE) self::queue_service_sync((int) $object_id);
    }
  }

  public static function handle_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    if ($taxonomy === Service_Categories::TAXONOMY) {
      if (get_post_type((int) $object_id) === Services_CPT::POST_TYPE) self::queue_service_sync((int) $object_id);
    }
  }

  public static function rebuild_index(int $limit = 1000): void {
    $q = new \WP_Query([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'fields' => 'ids',
      'orderby' => 'modified',
      'order' => 'DESC',
    ]);

    foreach ($q->posts as $service_id) {
      self::sync_service((int) $service_id);
    }
  }

  public static function run_rebuild_batch(int $page = 1): void {
    $batch_size = 200;
    $q = new \WP_Query([
      'post_type' => Services_CPT::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $batch_size,
      'paged' => max(1, $page),
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    foreach ($q->posts as $service_id) self::sync_service((int) $service_id);
    if (count($q->posts) === $batch_size) {
      self::schedule_rebuild(max(1, $page) + 1);
    } else {
      update_option('koopo_appt_bookable_index_version', DB::VERSION, false);
    }
  }

  /** Public place cards for the unified Professionals booking archive. */
  public static function archive_places(string $search = '', string $service_category = '', int $limit = 50): array {
    self::maybe_seed_index();
    global $wpdb;
    $where = "li.enabled = 1 AND li.service_count > 0 AND p.post_status = 'publish'";
    $params = [];
    if ($search !== '') {
      $where .= ' AND p.post_title LIKE %s';
      $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $sql = "SELECT li.* FROM " . DB::listing_index_table() . " li INNER JOIN {$wpdb->posts} p ON p.ID = li.listing_id WHERE {$where} ORDER BY li.sort_score DESC, li.updated_at DESC LIMIT %d";
    $params[] = min(100, max(1, $limit));
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    $listing_ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'listing_id'))));
    if ($listing_ids) {
      update_meta_cache('post', $listing_ids);
      update_object_term_cache($listing_ids, 'gd_place');
    }
    $services_by_listing = self::indexed_services_for_listings($listing_ids);
    $items = [];
    foreach ($rows as $row) {
      $listing_id = (int) $row['listing_id'];
      $services = $services_by_listing[$listing_id] ?? [];
      if (!$services) continue;
      $terms = wp_get_object_terms($listing_id, 'gd_placecategory');
      if (is_wp_error($terms)) $terms = [];
      $categories = array_map(static fn($term): array => ['id'=>(int)$term->term_id,'name'=>(string)$term->name,'slug'=>(string)$term->slug,'glyph'=>''], $terms);
      if ($service_category !== '' && !self::place_category_matches($categories, $service_category)) continue;
      $location = self::listing_coordinates($listing_id);
      $items[] = [
        'entity_type' => 'place',
        'id' => $listing_id,
        'listing_id' => $listing_id,
        'name' => get_the_title($listing_id),
        'headline' => wp_strip_all_tags((string) get_post_field('post_excerpt', $listing_id)),
        'image_url' => self::listing_image_url($listing_id),
        'permalink' => get_permalink($listing_id),
        'service_modes' => ['at_location'],
        'service_area' => null,
        'locations' => $location ? [$location] : [],
        'reviews' => ['average'=>self::listing_rating($listing_id),'count'=>self::listing_rating_count($listing_id)],
        'categories' => $categories,
        'all_services' => $services,
        'services' => array_slice($services, 0, 3),
      ];
    }
    return $items;
  }

  private static function indexed_services_for_listing(int $listing_id): array {
    $grouped = self::indexed_services_for_listings([$listing_id]);
    return $grouped[$listing_id] ?? [];
  }

  private static function indexed_services_for_listings(array $listing_ids): array {
    global $wpdb;
    $listing_ids = array_values(array_filter(array_map('absint', $listing_ids)));
    if (!$listing_ids) return [];
    $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . DB::service_index_table() . " WHERE listing_id IN ({$placeholders}) AND status != 'inactive' AND is_addon = 0 AND wc_product_id IS NOT NULL AND wc_product_id > 0 ORDER BY listing_id, sort_order ASC, title ASC",
      $listing_ids
    ), ARRAY_A) ?: [];
    $product_ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'wc_product_id'))));
    if ($product_ids) update_meta_cache('post', $product_ids);
    $grouped = [];
    foreach ($rows as $row) $grouped[(int) $row['listing_id']][] = self::format_service_row($row);
    return $grouped;
  }

  private static function place_category_matches(array $categories, string $service_category): bool {
    $needle = sanitize_title($service_category);
    $service_term = get_term_by('slug', $needle, Service_Categories::TAXONOMY);
    $names = array_filter([$needle, $service_term && !is_wp_error($service_term) ? sanitize_title($service_term->name) : '']);
    foreach ($categories as $category) {
      if (in_array(sanitize_title((string) ($category['slug'] ?? '')), $names, true) || in_array(sanitize_title((string) ($category['name'] ?? '')), $names, true)) return true;
    }
    return (bool) apply_filters('koopo_appt_place_matches_service_category', false, $categories, $service_category);
  }

  private static function listing_coordinates(int $listing_id): ?array {
    $details = function_exists('geodir_get_post_info') ? geodir_get_post_info($listing_id) : null;
    $read = static function(array $keys) use ($details, $listing_id) {
      foreach ($keys as $key) {
        if (is_object($details) && isset($details->{$key}) && $details->{$key} !== '') return $details->{$key};
        $value = get_post_meta($listing_id, $key, true);
        if ($value !== '') return $value;
      }
      return '';
    };
    $latitude = (float) $read(['latitude','_latitude','lat']);
    $longitude = (float) $read(['longitude','_longitude','lng','lon']);
    $address = self::listing_address($listing_id);
    if (!$latitude && !$longitude && $address === '') return null;
    return [
      'listing_id'=>$listing_id,
      'name'=>get_the_title($listing_id),
      'url'=>get_permalink($listing_id),
      'relationship_type'=>'business_location',
      'address'=>$address,
      'latitude'=>$latitude,
      'longitude'=>$longitude,
      'source'=>'geodirectory',
    ];
  }

  private static function maybe_seed_index(): void {
    if ((string) get_option('koopo_appt_bookable_index_version', '') === DB::VERSION) return;
    global $wpdb;
    $service_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DB::service_index_table());
    $listing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DB::listing_index_table());
    $provider_service_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DB::service_index_table() . ' WHERE provider_id IS NOT NULL AND provider_id > 0');
    if ($service_count > 0 && ($listing_count > 0 || $provider_service_count > 0)) {
      update_option('koopo_appt_bookable_index_version', DB::VERSION, false);
      return;
    }
    self::schedule_rebuild(1);
  }

  private static function schedule_rebuild(int $page): void {
    $args = [$page];
    if (function_exists('as_enqueue_async_action')) {
      if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action(self::REBUILD_HOOK, $args, 'koopo-appointments-index')) {
        as_enqueue_async_action(self::REBUILD_HOOK, $args, 'koopo-appointments-index', true);
      }
      return;
    }
    if (!wp_next_scheduled(self::REBUILD_HOOK, $args)) wp_schedule_single_event(time() + 5, self::REBUILD_HOOK, $args);
  }

  private static function queue_entity_sync(string $hook, int $object_id): void {
    if (self::$syncing) return;
    $object_id = absint($object_id);
    if (!$object_id) return;
    $key = $hook . ':' . $object_id;
    if (isset(self::$queued[$key])) return;
    self::$queued[$key] = true;
    $args = [$object_id];
    if (function_exists('as_schedule_single_action')) {
      if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action($hook, $args, 'koopo-appointments-index')) {
        as_schedule_single_action(time() + 2, $hook, $args, 'koopo-appointments-index', true);
      }
      return;
    }
    if (!wp_next_scheduled($hook, $args)) wp_schedule_single_event(time() + 2, $hook, $args);
  }

  private static function delete_service_index(int $service_id): void {
    global $wpdb;
    $wpdb->delete(DB::service_index_table(), ['service_id' => absint($service_id)], ['%d']);
  }

  private static function delete_listing_index(int $listing_id): void {
    global $wpdb;
    $wpdb->delete(DB::listing_index_table(), ['listing_id' => absint($listing_id)], ['%d']);
  }

  private static function service_listing_id(int $service_id): int {
    $listing_id = (int) get_post_meta($service_id, Services_API::META_LISTING_ID, true);
    if (!$listing_id) {
      $listing_id = (int) get_post_meta($service_id, '_koopo_listing_id', true);
    }
    return $listing_id;
  }

  private static function service_price(int $service_id): float {
    $price = get_post_meta($service_id, Services_API::META_PRICE, true);
    if ($price === '' || $price === null) {
      $price = get_post_meta($service_id, '_koopo_price', true);
    }
    return is_numeric($price) ? (float) $price : 0.0;
  }

  private static function service_duration(int $service_id): int {
    $duration = get_post_meta($service_id, Services_API::META_DURATION, true);
    if ($duration === '' || $duration === null) {
      $duration = get_post_meta($service_id, '_koopo_duration_minutes', true);
    }
    return max(0, (int) $duration);
  }

  private static function service_status(int $service_id): string {
    $status = (string) get_post_meta($service_id, Services_API::META_STATUS, true);
    return $status !== '' ? $status : 'active';
  }

  private static function service_category_ids(int $service_id): array {
    $provider_id = (int) get_post_meta($service_id, Services_API::META_PROVIDER_ID, true);
    return $provider_id ? array_map(static fn($category) => (int) $category['id'], Provider_Profiles::categories($provider_id)) : [];
  }

  private static function last_booking_at(int $listing_id): string {
    global $wpdb;
    $table = DB::table();
    return (string) $wpdb->get_var($wpdb->prepare(
      "SELECT MAX(created_at) FROM {$table} WHERE listing_id = %d",
      $listing_id
    ));
  }

  private static function calculate_sort_score(int $service_count, string $last_booking_at, string $last_service_updated_at): float {
    $score = $service_count * 10;
    $timestamp = strtotime($last_booking_at ?: $last_service_updated_at);
    if ($timestamp) {
      $age_days = max(0, (time() - $timestamp) / DAY_IN_SECONDS);
      $score += max(0, 30 - min(30, $age_days));
    }
    return (float) $score;
  }

  private static function format_service_row(array $row): array {
    $product_id = (int) ($row['wc_product_id'] ?? 0);
    $category_ids = [];
    if (!empty($row['category_ids'])) {
      $decoded = json_decode((string) $row['category_ids'], true);
      if (is_array($decoded)) {
        $category_ids = array_values(array_filter(array_map('absint', $decoded)));
      }
    }

    return [
      'id' => (int) $row['service_id'],
      'title' => (string) $row['title'],
      'description' => (string) ($row['description'] ?? ''),
      'price' => (float) $row['price'],
      'price_label' => '',
      'currency' => (string) ($row['currency'] ?? 'USD'),
      'duration_minutes' => (int) $row['duration_minutes'],
      'status' => (string) ($row['status'] ?? 'active'),
      'instant' => !empty($row['instant']),
      'color' => (string) ($row['color'] ?? ''),
      'category_ids' => $category_ids,
      'wc_product_id' => $product_id,
      'tax_status' => $product_id ? (string) (get_post_meta($product_id, '_tax_status', true) ?: 'none') : 'none',
      'virtual' => $product_id ? get_post_meta($product_id, '_virtual', true) === 'yes' : true,
    ];
  }

  private static function format_listing_row(array $row, array $services): array {
    $listing_id = (int) $row['listing_id'];
    $image_url = self::listing_image_url($listing_id);
    return [
      'listing_id' => $listing_id,
      'place_id' => $listing_id,
      'vendor_id' => (int) ($row['listing_author_id'] ?? 0),
      'name' => get_the_title($listing_id),
      'permalink' => get_permalink($listing_id),
      'excerpt' => wp_strip_all_tags((string) get_post_field('post_excerpt', $listing_id)),
      'image_url' => $image_url,
      'image_urls' => $image_url ? [$image_url] : [],
      'address' => self::listing_address($listing_id),
      'rating' => self::listing_rating($listing_id),
      'rating_count' => self::listing_rating_count($listing_id),
      'service_count' => (int) $row['service_count'],
      'min_price' => isset($row['min_price']) ? (float) $row['min_price'] : null,
      'max_price' => isset($row['max_price']) ? (float) $row['max_price'] : null,
      'currency' => (string) ($row['currency'] ?? 'USD'),
      'services' => $services,
    ];
  }

  private static function listing_image_url(int $listing_id): string {
    $thumb = get_the_post_thumbnail_url($listing_id, 'large');
    return is_string($thumb) ? $thumb : '';
  }

  private static function listing_address(int $listing_id): string {
    $parts = [];
    foreach (['street', 'gd_street', 'city', 'gd_city', 'region', 'gd_region', 'country', 'gd_country'] as $key) {
      $value = get_post_meta($listing_id, $key, true);
      if (is_string($value) && $value !== '') {
        $parts[] = $value;
      }
    }
    return implode(', ', array_values(array_unique($parts)));
  }

  private static function listing_rating(int $listing_id): float {
    if (function_exists('geodir_get_post_rating')) {
      return (float) geodir_get_post_rating($listing_id);
    }
    global $wpdb;
    foreach (['overall_rating', 'rating', 'geodir_overallrating'] as $key) {
      $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $listing_id,
        $key
      ));
      if (is_numeric($value)) {
        return (float) $value;
      }
    }
    return 0.0;
  }

  private static function listing_rating_count(int $listing_id): int {
    if (function_exists('geodir_get_review_count_total')) {
      return (int) geodir_get_review_count_total($listing_id);
    }
    global $wpdb;
    foreach (['rating_count', 'review_count', 'geodir_review_count'] as $key) {
      $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $listing_id,
        $key
      ));
      if (is_numeric($value)) {
        return (int) $value;
      }
    }
    return 0;
  }
}
