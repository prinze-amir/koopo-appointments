<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * A resource is the independently conflict-checked calendar behind a place or
 * professional. GeoDirectory remains a supported subject, not the scheduler.
 */
final class Resources {
  const META_RESOURCE_ID = '_koopo_resource_id';

  public static function ensure(string $subject_type, int $subject_id, int $owner_user_id = 0, int $payee_user_id = 0): int {
    global $wpdb;
    $subject_type = in_array($subject_type, ['listing', 'provider'], true) ? $subject_type : '';
    if (!$subject_type || !$subject_id) return 0;

    $existing = (int) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . DB::resources_table() . ' WHERE subject_type = %s AND subject_id = %d',
      $subject_type,
      $subject_id
    ));
    if ($existing) return $existing;

    $post = get_post($subject_id);
    if (!$post) return 0;
    $owner_user_id = $owner_user_id ?: (int) $post->post_author;
    $payee_user_id = $payee_user_id ?: $owner_user_id;
    if (!$owner_user_id || !$payee_user_id) return 0;

    $wpdb->insert(DB::resources_table(), [
      'owner_user_id' => $owner_user_id,
      'payee_user_id' => $payee_user_id,
      'subject_type' => $subject_type,
      'subject_id' => $subject_id,
      'status' => 'active',
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ], ['%d','%d','%s','%d','%s','%s','%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
      // A concurrent request may have won the unique subject insert.
      $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . DB::resources_table() . ' WHERE subject_type = %s AND subject_id = %d',
        $subject_type,
        $subject_id
      ));
    }
    if ($id) update_post_meta($subject_id, self::META_RESOURCE_ID, $id);
    return $id;
  }

  public static function ensure_for_listing(int $listing_id): int {
    $listing = get_post($listing_id);
    if (!$listing || $listing->post_type !== 'gd_place') return 0;
    $id = self::ensure('listing', $listing_id, (int) $listing->post_author, (int) $listing->post_author);
    if ($id) {
      global $wpdb;
      $wpdb->query($wpdb->prepare(
        'UPDATE ' . DB::table() . ' SET resource_id = %d, payee_user_id = listing_author_id WHERE listing_id = %d AND (resource_id IS NULL OR resource_id = 0)',
        $id,
        $listing_id
      ));
    }
    return $id;
  }

  public static function ensure_for_provider(int $provider_id): int {
    $provider = get_post($provider_id);
    if (!$provider || $provider->post_type !== Provider_Profiles::POST_TYPE) return 0;
    return self::ensure('provider', $provider_id, (int) $provider->post_author, (int) $provider->post_author);
  }

  public static function get(int $resource_id): ?object {
    global $wpdb;
    if (!$resource_id) return null;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::resources_table() . ' WHERE id = %d', $resource_id));
    return $row ?: null;
  }

  public static function for_subject(string $subject_type, int $subject_id): ?object {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . DB::resources_table() . ' WHERE subject_type = %s AND subject_id = %d',
      $subject_type,
      $subject_id
    ));
    return $row ?: null;
  }

  public static function for_service(int $service_id): ?object {
    $resource_id = (int) get_post_meta($service_id, self::META_RESOURCE_ID, true);
    if ($resource_id) {
      $resource = self::get($resource_id);
      if ($resource) return $resource;
    }

    $provider_id = (int) get_post_meta($service_id, Services_API::META_PROVIDER_ID, true);
    if ($provider_id) {
      $resource_id = self::ensure_for_provider($provider_id);
    } else {
      $listing_id = (int) get_post_meta($service_id, Services_API::META_LISTING_ID, true);
      $resource_id = $listing_id ? self::ensure_for_listing($listing_id) : 0;
    }
    if (!$resource_id) return null;
    update_post_meta($service_id, self::META_RESOURCE_ID, $resource_id);
    return self::get($resource_id);
  }

  public static function can_manage(int $resource_id, int $user_id = 0): bool {
    $resource = self::get($resource_id);
    if (!$resource) return false;
    $user_id = $user_id ?: get_current_user_id();
    return Access::is_admin_bypass($user_id) || (int) $resource->owner_user_id === $user_id;
  }

  public static function settings_post_id(int $resource_id): int {
    $resource = self::get($resource_id);
    return $resource ? (int) $resource->subject_id : 0;
  }

  public static function booking_resource_id($booking): int {
    $resource_id = (int) ($booking->resource_id ?? 0);
    if ($resource_id) return $resource_id;
    $listing_id = (int) ($booking->listing_id ?? 0);
    if ($listing_id) return self::ensure_for_listing($listing_id);
    $provider_id = (int) ($booking->provider_id ?? 0);
    return $provider_id ? self::ensure_for_provider($provider_id) : 0;
  }

  public static function contexts_for_user(int $user_id): array {
    global $wpdb;
    if (!$user_id) return [];
    $page = 1;
    $batch_size = 200;
    do {
      $subjects = get_posts([
        'post_type' => ['gd_place', Provider_Profiles::POST_TYPE],
        'post_status' => 'publish',
        'author' => $user_id,
        'posts_per_page' => $batch_size,
        'paged' => $page,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
      ]);
      foreach ($subjects as $subject) {
        self::ensure($subject->post_type === Provider_Profiles::POST_TYPE ? 'provider' : 'listing', (int) $subject->ID, $user_id, $user_id);
      }
      $page++;
    } while (count($subjects) === $batch_size);
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . DB::resources_table() . " WHERE owner_user_id = %d AND status = 'active' ORDER BY subject_type, id",
      $user_id
    )) ?: [];
    $out = [];
    foreach ($rows as $row) {
      $post = get_post((int) $row->subject_id);
      if (!$post || $post->post_status !== 'publish') continue;
      $out[] = [
        'resource_id' => (int) $row->id,
        'subject_type' => (string) $row->subject_type,
        'subject_id' => (int) $row->subject_id,
        'listing_id' => $row->subject_type === 'listing' ? (int) $row->subject_id : 0,
        'provider_id' => $row->subject_type === 'provider' ? (int) $row->subject_id : 0,
        'title' => get_the_title((int) $row->subject_id),
        'permalink' => get_permalink((int) $row->subject_id),
        'payee_user_id' => (int) $row->payee_user_id,
      ];
    }
    return $out;
  }
}
