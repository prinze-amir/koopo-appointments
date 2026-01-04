<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Settings_API {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {

    // Get listing booking settings (public-safe subset)
    register_rest_route('koopo/v1', '/appointments/settings/(?P<listing_id>\d+)', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_settings'],
      'permission_callback' => '__return_true',
    ]);

    // Update listing booking settings (vendor only)
    register_rest_route('koopo/v1', '/appointments/settings/(?P<listing_id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'update_settings'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  private static function is_listing_owner(int $listing_id): bool {
    $listing = get_post($listing_id);
    if (!$listing || $listing->post_type !== 'gd_place') return false;
    return (int)$listing->post_author === get_current_user_id();
  }

  public static function get_settings(\WP_REST_Request $req) {
    $listing_id = absint($req['listing_id']);
    $listing = get_post($listing_id);
    if (!$listing || $listing->post_type !== 'gd_place') {
      return new \WP_REST_Response(['error' => 'Invalid listing'], 404);
    }

    return new \WP_REST_Response(self::read_settings($listing_id), 200);
  }

  public static function update_settings(\WP_REST_Request $req) {
    $listing_id = absint($req['listing_id']);
    if (!self::is_listing_owner($listing_id)) {
      return new \WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    $payload = $req->get_json_params();
    if (!is_array($payload)) $payload = [];

    $enabled = !empty($payload['enabled']) ? '1' : '0';
    update_post_meta($listing_id, '_koopo_appt_enabled', $enabled);

    $tz = isset($payload['timezone']) ? sanitize_text_field($payload['timezone']) : '';
    if ($tz) update_post_meta($listing_id, '_koopo_appt_timezone', $tz);

    // Hours & breaks stored as JSON
    if (isset($payload['hours']) && is_array($payload['hours'])) {
      update_post_meta($listing_id, '_koopo_appt_hours', wp_json_encode(self::sanitize_hours($payload['hours'])));
    }
    if (isset($payload['breaks']) && is_array($payload['breaks'])) {
      update_post_meta($listing_id, '_koopo_appt_breaks', wp_json_encode(self::sanitize_hours($payload['breaks'])));
    }

    // Slot interval + buffers
    if (isset($payload['slot_interval'])) {
      update_post_meta($listing_id, '_koopo_appt_slot_interval', (int)$payload['slot_interval']);
    }
    if (isset($payload['buffer_before'])) {
      update_post_meta($listing_id, '_koopo_appt_buffer_before', (int)$payload['buffer_before']);
    }
    if (isset($payload['buffer_after'])) {
      update_post_meta($listing_id, '_koopo_appt_buffer_after', (int)$payload['buffer_after']);
    }

    // Days off
    if (isset($payload['days_off']) && is_array($payload['days_off'])) {
      $days = array_values(array_filter(array_map('sanitize_text_field', $payload['days_off'])));
      update_post_meta($listing_id, '_koopo_appt_days_off', wp_json_encode($days));
    }

    return new \WP_REST_Response(self::read_settings($listing_id), 200);
  }

  public static function read_settings(int $listing_id): array {
    $enabled = get_post_meta($listing_id, '_koopo_appt_enabled', true);
    $tz      = get_post_meta($listing_id, '_koopo_appt_timezone', true);
    if (!$tz) $tz = 'America/Detroit'; // default; you can change

    $hours_json  = get_post_meta($listing_id, '_koopo_appt_hours', true);
    $breaks_json = get_post_meta($listing_id, '_koopo_appt_breaks', true);
    $days_off_json = get_post_meta($listing_id, '_koopo_appt_days_off', true);

    $slot_interval = (int) get_post_meta($listing_id, '_koopo_appt_slot_interval', true);
    $buffer_before = (int) get_post_meta($listing_id, '_koopo_appt_buffer_before', true);
    $buffer_after  = (int) get_post_meta($listing_id, '_koopo_appt_buffer_after', true);

    return [
      'listing_id' => $listing_id,
      'enabled' => ($enabled === '1'),
      'timezone' => $tz,
      'hours' => self::decode_json_obj($hours_json) ?: self::default_hours(),
      'breaks' => self::decode_json_obj($breaks_json) ?: self::default_breaks(),
      'slot_interval' => $slot_interval ?: 0,
      'buffer_before' => $buffer_before ?: 0,
      'buffer_after' => $buffer_after ?: 0,
      'days_off' => self::decode_json_arr($days_off_json) ?: [],
    ];
  }

  private static function decode_json_obj($json) {
    if (!$json) return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
  }
  private static function decode_json_arr($json) {
    if (!$json) return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
  }

  private static function default_hours(): array {
    return [
      'mon' => [['09:00','17:00']],
      'tue' => [['09:00','17:00']],
      'wed' => [['09:00','17:00']],
      'thu' => [['09:00','17:00']],
      'fri' => [['09:00','17:00']],
      'sat' => [],
      'sun' => [],
    ];
  }
  private static function default_breaks(): array {
    return [];
  }

  private static function to_minutes($hhmm) {
  [$h,$m] = array_map('intval', explode(':', $hhmm));
  return $h*60 + $m;
}

  private static function sanitize_hours(array $input): array {
    // Expect: day => [[HH:MM, HH:MM], ...]
    $days = ['mon','tue','wed','thu','fri','sat','sun'];
    $out = [];

    foreach ($days as $day) {
      $ranges = isset($input[$day]) && is_array($input[$day]) ? $input[$day] : [];
      $out[$day] = [];
      foreach ($ranges as $r) {
        if (!is_array($r) || count($r) < 2) continue;
        $a = preg_replace('/[^0-9:]/', '', (string)$r[0]);
        $b = preg_replace('/[^0-9:]/', '', (string)$r[1]);
        // Validate HH:MM format and ensure start < end.
        if (!preg_match('/^\d{2}:\d{2}$/', $a)) continue;
        if (!preg_match('/^\d{2}:\d{2}$/', $b)) continue;
        if (self::to_minutes($a) >= self::to_minutes($b)) continue;

        $out[$day][] = [$a, $b];
      }
    
      // Sort + de-duplicate + merge overlaps to prevent duplicate/overlapping ranges
      if (!empty($out[$day])) {
        usort($out[$day], function($x, $y) {
          return self::to_minutes($x[0]) <=> self::to_minutes($y[0]);
        });

        $merged = [];
        foreach ($out[$day] as $rng) {
          if (empty($merged)) {
            $merged[] = $rng;
            continue;
          }
          $lastIdx = count($merged) - 1;
          $last = $merged[$lastIdx];

          // Exact duplicate
          if ($last[0] === $rng[0] && $last[1] === $rng[1]) {
            continue;
          }

          $lastStart = self::to_minutes($last[0]);
          $lastEnd   = self::to_minutes($last[1]);
          $curStart  = self::to_minutes($rng[0]);
          $curEnd    = self::to_minutes($rng[1]);

          // Overlap or adjacency -> merge
          if ($curStart <= $lastEnd) {
            $merged[$lastIdx][1] = ($curEnd > $lastEnd) ? $rng[1] : $last[1];
          } else {
            $merged[] = $rng;
          }
        }
        $out[$day] = $merged;
      }
}
    return $out;
  }
}
