<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

class Services_CPT {

  const POST_TYPE = 'koopo_service';

  public static function init() {
    add_action('init', [__CLASS__, 'register_cpt']);
  }

  public static function register_cpt() {
    register_post_type(self::POST_TYPE, [
      'label' => 'Koopo Services',
      'public' => false,
      'show_ui' => false, // front-end managed
      'show_in_rest' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }
}
