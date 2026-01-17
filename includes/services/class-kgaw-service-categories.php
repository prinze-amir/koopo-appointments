<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/**
 * Service Categories Taxonomy
 * Allows admin to create service categories with icons for tax tagging
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
      'edit-tags.php?taxonomy=koopo_service_category&post_type=koopo_service'
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

    register_taxonomy(self::TAXONOMY, Services_CPT::POST_TYPE, [
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
      'meta_box_cb' => false, // We'll handle this in the service creation UI
    ]);
  }

  /**
   * Add icon field to category add form
   */
  public static function add_icon_field() {
    wp_enqueue_media();
    ?>
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
    ?>
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
        'count' => $term->count,
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
      'count' => $term->count,
    ];
  }
}
