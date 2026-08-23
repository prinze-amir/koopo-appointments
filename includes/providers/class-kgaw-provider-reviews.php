<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Moderated service-profile reviews backed by WordPress comments and comment meta. */
final class Provider_Reviews {
  // wp_comments.comment_type is VARCHAR(20).
  const COMMENT_TYPE = 'koopo_provider_rev';
  const META_RATING = '_koopo_provider_rating';
  const PROVIDER_META_AVERAGE = '_koopo_provider_review_average';
  const PROVIDER_META_COUNT = '_koopo_provider_review_count';

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_filter('comments_open', [__CLASS__, 'comments_open'], 10, 2);
    add_filter('manage_edit-comments_columns', [__CLASS__, 'admin_columns']);
    add_action('manage_comments_custom_column', [__CLASS__, 'admin_column'], 10, 2);
    add_action('transition_comment_status', [__CLASS__, 'status_changed'], 10, 3);
    add_action('added_comment_meta', [__CLASS__, 'rating_meta_changed'], 10, 4);
    add_action('updated_comment_meta', [__CLASS__, 'rating_meta_changed'], 10, 4);
    add_action('delete_comment', [__CLASS__, 'comment_deleted'], 10, 2);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/providers/(?P<id>\d+)/reviews', [
      [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'list_reviews'],
        'permission_callback' => '__return_true',
        'args' => [
          'id'=>['type'=>'integer','minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'page'=>['type'=>'integer','default'=>1,'minimum'=>1,'maximum'=>10000,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'per_page'=>['type'=>'integer','default'=>10,'minimum'=>1,'maximum'=>20,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
        ],
      ],
      [
        'methods' => 'POST',
        'callback' => [__CLASS__, 'create_review'],
        'permission_callback' => static fn(): bool => is_user_logged_in(),
        'args' => [
          'id'=>['type'=>'integer','minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'rating'=>['type'=>'integer','required'=>true,'minimum'=>1,'maximum'=>5,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
          'content'=>['type'=>'string','required'=>true,'minLength'=>10,'maxLength'=>3000,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_textarea_field'],
        ],
      ],
    ]);
  }

  public static function comments_open(bool $open, int $post_id): bool {
    return Provider_Profiles::POST_TYPE === get_post_type($post_id) && 'publish' === get_post_status($post_id) ? true : $open;
  }

  public static function summary(int $provider_id): array {
    if (metadata_exists('post', $provider_id, self::PROVIDER_META_COUNT)) {
      return ['average' => (float) get_post_meta($provider_id, self::PROVIDER_META_AVERAGE, true), 'count' => (int) get_post_meta($provider_id, self::PROVIDER_META_COUNT, true)];
    }
    return self::recalculate($provider_id);
  }

  public static function recalculate(int $provider_id): array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT COUNT(*) review_count, AVG(CAST(cm.meta_value AS DECIMAL(10,2))) review_average
       FROM {$wpdb->comments} c
       INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id=c.comment_ID AND cm.meta_key=%s
       WHERE c.comment_post_ID=%d AND c.comment_type=%s AND c.comment_approved='1'
         AND CAST(cm.meta_value AS UNSIGNED) BETWEEN 1 AND 5",
      self::META_RATING,
      $provider_id,
      self::COMMENT_TYPE
    ));
    $summary = [
      'average' => $row && (int) $row->review_count > 0 ? round((float) $row->review_average, 1) : 0.0,
      'count' => $row ? (int) $row->review_count : 0,
    ];
    update_post_meta($provider_id, self::PROVIDER_META_AVERAGE, $summary['average']);
    update_post_meta($provider_id, self::PROVIDER_META_COUNT, $summary['count']);
    return $summary;
  }

  public static function status_changed(string $new_status, string $old_status, \WP_Comment $comment): void {
    unset($new_status, $old_status);
    if (self::COMMENT_TYPE === (string) $comment->comment_type) self::recalculate((int) $comment->comment_post_ID);
  }

  public static function rating_meta_changed($meta_id, int $comment_id, string $meta_key, $meta_value): void {
    unset($meta_id, $meta_value);
    $comment = get_comment($comment_id);
    if (self::META_RATING === $meta_key && $comment && self::COMMENT_TYPE === (string) $comment->comment_type) self::recalculate((int) $comment->comment_post_ID);
  }

  public static function comment_deleted(int $comment_id, \WP_Comment $comment): void {
    unset($comment_id);
    if (self::COMMENT_TYPE === (string) $comment->comment_type) {
      delete_post_meta((int) $comment->comment_post_ID, self::PROVIDER_META_COUNT);
      delete_post_meta((int) $comment->comment_post_ID, self::PROVIDER_META_AVERAGE);
    }
  }

  public static function list_reviews(\WP_REST_Request $request): \WP_REST_Response {
    $provider_id = absint($request['id']);
    if (Provider_Profiles::POST_TYPE !== get_post_type($provider_id) || 'publish' !== get_post_status($provider_id)) return new \WP_REST_Response(['error' => 'Service profile not found.'], 404);
    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = min(20, max(1, absint($request->get_param('per_page')) ?: 10));
    $query = new \WP_Comment_Query();
    $comments = $query->query([
      'post_id' => $provider_id,
      'type' => self::COMMENT_TYPE,
      'status' => 'approve',
      'number' => $per_page,
      'offset' => ($page - 1) * $per_page,
      'orderby' => 'comment_date_gmt',
      'order' => 'DESC',
    ]);
    return new \WP_REST_Response([
      'summary' => self::summary($provider_id),
      'reviews' => array_map([__CLASS__, 'format'], $comments),
      'page' => $page,
      'per_page' => $per_page,
    ], 200);
  }

  public static function create_review(\WP_REST_Request $request): \WP_REST_Response {
    $user_id = get_current_user_id();
    if (!$user_id) return new \WP_REST_Response(['error' => 'Sign in to leave a review.'], 401);
    $provider_id = absint($request['id']);
    $provider = get_post($provider_id);
    if (!$provider || Provider_Profiles::POST_TYPE !== $provider->post_type || 'publish' !== $provider->post_status) return new \WP_REST_Response(['error' => 'Service profile not found.'], 404);
    if ((int) $provider->post_author === $user_id && !current_user_can('moderate_comments')) return new \WP_REST_Response(['error' => 'You cannot review your own service profile.'], 403);
    $payload = (array) $request->get_json_params();
    $rating = absint($payload['rating'] ?? 0);
    $content = sanitize_textarea_field((string) ($payload['content'] ?? ''));
    if ($rating < 1 || $rating > 5) return new \WP_REST_Response(['error' => 'Choose a rating from 1 to 5.'], 422);
    if (mb_strlen($content) < 10 || mb_strlen($content) > 3000) return new \WP_REST_Response(['error' => 'Write a review between 10 and 3,000 characters.'], 422);

    global $wpdb;
    $lock_name = 'koopo_review_' . $provider_id . '_' . $user_id;
    $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 2)) === 1;
    if (!$locked) return new \WP_REST_Response(['error'=>'Another review request is being processed. Please try again.','code'=>'review_busy'],409);
    try {
      $eligible_booking_id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . DB::table() . " WHERE provider_id = %d AND customer_id = %d AND status = 'confirmed' AND end_datetime <= %s ORDER BY end_datetime DESC LIMIT 1",
        $provider_id,
        $user_id,
        current_time('mysql')
      ));
      if (!$eligible_booking_id && !current_user_can('moderate_comments')) {
        return new \WP_REST_Response(['error' => 'Reviews are available after a completed appointment with this service provider.'], 403);
      }
      $existing = get_comments(['post_id'=>$provider_id,'type'=>self::COMMENT_TYPE,'user_id'=>$user_id,'status'=>'all','number'=>1,'fields'=>'ids']);
      if ($existing) return new \WP_REST_Response(['error' => 'You have already reviewed this service profile.'], 409);
      $user = get_userdata($user_id);
      $comment_id = wp_new_comment([
        'comment_post_ID' => $provider_id,
        'comment_content' => $content,
        'comment_type' => self::COMMENT_TYPE,
        'user_id' => $user_id,
        'comment_author' => $user ? $user->display_name : '',
        'comment_author_email' => $user ? $user->user_email : '',
        'comment_author_url' => '',
      ], true);
      if (is_wp_error($comment_id)) return new \WP_REST_Response(['error' => $comment_id->get_error_message()], 400);
      if (!$comment_id) return new \WP_REST_Response(['error' => 'The review could not be saved.'], 500);
      update_comment_meta((int) $comment_id, self::META_RATING, $rating);
      update_comment_meta((int) $comment_id, '_koopo_verified_booking_id', $eligible_booking_id);
      $comment = get_comment((int) $comment_id);
      return new \WP_REST_Response([
        'review' => self::format($comment),
        'status' => '1' === (string) $comment->comment_approved ? 'approved' : 'pending',
        'message' => '1' === (string) $comment->comment_approved ? __('Your review is live.', 'koopo-appointments') : __('Thanks. Your review is awaiting moderation.', 'koopo-appointments'),
      ], 201);
    } finally {
      $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }
  }

  public static function format(\WP_Comment $comment): array {
    $user_id = (int) $comment->user_id;
    $avatar_url = $user_id && function_exists('bp_core_fetch_avatar') ? (string) bp_core_fetch_avatar(['item_id'=>$user_id,'type'=>'thumb','html'=>false,'no_grav'=>false]) : '';
    if (!$avatar_url) $avatar_url = (string) get_avatar_url($comment, ['size' => 96]);
    return [
      'id' => (int) $comment->comment_ID,
      'rating' => (int) get_comment_meta($comment->comment_ID, self::META_RATING, true),
      'content' => (string) $comment->comment_content,
      'author' => (string) $comment->comment_author,
      'avatar_url' => $avatar_url,
      'profile_url' => $user_id && function_exists('bp_core_get_user_domain') ? (string) bp_core_get_user_domain($user_id) : '',
      'date' => (string) get_comment_date('c', $comment),
      'verified' => (int) get_comment_meta($comment->comment_ID, '_koopo_verified_booking_id', true) > 0,
    ];
  }

  public static function admin_columns(array $columns): array {
    $columns['koopo_provider_rating'] = __('Provider rating', 'koopo-appointments');
    return $columns;
  }

  public static function admin_column(string $column, int $comment_id): void {
    if ('koopo_provider_rating' !== $column || self::COMMENT_TYPE !== (string) get_comment_type($comment_id)) return;
    $rating = (int) get_comment_meta($comment_id, self::META_RATING, true);
    echo esc_html($rating ? str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) : '—');
  }
}
