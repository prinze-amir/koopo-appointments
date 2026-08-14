<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Native WordPress administration for service profiles and services. */
final class Provider_Service_Admin {
  public static function init(): void {
    add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
    add_action('save_post_' . Provider_Profiles::POST_TYPE, [__CLASS__, 'save_provider'], 10, 2);
    add_action('save_post_' . Services_CPT::POST_TYPE, [__CLASS__, 'save_service'], 10, 2);
    add_filter('manage_' . Provider_Profiles::POST_TYPE . '_posts_columns', [__CLASS__, 'provider_columns']);
    add_action('manage_' . Provider_Profiles::POST_TYPE . '_posts_custom_column', [__CLASS__, 'provider_column'], 10, 2);
    add_filter('manage_' . Services_CPT::POST_TYPE . '_posts_columns', [__CLASS__, 'service_columns']);
    add_action('manage_' . Services_CPT::POST_TYPE . '_posts_custom_column', [__CLASS__, 'service_column'], 10, 2);
  }

  public static function meta_boxes(): void {
    add_meta_box('koopo-provider-details', __('Service profile details', 'koopo-appointments'), [__CLASS__, 'provider_box'], Provider_Profiles::POST_TYPE, 'normal', 'high');
    add_meta_box('koopo-provider-photo', __('Profile image', 'koopo-appointments'), [__CLASS__, 'provider_photo_box'], Provider_Profiles::POST_TYPE, 'side', 'default');
    add_meta_box('koopo-provider-gallery', __('Work gallery', 'koopo-appointments'), [__CLASS__, 'provider_gallery_box'], Provider_Profiles::POST_TYPE, 'normal', 'default');
    add_meta_box('koopo-provider-reviews', __('Provider reviews', 'koopo-appointments'), [__CLASS__, 'provider_reviews_box'], Provider_Profiles::POST_TYPE, 'side', 'default');
    remove_meta_box('postimagediv', Provider_Profiles::POST_TYPE, 'side');
    add_meta_box('koopo-service-details', __('Service details', 'koopo-appointments'), [__CLASS__, 'service_box'], Services_CPT::POST_TYPE, 'normal', 'high');
  }

  public static function provider_box(\WP_Post $post): void {
    wp_nonce_field('koopo_provider_admin_save', 'koopo_provider_admin_nonce');
    $location = Provider_Profiles::direct_location((int) $post->ID, true) ?: [];
    $modes = (array) get_post_meta($post->ID, Provider_Profiles::META_SERVICE_MODES, true);
    $profile_category = Provider_Profiles::categories((int) $post->ID)[0]['id'] ?? 0;
    $categories = Service_Categories::get_all_categories();
    ?>
    <style>.koopo-admin-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.koopo-admin-fields label{display:flex;flex-direction:column;gap:6px;font-weight:600}.koopo-admin-fields input,.koopo-admin-fields textarea,.koopo-admin-fields select{width:100%}.koopo-admin-fields .wide{grid-column:1/-1}.koopo-admin-checks{display:flex!important;flex-direction:row!important;flex-wrap:wrap;align-items:center;gap:18px!important}.koopo-admin-checks label{display:inline-flex;flex-direction:row;align-items:center;font-weight:400}.koopo-admin-note{padding:12px 14px;background:#f0f6f2;border-left:4px solid #315943}</style>
    <div class="koopo-admin-fields">
      <label class="wide"><?php esc_html_e('Headline', 'koopo-appointments'); ?><input name="koopo_provider_headline" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_HEADLINE, true)); ?>"></label>
      <label><?php esc_html_e('Primary service category', 'koopo-appointments'); ?><select name="koopo_provider_category"><option value="0"><?php esc_html_e('Choose category…', 'koopo-appointments'); ?></option><?php foreach ($categories as $category) : ?><option value="<?php echo esc_attr($category['id']); ?>" <?php selected($profile_category, $category['id']); ?>><?php echo esc_html($category['name']); ?></option><?php endforeach; ?></select></label>
      <div class="wide koopo-admin-checks"><strong><?php esc_html_e('Service modes', 'koopo-appointments'); ?></strong><?php foreach (['at_location'=>'At a location','mobile'=>'Mobile','virtual'=>'Virtual'] as $value=>$label) : ?><label><input type="checkbox" name="koopo_provider_modes[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $modes, true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div>
      <h3 class="wide"><?php esc_html_e('Independent location', 'koopo-appointments'); ?></h3>
      <p class="wide koopo-admin-note"><?php esc_html_e('Use this when the provider needs map discovery without owning a GeoDirectory place. Approved place affiliations remain available as additional locations.', 'koopo-appointments'); ?></p>
      <label><?php esc_html_e('Location name', 'koopo-appointments'); ?><input name="koopo_provider_location_name" value="<?php echo esc_attr($location['name'] ?? ''); ?>" placeholder="Private studio or workplace name"></label>
      <label><?php esc_html_e('Street address', 'koopo-appointments'); ?><input name="koopo_provider_address" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_ADDRESS, true)); ?>"></label>
      <label><?php esc_html_e('City', 'koopo-appointments'); ?><input name="koopo_provider_city" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_CITY, true)); ?>"></label>
      <label><?php esc_html_e('State or region', 'koopo-appointments'); ?><input name="koopo_provider_region" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_REGION, true)); ?>"></label>
      <label><?php esc_html_e('Postal code', 'koopo-appointments'); ?><input name="koopo_provider_postal" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_POSTAL_CODE, true)); ?>"></label>
      <label><?php esc_html_e('Country', 'koopo-appointments'); ?><input name="koopo_provider_country" value="<?php echo esc_attr(get_post_meta($post->ID, Provider_Profiles::META_COUNTRY, true)); ?>"></label>
      <label><?php esc_html_e('Latitude', 'koopo-appointments'); ?><input type="number" step="0.0000001" min="-90" max="90" name="koopo_provider_latitude" value="<?php echo esc_attr($location['latitude'] ?? ''); ?>"></label>
      <label><?php esc_html_e('Longitude', 'koopo-appointments'); ?><input type="number" step="0.0000001" min="-180" max="180" name="koopo_provider_longitude" value="<?php echo esc_attr($location['longitude'] ?? ''); ?>"></label>
      <label class="wide" style="display:block"><input type="checkbox" name="koopo_provider_location_public" value="1" <?php checked('1', get_post_meta($post->ID, Provider_Profiles::META_LOCATION_PUBLIC, true)); ?>> <?php esc_html_e('Show this location publicly and use it on the professionals map', 'koopo-appointments'); ?></label>
    </div>
    <?php
  }

  public static function provider_photo_box(\WP_Post $post): void {
    $image = Provider_Profiles::image_url((int) $post->ID, 'medium');
    if ($image) echo '<img src="' . esc_url($image) . '" alt="" style="display:block;width:100%;height:auto;aspect-ratio:1;object-fit:cover;margin-bottom:12px">';
    $owner = (int) $post->post_author;
    $url = function_exists('dokan_get_navigation_url') && $owner === get_current_user_id() ? dokan_get_navigation_url('koopo-professional-profile') : '';
    echo '<p>' . esc_html__('Service-profile images use the direct Media Gateway upload and fall back to the member avatar.', 'koopo-appointments') . '</p>';
    if ($url) echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Manage image', 'koopo-appointments') . '</a>';
  }

  public static function provider_gallery_box(\WP_Post $post): void {
    $gallery = Provider_Profiles::gallery((int) $post->ID);
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px">';
    foreach ($gallery as $image) echo '<img src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt']) . '" style="width:100%;aspect-ratio:1;object-fit:cover">';
    echo '</div><p>' . esc_html(sprintf(__('Gallery photos are uploaded directly to Koopo storage. Up to %d ordered images are supported.', 'koopo-appointments'), Provider_Profiles::GALLERY_LIMIT)) . '</p>';
    if (!$gallery) echo '<p><em>' . esc_html__('No gallery photos yet.', 'koopo-appointments') . '</em></p>';
  }

  public static function provider_reviews_box(\WP_Post $post): void {
    $summary = Provider_Reviews::summary((int) $post->ID);
    echo '<p><strong style="font-size:22px">' . esc_html($summary['count'] ? number_format_i18n($summary['average'], 1) . ' / 5' : __('No rating', 'koopo-appointments')) . '</strong><br>' . esc_html(sprintf(_n('%s approved review', '%s approved reviews', $summary['count'], 'koopo-appointments'), number_format_i18n($summary['count']))) . '</p>';
    echo '<a class="button" href="' . esc_url(admin_url('edit-comments.php?comment_type=' . Provider_Reviews::COMMENT_TYPE . '&p=' . (int) $post->ID)) . '">' . esc_html__('Moderate reviews', 'koopo-appointments') . '</a>';
  }

  public static function service_box(\WP_Post $post): void {
    wp_nonce_field('koopo_service_admin_save', 'koopo_service_admin_nonce');
    $provider_id = (int) get_post_meta($post->ID, Services_API::META_PROVIDER_ID, true);
    $listing_id = (int) get_post_meta($post->ID, Services_API::META_LISTING_ID, true);
    $providers = get_posts(['post_type'=>Provider_Profiles::POST_TYPE,'post_status'=>['publish','draft'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
    ?>
    <div class="koopo-admin-fields">
      <label><?php esc_html_e('Service profile', 'koopo-appointments'); ?><select name="koopo_service_provider"><option value="0"><?php esc_html_e('Place-based service', 'koopo-appointments'); ?></option><?php foreach ($providers as $provider) : ?><option value="<?php echo esc_attr($provider->ID); ?>" <?php selected($provider_id, $provider->ID); ?>><?php echo esc_html($provider->post_title); ?></option><?php endforeach; ?></select></label>
      <label><?php esc_html_e('GeoDirectory place ID', 'koopo-appointments'); ?><input type="number" min="0" name="koopo_service_listing" value="<?php echo esc_attr($listing_id); ?>"><small><?php esc_html_e('Use either a service profile or a place, not both.', 'koopo-appointments'); ?></small></label>
      <label><?php esc_html_e('Price', 'koopo-appointments'); ?><input type="number" min="0" step="0.01" name="koopo_service_price" value="<?php echo esc_attr(get_post_meta($post->ID, Services_API::META_PRICE, true)); ?>"></label>
      <label><?php esc_html_e('Display price', 'koopo-appointments'); ?><input name="koopo_service_price_label" value="<?php echo esc_attr(get_post_meta($post->ID, Services_API::META_PRICE_LABEL, true)); ?>" placeholder="From $45"></label>
      <label><?php esc_html_e('Duration in minutes', 'koopo-appointments'); ?><input type="number" min="5" step="5" name="koopo_service_duration" value="<?php echo esc_attr(get_post_meta($post->ID, Services_API::META_DURATION, true)); ?>"></label>
      <label><?php esc_html_e('Status', 'koopo-appointments'); ?><select name="koopo_service_status"><option value="active" <?php selected('active', get_post_meta($post->ID, Services_API::META_STATUS, true)); ?>><?php esc_html_e('Active', 'koopo-appointments'); ?></option><option value="inactive" <?php selected('inactive', get_post_meta($post->ID, Services_API::META_STATUS, true)); ?>><?php esc_html_e('Inactive', 'koopo-appointments'); ?></option></select></label>
      <label class="wide"><?php esc_html_e('Description', 'koopo-appointments'); ?><textarea rows="4" name="koopo_service_description"><?php echo esc_textarea(get_post_meta($post->ID, Services_API::META_DESC, true)); ?></textarea></label>
      <p class="wide koopo-admin-note"><?php esc_html_e('Category is inherited from the selected service profile. Place-based services continue to use their business listing for discovery.', 'koopo-appointments'); ?></p>
      <div class="wide koopo-admin-checks"><label><input type="checkbox" name="koopo_service_instant" value="1" <?php checked('1', get_post_meta($post->ID, Services_API::META_INSTANT, true)); ?>> <?php esc_html_e('Instant booking', 'koopo-appointments'); ?></label><label><input type="checkbox" name="koopo_service_addon" value="1" <?php checked('1', get_post_meta($post->ID, Services_API::META_ADDON, true)); ?>> <?php esc_html_e('Add-on service', 'koopo-appointments'); ?></label></div>
    </div>
    <?php
  }

  public static function save_provider(int $post_id, \WP_Post $post): void {
    if (!self::can_save($post_id, 'koopo_provider_admin_nonce', 'koopo_provider_admin_save')) return;
    Provider_Profiles::save_meta($post_id, [
      'headline' => wp_unslash($_POST['koopo_provider_headline'] ?? ''), 'category_id' => absint($_POST['koopo_provider_category'] ?? 0), 'service_modes' => (array) ($_POST['koopo_provider_modes'] ?? []),
      'location_name' => wp_unslash($_POST['koopo_provider_location_name'] ?? ''), 'address' => wp_unslash($_POST['koopo_provider_address'] ?? ''),
      'city' => wp_unslash($_POST['koopo_provider_city'] ?? ''), 'region' => wp_unslash($_POST['koopo_provider_region'] ?? ''), 'postal_code' => wp_unslash($_POST['koopo_provider_postal'] ?? ''),
      'country' => wp_unslash($_POST['koopo_provider_country'] ?? ''), 'latitude' => $_POST['koopo_provider_latitude'] ?? '', 'longitude' => $_POST['koopo_provider_longitude'] ?? '',
      'location_public' => !empty($_POST['koopo_provider_location_public']),
    ]);
    $headline = sanitize_text_field(wp_unslash($_POST['koopo_provider_headline'] ?? ''));
    if ($headline !== $post->post_excerpt) { remove_action('save_post_' . Provider_Profiles::POST_TYPE, [__CLASS__, 'save_provider'], 10); wp_update_post(['ID'=>$post_id,'post_excerpt'=>$headline]); add_action('save_post_' . Provider_Profiles::POST_TYPE, [__CLASS__, 'save_provider'], 10, 2); }
  }

  public static function save_service(int $post_id, \WP_Post $post): void {
    unset($post);
    if (!self::can_save($post_id, 'koopo_service_admin_nonce', 'koopo_service_admin_save')) return;
    $provider_id = absint($_POST['koopo_service_provider'] ?? 0); $listing_id = $provider_id ? 0 : absint($_POST['koopo_service_listing'] ?? 0);
    update_post_meta($post_id, Services_API::META_PROVIDER_ID, $provider_id); update_post_meta($post_id, Services_API::META_LISTING_ID, $listing_id); update_post_meta($post_id, '_koopo_listing_id', $listing_id);
    $price = wc_format_decimal(wp_unslash($_POST['koopo_service_price'] ?? '0')); $duration = max(5, absint($_POST['koopo_service_duration'] ?? 30));
    update_post_meta($post_id, Services_API::META_PRICE, $price); update_post_meta($post_id, '_koopo_price', $price); update_post_meta($post_id, Services_API::META_DURATION, $duration); update_post_meta($post_id, '_koopo_duration_minutes', $duration);
    update_post_meta($post_id, Services_API::META_PRICE_LABEL, sanitize_text_field(wp_unslash($_POST['koopo_service_price_label'] ?? ''))); update_post_meta($post_id, Services_API::META_DESC, sanitize_textarea_field(wp_unslash($_POST['koopo_service_description'] ?? '')));
    update_post_meta($post_id, Services_API::META_STATUS, in_array($_POST['koopo_service_status'] ?? '', ['active','inactive'], true) ? $_POST['koopo_service_status'] : 'active'); update_post_meta($post_id, Services_API::META_INSTANT, !empty($_POST['koopo_service_instant']) ? '1' : '0'); update_post_meta($post_id, Services_API::META_ADDON, !empty($_POST['koopo_service_addon']) ? '1' : '0');
    if ($provider_id) update_post_meta($post_id, Resources::META_RESOURCE_ID, Resources::ensure_for_provider($provider_id)); elseif ($listing_id) update_post_meta($post_id, Resources::META_RESOURCE_ID, Resources::ensure_for_listing($listing_id));
    $product = wc_get_product((int) get_post_meta($post_id, '_koopo_wc_product_id', true)); if ($product) { $product->set_name(get_the_title($post_id)); $product->set_regular_price((string) $price); $product->set_price((string) $price); $product->save(); }
  }

  private static function can_save(int $post_id, string $nonce_name, string $action): bool {
    return !wp_is_post_autosave($post_id) && !wp_is_post_revision($post_id) && isset($_POST[$nonce_name]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_name])), $action) && current_user_can('edit_post', $post_id);
  }

  public static function provider_columns(array $columns): array { return array_slice($columns,0,2,true)+['koopo_owner'=>__('Owner','koopo-appointments'),'koopo_category'=>__('Category','koopo-appointments'),'koopo_location'=>__('Location','koopo-appointments'),'koopo_services'=>__('Services','koopo-appointments')]+array_slice($columns,2,null,true); }
  public static function provider_column(string $column, int $post_id): void { if ('koopo_owner'===$column) echo esc_html(get_the_author_meta('display_name',(int)get_post_field('post_author',$post_id))); if ('koopo_category'===$column) echo esc_html(Provider_Profiles::categories($post_id)[0]['name']??'—'); if ('koopo_location'===$column) { $l=Provider_Profiles::direct_location($post_id,true); echo esc_html($l['address']??__('Not set','koopo-appointments')); } if ('koopo_services'===$column) echo esc_html(count(Provider_Profiles::public_services($post_id))); }
  public static function service_columns(array $columns): array { return array_slice($columns,0,2,true)+['koopo_context'=>__('Booking profile','koopo-appointments'),'koopo_category'=>__('Profile category','koopo-appointments'),'koopo_price'=>__('Price','koopo-appointments'),'koopo_duration'=>__('Duration','koopo-appointments')]+array_slice($columns,2,null,true); }
  public static function service_column(string $column, int $post_id): void { if ('koopo_context'===$column) { $id=(int)get_post_meta($post_id,Services_API::META_PROVIDER_ID,true) ?: (int)get_post_meta($post_id,Services_API::META_LISTING_ID,true); echo esc_html($id?get_the_title($id):__('Unassigned','koopo-appointments')); } if ('koopo_category'===$column) { $provider_id=(int)get_post_meta($post_id,Services_API::META_PROVIDER_ID,true); $category=$provider_id?(Provider_Profiles::categories($provider_id)[0]['name']??''):''; echo esc_html($category?:'—'); } if ('koopo_price'===$column) echo wp_kses_post(wc_price((float)get_post_meta($post_id,Services_API::META_PRICE,true))); if ('koopo_duration'===$column) echo esc_html(absint(get_post_meta($post_id,Services_API::META_DURATION,true)).' min'); }
}
