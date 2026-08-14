<?php
use Koopo_Appointments\Provider_Profiles;

defined('ABSPATH') || exit;
get_header();
$currency = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '$';
$profiles = [];
$active_category = sanitize_title((string) ($_GET['service_category'] ?? ''));
$categories = array_values(array_filter(\Koopo_Appointments\Service_Categories::get_all_categories(), static fn($category) => !empty($category['featured']) || !empty($category['count'])));
while (have_posts()) {
  the_post();
  $profile = Provider_Profiles::format((int) get_the_ID(), false);
  $profile['entity_type'] = 'provider';
  $profile['all_services'] = Provider_Profiles::public_services((int) get_the_ID());
  $profile['services'] = array_slice($profile['all_services'], 0, 3);
  $profiles[] = $profile;
}
$archive_page = max(1, (int) get_query_var('paged'));
if ($archive_page === 1) {
  $profiles = array_merge($profiles, \Koopo_Appointments\Bookable_Listings_API::archive_places(get_search_query(), $active_category, 50));
}
$map_points = [];
foreach ($profiles as $profile) {
  foreach ($profile['locations'] as $location) {
    if (empty($location['latitude']) || empty($location['longitude'])) continue;
    $prices = array_map(static fn($service) => (float) $service['price'], $profile['all_services']);
    $minimum_price = $prices ? min($prices) : null;
    $map_points[] = [
      'type' => 'location_marker',
      'providerId' => (int) $profile['id'],
      'name' => $profile['name'],
      'url' => $profile['permalink'],
      'image' => $profile['image_url'],
      'location' => $location['name'],
      'lat' => (float) $location['latitude'],
      'lng' => (float) $location['longitude'],
      'price' => null !== $minimum_price ? $currency . number_format_i18n($minimum_price, 0) . '+' : '',
      'priceLabel' => null !== $minimum_price ? sprintf(__('Services from %s', 'koopo-appointments'), $currency . number_format_i18n($minimum_price, 0)) : __('View services', 'koopo-appointments'),
    ];
  }
  if (!empty($profile['service_area']['configured']) && !empty($profile['service_area']['approximate_center'])) {
    $map_points[] = [
      'type' => 'service_area',
      'providerId' => (int) $profile['id'],
      'name' => $profile['name'],
      'url' => $profile['permalink'],
      'location' => $profile['service_area']['public_label'],
      'lat' => (float) $profile['service_area']['approximate_center']['latitude'],
      'lng' => (float) $profile['service_area']['approximate_center']['longitude'],
      'radius' => (int) $profile['service_area']['radius_meters'],
    ];
  }
}
?>
<main class="koopo-pro-directory" id="koopo-professionals">
  <section class="koopo-pro-directory__intro">
    <div>
      <span class="koopo-pro-eyebrow"><?php esc_html_e('Book local talent', 'koopo-appointments'); ?></span>
      <h1><?php esc_html_e('Find a professional who fits.', 'koopo-appointments'); ?></h1>
      <p><?php esc_html_e('Compare independent providers and bookable business locations in one place.', 'koopo-appointments'); ?></p>
    </div>
    <form class="koopo-pro-search" method="get" action="<?php echo esc_url(get_post_type_archive_link(Provider_Profiles::POST_TYPE)); ?>" role="search">
        <label for="koopo-professional-search"><?php esc_html_e('Search services and providers', 'koopo-appointments'); ?></label>
      <div class="koopo-pro-search__control">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.6-4.6m2.1-5.15a7.25 7.25 0 1 1-14.5 0 7.25 7.25 0 0 1 14.5 0Z"/></svg>
        <input id="koopo-professional-search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php esc_attr_e('Search people, places, or specialties', 'koopo-appointments'); ?>" />
        <?php if ($active_category) : ?><input type="hidden" name="service_category" value="<?php echo esc_attr($active_category); ?>" /><?php endif; ?>
        <button type="submit"><?php esc_html_e('Search', 'koopo-appointments'); ?></button>
      </div>
    </form>
  </section>

  <?php if ($categories) : ?><nav class="koopo-pro-categories" aria-label="<?php esc_attr_e('Service categories', 'koopo-appointments'); ?>">
    <a class="<?php echo $active_category ? '' : 'is-active'; ?>" href="<?php echo esc_url(get_post_type_archive_link(Provider_Profiles::POST_TYPE)); ?>"><span>⌘</span><b><?php esc_html_e('All services', 'koopo-appointments'); ?></b></a>
    <?php foreach ($categories as $category) : ?><a class="<?php echo $active_category === $category['slug'] ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('service_category', $category['slug'], get_post_type_archive_link(Provider_Profiles::POST_TYPE))); ?>"><span><?php echo esc_html($category['glyph'] ?: '○'); ?></span><b><?php echo esc_html($category['name']); ?></b><small><?php echo esc_html(number_format_i18n($category['count'])); ?></small></a><?php endforeach; ?>
  </nav><?php endif; ?>

  <div class="koopo-pro-mobile-switch" role="group" aria-label="<?php esc_attr_e('Directory view', 'koopo-appointments'); ?>">
    <button type="button" class="is-active" data-koopo-view="list"><?php esc_html_e('List', 'koopo-appointments'); ?></button>
    <button type="button" data-koopo-view="map"><?php esc_html_e('Map', 'koopo-appointments'); ?></button>
  </div>

  <?php if ($profiles) : ?>
    <div class="koopo-pro-directory__workspace" data-koopo-directory>
      <section class="koopo-pro-results" aria-label="<?php esc_attr_e('Professional results', 'koopo-appointments'); ?>">
        <div class="koopo-pro-results__count"><?php printf(esc_html(_n('%s bookable result', '%s bookable results', count($profiles), 'koopo-appointments')), number_format_i18n(count($profiles))); ?></div>
        <?php foreach ($profiles as $index => $profile) :
          $modes = array_map(static fn($mode) => ucwords(str_replace('_', ' ', $mode)), $profile['service_modes']);
          $primary_location = $profile['locations'][0] ?? null;
          $is_place = ($profile['entity_type'] ?? 'provider') === 'place';
        ?>
          <article class="koopo-pro-result" data-provider-id="<?php echo esc_attr($profile['id']); ?>" style="--koopo-delay:<?php echo esc_attr(min($index, 8) * 55); ?>ms">
            <a class="koopo-pro-result__portrait" href="<?php echo esc_url($profile['permalink']); ?>" aria-label="<?php echo esc_attr($profile['name']); ?>">
              <?php if ($profile['image_url']) : ?><img src="<?php echo esc_url($profile['image_url']); ?>" alt="<?php echo esc_attr($profile['name']); ?>" loading="lazy" /><?php else : ?><span><?php echo esc_html(mb_substr($profile['name'], 0, 1)); ?></span><?php endif; ?>
            </a>
            <div class="koopo-pro-result__body">
              <div class="koopo-pro-result__topline">
                <div><span class="koopo-pro-result__type"><?php echo esc_html($is_place ? __('Business location', 'koopo-appointments') : __('Service provider', 'koopo-appointments')); ?></span><h2><a href="<?php echo esc_url($profile['permalink']); ?>"><?php echo esc_html($profile['name']); ?></a></h2><?php if ($profile['headline']) : ?><p><?php echo esc_html($profile['headline']); ?></p><?php endif; ?></div>
                <a class="koopo-pro-arrow" href="<?php echo esc_url($profile['permalink']); ?>" aria-label="<?php esc_attr_e('View profile', 'koopo-appointments'); ?>">↗</a>
              </div>
              <div class="koopo-pro-meta">
                <?php if ($modes) : ?><span><?php echo esc_html(implode(' · ', $modes)); ?></span><?php endif; ?>
                <?php if ($primary_location) : ?><span class="koopo-pro-meta__location"><?php echo esc_html($primary_location['name']); ?></span><?php endif; ?>
                <?php if (!$primary_location && !empty($profile['service_area']['public_label'])) : ?><span class="koopo-pro-meta__location"><?php echo esc_html($profile['service_area']['public_label']); ?></span><?php endif; ?>
                <?php if ($profile['reviews']['count']) : ?><span class="koopo-pro-meta__rating">★ <?php echo esc_html(number_format_i18n($profile['reviews']['average'], 1)); ?> · <?php printf(esc_html(_n('%s review', '%s reviews', $profile['reviews']['count'], 'koopo-appointments')), number_format_i18n($profile['reviews']['count'])); ?></span><?php endif; ?>
              </div>
              <?php $profile_categories = array_column($profile['categories'], 'name', 'slug'); if ($profile_categories) : ?><div class="koopo-pro-result__categories"><?php foreach ($profile_categories as $slug => $name) : ?><a href="<?php echo esc_url(add_query_arg('service_category', $slug, get_post_type_archive_link(Provider_Profiles::POST_TYPE))); ?>"><?php echo esc_html($name); ?></a><?php endforeach; ?></div><?php endif; ?>
              <?php if ($profile['services']) : ?><ul class="koopo-pro-service-peek">
                <?php foreach ($profile['services'] as $service) : ?><li><span><?php echo esc_html($service['title']); ?><?php if ($service['duration_minutes']) : ?><small><?php echo esc_html($service['duration_minutes']); ?> min</small><?php endif; ?></span><strong><?php echo esc_html($service['price_label'] ?: $currency . number_format_i18n($service['price'], 0)); ?></strong></li><?php endforeach; ?>
              </ul><?php else : ?><p class="koopo-pro-service-peek__empty"><?php esc_html_e('Services coming soon', 'koopo-appointments'); ?></p><?php endif; ?>
              <a class="koopo-pro-primary" href="<?php echo esc_url($profile['permalink']); ?>"><?php echo esc_html($is_place ? __('View place services & book', 'koopo-appointments') : __('View services & book', 'koopo-appointments')); ?></a>
            </div>
          </article>
        <?php endforeach; ?>
        <?php the_posts_pagination(['mid_size' => 1]); ?>
      </section>
      <aside class="koopo-pro-map-panel" aria-label="<?php esc_attr_e('Professional locations', 'koopo-appointments'); ?>">
        <div id="koopo-provider-map" class="koopo-pro-map"></div>
        <div class="koopo-pro-map__empty" <?php echo $map_points ? 'hidden' : ''; ?>>
          <strong><?php esc_html_e('Location-flexible professionals', 'koopo-appointments'); ?></strong>
          <span><?php esc_html_e('These providers may be mobile, virtual, or still adding an affiliated workplace.', 'koopo-appointments'); ?></span>
        </div>
      </aside>
    </div>
  <?php else : ?>
    <section class="koopo-pro-empty"><span>✦</span><h2><?php esc_html_e('No professionals found yet.', 'koopo-appointments'); ?></h2><p><?php esc_html_e('Try a broader search, or check back as new service profiles join Koopo.', 'koopo-appointments'); ?></p></section>
  <?php endif; ?>
  <script type="application/json" id="koopo-provider-map-data"><?php echo wp_json_encode($map_points); ?></script>
</main>
<?php get_footer(); ?>
