<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Availability {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/availability/by-service/(?P<id>\d+)', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_slots'],
      'permission_callback' => '__return_true',
    ]);
  }

  /**
   * MVP slot generator:
   * - Uses service duration
   * - Uses listing/vendor hours (defaults 9–5 local)
   * - Excludes overlaps with confirmed bookings (and optionally pending)
   */
  public static function get_slots(\WP_REST_Request $req) {
    $service_id = absint($req['id']);
    $date = sanitize_text_field((string)$req->get_param('date')); // YYYY-MM-DD

    if (!$service_id || !$date) {
      return new \WP_REST_Response(['error' => 'Missing service or date'], 400);
    }

    $service = get_post($service_id);
    if (!$service || $service->post_type !== Services_CPT::POST_TYPE) {
      return new \WP_REST_Response(['error' => 'Invalid service'], 404);
    }

    $duration = (int) get_post_meta($service_id, '_koopo_duration_minutes', true);
    if (!$duration) $duration = 30;
    $duration_override = absint($req->get_param('duration_minutes'));
    if ($duration_override > 0) {
      $duration = $duration_override;
    }

    $listing_id = (int) get_post_meta($service_id, '_koopo_listing_id', true);
    if (!$listing_id) return new \WP_REST_Response(['error' => 'Service missing listing_id'], 400);

    $settings = Settings_API::read_settings($listing_id);
    if (empty($settings['enabled'])) {
    return new \WP_REST_Response(['service_id'=>$service_id,'date'=>$date,'slots'=>[]], 200);
    }

    if (in_array($date, $settings['days_off'], true)) {
    return new \WP_REST_Response(['service_id'=>$service_id,'date'=>$date,'slots'=>[]], 200);
    }

    $tz = new \DateTimeZone($settings['timezone'] ?: 'America/Detroit');

    // Determine day key (mon/tue/...)
    $dt = new \DateTimeImmutable($date . ' 00:00:00', $tz);
    $map = ['1'=>'mon','2'=>'tue','3'=>'wed','4'=>'thu','5'=>'fri','6'=>'sat','7'=>'sun'];
    $dayKey = $map[$dt->format('N')] ?? 'mon';

    $hours  = $settings['hours'][$dayKey] ?? [];
    $breaks = $settings['breaks'][$dayKey] ?? [];

    $duration = (int) get_post_meta($service_id, '_koopo_duration_minutes', true);
    if (!$duration) $duration = 30;
    if ($duration_override > 0) {
      $duration = $duration_override;
    }

    $interval = (int) $settings['slot_interval'];
    // If not set, fall back to the service duration.
    if ($interval <= 0) $interval = $duration;

    // If an interval is larger than the service duration, it will skip possible start times
    // (e.g., 30-min service showing only hourly slots). Clamp to duration.
    if ($interval > $duration) $interval = $duration;
$busy = self::get_busy_ranges($listing_id, $date);

    // Apply buffers to busy ranges
    $buffer_before = (int)$settings['buffer_before'];
    $buffer_after  = (int)$settings['buffer_after'];
    if ($buffer_before || $buffer_after) {
    $busy = array_map(function($b) use ($buffer_before, $buffer_after) {
        $s = strtotime($b['start']) - ($buffer_before * 60);
        $e = strtotime($b['end']) + ($buffer_after * 60);
        return ['start' => date('Y-m-d H:i:s', $s), 'end' => date('Y-m-d H:i:s', $e)];
    }, $busy);
    }

    // Build slots from each hour range minus breaks
    $slots = [];
    foreach ($hours as $range) {
    [$from, $to] = $range;
    $rangeStart = $date . ' ' . $from . ':00';
    $rangeEnd   = $date . ' ' . $to . ':00';

    $rangeSlots = self::generate_slots($rangeStart, $rangeEnd, $duration, $interval);

    // remove those that overlap breaks
    $rangeSlots = array_values(array_filter($rangeSlots, function($s) use ($breaks, $date) {
        foreach ($breaks as $br) {
        [$bf, $bt] = $br;
        $bs = strtotime($date . ' ' . $bf . ':00');
        $be = strtotime($date . ' ' . $bt . ':00');
        $ss = strtotime($s['start']);
        $se = strtotime($s['end']);
        if ($ss < $be && $se > $bs) return false;
        }
        return true;
    }));

    $slots = array_merge($slots, $rangeSlots);
    }


    // De-duplicate and sort slots (prevents duplicated ranges or overlapping hour blocks producing duplicates)
    if (!empty($slots)) {
      $uniq = [];
      foreach ($slots as $s) {
        $k = $s['start'] . '|' . $s['end'];
        $uniq[$k] = $s;
      }
      $slots = array_values($uniq);
      usort($slots, function($a, $b) {
        return strtotime($a['start']) <=> strtotime($b['start']);
      });
    }

    // Remove overlaps with busy bookings
    $available = [];
    foreach ($slots as $s) {
    if (!self::overlaps_any($s['start'], $s['end'], $busy)) {
        $available[] = $s;
    }
    }

    return new \WP_REST_Response([
    'service_id' => $service_id,
    'listing_id' => $listing_id,
    'date' => $date,
    'timezone' => $settings['timezone'],
    'duration_minutes' => $duration,
    'slot_interval' => $interval,
    'slots' => $available,
    ], 200);
  }

  private static function generate_slots(string $start, string $end, int $duration, int $interval): array {
    $out = [];
    $start_ts = strtotime($start);
    $end_ts   = strtotime($end);

    for ($t = $start_ts; $t + ($duration * 60) <= $end_ts; $t += ($interval * 60)) {
      $s = date('Y-m-d H:i:s', $t);
      $e = date('Y-m-d H:i:s', $t + ($duration * 60));
      $out[] = [
        'start' => $s,
        'end' => $e,
        'label' => date('g:i A', $t),
      ];
    }
    return $out;
  }

  private static function get_busy_ranges(int $listing_id, string $date): array {
    if (!$listing_id) return [];

    global $wpdb;
    $table = DB::table();

    $dayStart = "{$date} 00:00:00";
    $dayEnd   = "{$date} 23:59:59";

    // Exclude only confirmed by default; you can include pending if you want.
    $statuses = Bookings::get_blocking_statuses($listing_id);
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

    $sql = $wpdb->prepare(
      "SELECT start_datetime, end_datetime
       FROM {$table}
       WHERE listing_id = %d
         AND status IN ({$placeholders})
         AND start_datetime BETWEEN %s AND %s",
      array_merge([$listing_id], $statuses, [$dayStart, $dayEnd])
    );

    $rows = $wpdb->get_results($sql);
    $busy = [];
    foreach ($rows as $r) {
      $busy[] = ['start' => $r->start_datetime, 'end' => $r->end_datetime];
    }
    return $busy;
  }

  private static function overlaps_any(string $start, string $end, array $busy): bool {
    $s1 = strtotime($start);
    $e1 = strtotime($end);
    foreach ($busy as $b) {
      $s2 = strtotime($b['start']);
      $e2 = strtotime($b['end']);
      if ($s1 < $e2 && $e1 > $s2) return true;
    }
    return false;
  }
}
