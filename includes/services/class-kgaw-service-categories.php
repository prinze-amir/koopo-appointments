<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Profile-level service categories used for professional discovery.
 *
 * The service post type remains registered temporarily so legacy term
 * relationships can be read during the non-destructive migration.
 */
class Service_Categories {

  const TAXONOMY = 'koopo_service_category';

  public static function init() {
    add_action('init', [__CLASS__, 'register_taxonomy']);
    add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 99);
    add_action('koopo_service_category_add_form_fields', [__CLASS__, 'add_icon_field']);
    add_action('koopo_service_category_edit_form_fields', [__CLASS__, 'edit_icon_field']);
    add_action('created_koopo_service_category', [__CLASS__, 'save_icon_field']);
    add_action('edited_koopo_service_category', [__CLASS__, 'save_icon_field']);
    add_filter('manage_edit-koopo_service_category_columns', [__CLASS__, 'add_icon_column']);
    add_filter('manage_koopo_service_category_custom_column', [__CLASS__, 'render_icon_column'], 10, 3);
    add_action('init', [__CLASS__, 'seed_defaults'], 30);
    add_action('init', [__CLASS__, 'migrate_to_profiles'], 31);
  }

  /**
   * Add admin menu for service categories
   */
  public static function add_admin_menu() {
    add_submenu_page(
      'koopo-appointments',
      'Service Categories',
      'Service Categories',
      'manage_options',
      'edit-tags.php?taxonomy=koopo_service_category&post_type=koopo_provider'
    );
  }

  public static function register_taxonomy() {
    $labels = [
      'name' => 'Service Categories',
      'singular_name' => 'Service Category',
      'menu_name' => 'Categories',
      'all_items' => 'All Categories',
      'edit_item' => 'Edit Category',
      'view_item' => 'View Category',
      'update_item' => 'Update Category',
      'add_new_item' => 'Add New Category',
      'new_item_name' => 'New Category Name',
      'parent_item' => 'Parent Category',
      'parent_item_colon' => 'Parent Category:',
      'search_items' => 'Search Categories',
      'popular_items' => 'Popular Categories',
      'not_found' => 'No categories found',
    ];

    register_taxonomy(self::TAXONOMY, [Provider_Profiles::POST_TYPE, Services_CPT::POST_TYPE], [
      'labels' => $labels,
      'public' => false,
      'publicly_queryable' => false,
      'show_ui' => true,
      'show_in_menu' => false, // We handle menu manually
      'show_admin_column' => false,
      'hierarchical' => true,
      'show_in_rest' => true,
      'rewrite' => false,
      'capabilities' => [
        'manage_terms' => 'manage_options', // Only admins can manage
        'edit_terms'   => 'manage_options',
        'delete_terms' => 'manage_options',
        'assign_terms' => 'edit_posts', // Vendors can assign
      ],
      'meta_box_cb' => false, // Koopo provides a single category selector on the profile UI.
    ]);
  }

  /**
   * Add icon field to category add form
   */
  public static function add_icon_field() {
    wp_enqueue_media();
    ?>
    <div class="form-field"><label for="category-glyph"><?php esc_html_e('Directory symbol', 'koopo-appointments'); ?></label><input type="text" name="category_glyph" id="category-glyph" maxlength="4" placeholder="✦"><p class="description"><?php esc_html_e('A short symbol or emoji shown in category filters.', 'koopo-appointments'); ?></p></div>
    <div class="form-field"><label><input type="checkbox" name="category_featured" value="1"> <?php esc_html_e('Feature this category in discovery', 'koopo-appointments'); ?></label></div>
    <div class="form-field term-icon-wrap">
      <label for="category-icon">Category Icon</label>
      <div style="display: flex; gap: 10px; align-items: flex-start;">
        <input type="url" name="category_icon" id="category-icon" value="" size="40" placeholder="https://example.com/icon.svg" style="flex: 1;">
        <button type="button" class="button button-secondary" id="category-icon-upload">Upload Icon</button>
      </div>
      <p class="description">Upload an icon (SVG, PNG, or JPG) from your media library. Recommended size: 64x64px.</p>
    </div>

    <div class="form-field term-icon-preview-wrap">
      <label>Icon Preview</label>
      <div id="category-icon-preview" style="padding: 10px; background: #f5f5f5; border-radius: 4px; min-height: 80px; display: flex; align-items: center; justify-content: center;">
        <span style="color: #666; font-style: italic;">No icon uploaded yet</span>
      </div>
    </div>

    <script>
      jQuery(document).ready(function($) {
        let mediaUploader;

        $('#category-icon-upload').on('click', function(e) {
          e.preventDefault();

          if (mediaUploader) {
            mediaUploader.open();
            return;
          }

          mediaUploader = wp.media({
            title: 'Choose Category Icon',
            button: {
              text: 'Use this icon'
            },
            library: {
              type: ['image/svg+xml', 'image/png', 'image/jpeg', 'image/jpg']
            },
            multiple: false
          });

          mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#category-icon').val(attachment.url).trigger('input');
          });

          mediaUploader.open();
        });

        $('#category-icon').on('input', function() {
          const url = $(this).val();
          const $preview = $('#category-icon-preview');

          if (url) {
            $preview.html('<img src="' + url + '" style="max-width: 64px; max-height: 64px;" alt="Category icon">');
          } else {
            $preview.html('<span style="color: #666; font-style: italic;">No icon uploaded yet</span>');
          }
        });
      });
    </script>
    <?php
  }

  /**
   * Add icon field to category edit form
   */
  public static function edit_icon_field($term) {
    wp_enqueue_media();
    $icon_url = get_term_meta($term->term_id, 'icon_url', true);
    $glyph = get_term_meta($term->term_id, 'glyph', true);
    $featured = '1' === (string) get_term_meta($term->term_id, 'featured', true);
    ?>
    <tr class="form-field"><th scope="row"><label for="category-glyph"><?php esc_html_e('Directory symbol', 'koopo-appointments'); ?></label></th><td><input type="text" name="category_glyph" id="category-glyph" value="<?php echo esc_attr($glyph); ?>" maxlength="4"><p class="description"><?php esc_html_e('A short symbol or emoji shown in category filters.', 'koopo-appointments'); ?></p></td></tr>
    <tr class="form-field"><th scope="row"><?php esc_html_e('Discovery', 'koopo-appointments'); ?></th><td><label><input type="checkbox" name="category_featured" value="1" <?php checked($featured); ?>> <?php esc_html_e('Feature this category in discovery', 'koopo-appointments'); ?></label></td></tr>
    <tr class="form-field term-icon-wrap">
      <th scope="row">
        <label for="category-icon">Category Icon</label>
      </th>
      <td>
        <div style="display: flex; gap: 10px; align-items: flex-start; max-width: 600px;">
          <input type="url" name="category_icon" id="category-icon" value="<?php echo esc_attr($icon_url); ?>" size="40" placeholder="https://example.com/icon.svg" style="flex: 1;">
          <button type="button" class="button button-secondary" id="category-icon-upload">Upload Icon</button>
        </div>
        <p class="description">Upload an icon (SVG, PNG, or JPG) from your media library. Recommended size: 64x64px.</p>
      </td>
    </tr>

    <tr class="form-field term-icon-preview-wrap">
      <th scope="row">
        <label>Icon Preview</label>
      </th>
      <td>
        <div id="category-icon-preview" style="padding: 10px; background: #f5f5f5; border-radius: 4px; min-height: 80px; max-width: 120px; display: flex; align-items: center; justify-content: center;">
          <?php if ($icon_url): ?>
            <img src="<?php echo esc_url($icon_url); ?>" style="max-width: 64px; max-height: 64px;" alt="Category icon">
          <?php else: ?>
            <span style="color: #666; font-style: italic;">No icon uploaded yet</span>
          <?php endif; ?>
        </div>
      </td>
    </tr>

    <script>
      jQuery(document).ready(function($) {
        let mediaUploader;

        $('#category-icon-upload').on('click', function(e) {
          e.preventDefault();

          if (mediaUploader) {
            mediaUploader.open();
            return;
          }

          mediaUploader = wp.media({
            title: 'Choose Category Icon',
            button: {
              text: 'Use this icon'
            },
            library: {
              type: ['image/svg+xml', 'image/png', 'image/jpeg', 'image/jpg']
            },
            multiple: false
          });

          mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#category-icon').val(attachment.url).trigger('input');
          });

          mediaUploader.open();
        });

        $('#category-icon').on('input', function() {
          const url = $(this).val();
          const $preview = $('#category-icon-preview');

          if (url) {
            $preview.html('<img src="' + url + '" style="max-width: 64px; max-height: 64px;" alt="Category icon">');
          } else {
            $preview.html('<span style="color: #666; font-style: italic;">No icon uploaded yet</span>');
          }
        });
      });
    </script>
    <?php
  }

  /**
   * Save icon field
   */
  public static function save_icon_field($term_id) {
    if (isset($_POST['category_icon'])) {
      $icon_url = sanitize_text_field($_POST['category_icon']);
      update_term_meta($term_id, 'icon_url', $icon_url);
    }
    if (isset($_POST['category_glyph'])) {
      update_term_meta($term_id, 'glyph', sanitize_text_field(wp_unslash($_POST['category_glyph'])));
      update_term_meta($term_id, 'featured', !empty($_POST['category_featured']) ? '1' : '0');
    }
  }

  /**
   * Add icon column to category list
   */
  public static function add_icon_column($columns) {
    $new_columns = [];
    foreach ($columns as $key => $value) {
      if ($key === 'name') {
        $new_columns['icon'] = 'Icon';
      }
      $new_columns[$key] = $value;
    }
    return $new_columns;
  }

  /**
   * Render icon column
   */
  public static function render_icon_column($content, $column_name, $term_id) {
    if ($column_name === 'posts') {
      return (string) self::provider_count((int) $term_id);
    }
    if ($column_name === 'icon') {
      $icon_url = get_term_meta($term_id, 'icon_url', true);
      if ($icon_url) {
        return '<img src="' . esc_url($icon_url) . '" style="max-width: 32px; max-height: 32px; border-radius: 4px;" alt="Category icon">';
      } else {
        return '<span style="color: #999; font-style: italic;">—</span>';
      }
    }
    return $content;
  }

  /**
   * Get all categories
   */
  public static function get_all_categories() {
    $terms = get_terms([
      'taxonomy' => self::TAXONOMY,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
    ]);

    if (is_wp_error($terms)) {
      return [];
    }

    $categories = [];
    foreach ($terms as $term) {
      $categories[] = [
        'id' => $term->term_id,
        'name' => $term->name,
        'slug' => $term->slug,
        'description' => $term->description,
        'icon_url' => get_term_meta($term->term_id, 'icon_url', true),
        'glyph' => (string) get_term_meta($term->term_id, 'glyph', true),
        'featured' => '1' === (string) get_term_meta($term->term_id, 'featured', true),
        'count' => self::provider_count((int) $term->term_id),
      ];
    }

    return $categories;
  }

  /**
   * Get category by ID
   */
  public static function get_category($category_id) {
    $term = get_term($category_id, self::TAXONOMY);

    if (is_wp_error($term) || !$term) {
      return null;
    }

    return [
      'id' => $term->term_id,
      'name' => $term->name,
      'slug' => $term->slug,
      'description' => $term->description,
      'icon_url' => get_term_meta($term->term_id, 'icon_url', true),
      'glyph' => (string) get_term_meta($term->term_id, 'glyph', true),
      'featured' => '1' === (string) get_term_meta($term->term_id, 'featured', true),
      'count' => self::provider_count((int) $term->term_id),
    ];
  }

  public static function seed_defaults(): void {
    if ((string) get_option('koopo_appt_service_category_seed_version', '') === '1') return;
    $defaults = [
      ['Barbering & Grooming', 'barbering-grooming', 'Haircuts, beard care, shaves, and grooming.', '✂', true],
      ['Hair & Styling', 'hair-styling', 'Cuts, color, styling, braids, and hair care.', '◒', true],
      ['Beauty & Nails', 'beauty-nails', 'Nails, makeup, lashes, brows, and beauty services.', '✦', true],
      ['Massage & Bodywork', 'massage-bodywork', 'Massage, recovery, and hands-on bodywork.', '≈', true],
      ['Wellness', 'wellness', 'Holistic wellness, coaching, and personal care.', '○', true],
      ['Therapy & Counseling', 'therapy-counseling', 'Mental health, counseling, and therapeutic support.', '◇', true],
      ['Fitness & Movement', 'fitness-movement', 'Training, yoga, mobility, and movement sessions.', '↗', true],
      ['Home Services', 'home-services', 'Mobile and in-home professional services.', '⌂', false],
      ['Professional Services', 'professional-services', 'Consulting and appointment-based expertise.', '□', false],
    ];
    foreach ($defaults as [$name, $slug, $description, $glyph, $featured]) {
      $exists = term_exists($slug, self::TAXONOMY);
      $term_id = is_array($exists) ? (int) $exists['term_id'] : (int) $exists;
      if (!$term_id) {
        $created = wp_insert_term($name, self::TAXONOMY, ['slug' => $slug, 'description' => $description]);
        if (is_wp_error($created)) continue;
        $term_id = (int) $created['term_id'];
      }
      if (!get_term_meta($term_id, 'glyph', true)) update_term_meta($term_id, 'glyph', $glyph);
      if ($featured) update_term_meta($term_id, 'featured', '1');
    }
    update_option('koopo_appt_service_category_seed_version', '1', false);
  }

  /** Move the strongest legacy service category to each profile without deleting service terms. */
  public static function migrate_to_profiles(): void {
    if ((string) get_option('koopo_appt_profile_category_migration_version', '') === '1') return;
    $provider_ids = get_posts(['post_type'=>Provider_Profiles::POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
    foreach ($provider_ids as $provider_id) {
      $existing = wp_get_object_terms($provider_id, self::TAXONOMY, ['fields'=>'ids']);
      if (!is_wp_error($existing) && $existing) continue;
      $service_ids = get_posts(['post_type'=>Services_CPT::POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>Services_API::META_PROVIDER_ID,'meta_value'=>$provider_id]);
      $frequency = [];
      foreach ($service_ids as $service_id) {
        $term_ids = wp_get_object_terms($service_id, self::TAXONOMY, ['fields'=>'ids']);
        if (is_wp_error($term_ids)) continue;
        foreach ($term_ids as $term_id) $frequency[(int) $term_id] = ($frequency[(int) $term_id] ?? 0) + 1;
      }
      if (!$frequency) continue;
      uksort($frequency, static function($left, $right) use ($frequency): int { $compare=$frequency[$right]<=>$frequency[$left]; return $compare ?: ((int)$left<=> (int)$right); });
      wp_set_object_terms($provider_id, [(int) array_key_first($frequency)], self::TAXONOMY, false);
      foreach ($service_ids as $service_id) Bookable_Listings_API::sync_service((int) $service_id);
    }
    update_option('koopo_appt_profile_category_migration_version', '1', false);
  }

  private static function provider_count(int $term_id): int {
    $query = new \WP_Query(['post_type'=>Provider_Profiles::POST_TYPE,'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>false,'tax_query'=>[['taxonomy'=>self::TAXONOMY,'field'=>'term_id','terms'=>[$term_id]]]]);
    return (int) $query->found_posts;
  }
}
