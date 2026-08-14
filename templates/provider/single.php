<?php
use Koopo_Appointments\Provider_Profiles;
use Koopo_Appointments\UI;

defined('ABSPATH') || exit;
get_header();
the_post();
$provider_id = (int) get_the_ID();
$profile = Provider_Profiles::format($provider_id, false);
$services = Provider_Profiles::public_services($provider_id);
$currency = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '$';
$gallery = $profile['gallery'];
$review_summary = $profile['reviews'];
$reviews = get_comments(['post_id'=>$provider_id,'type'=>\Koopo_Appointments\Provider_Reviews::COMMENT_TYPE,'status'=>'approve','number'=>10,'orderby'=>'comment_date_gmt','order'=>'DESC']);
$owner_id = (int) get_post_field('post_author', $provider_id);
$section_index = 1;
$modes = array_map(static fn($mode) => ucwords(str_replace('_', ' ', $mode)), $profile['service_modes']);
$points = [];
foreach ($profile['locations'] as $location) {
  if (!empty($location['latitude']) && !empty($location['longitude'])) $points[] = ['providerId' => $provider_id, 'name' => $profile['name'], 'location' => $location['name'], 'lat' => (float) $location['latitude'], 'lng' => (float) $location['longitude']];
}
?>
<main class="koopo-pro-profile">
  <nav class="koopo-pro-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumb', 'koopo-appointments'); ?>"><a href="<?php echo esc_url(get_post_type_archive_link(Provider_Profiles::POST_TYPE)); ?>">← <?php esc_html_e('All professionals', 'koopo-appointments'); ?></a></nav>
  <header class="koopo-pro-hero">
    <div class="koopo-pro-hero__image"><?php if ($profile['image_url']) : ?><img src="<?php echo esc_url($profile['image_url']); ?>" alt="<?php echo esc_attr($profile['name']); ?>" /><?php else : ?><span><?php echo esc_html(mb_substr($profile['name'], 0, 1)); ?></span><?php endif; ?></div>
    <div class="koopo-pro-hero__content">
      <span class="koopo-pro-eyebrow"><?php esc_html_e('Koopo professional', 'koopo-appointments'); ?></span>
      <h1><?php echo esc_html($profile['name']); ?></h1>
      <?php if ($profile['headline']) : ?><p class="koopo-pro-hero__headline"><?php echo esc_html($profile['headline']); ?></p><?php endif; ?>
      <?php if ($review_summary['count']) : ?><a class="koopo-pro-rating-link" href="#reviews"><span aria-hidden="true"><?php echo esc_html(str_repeat('★', (int) round($review_summary['average'])) . str_repeat('☆', 5 - (int) round($review_summary['average']))); ?></span><strong><?php echo esc_html(number_format_i18n($review_summary['average'], 1)); ?></strong><small><?php printf(esc_html(_n('%s review', '%s reviews', $review_summary['count'], 'koopo-appointments')), number_format_i18n($review_summary['count'])); ?></small></a><?php endif; ?>
      <?php if ($modes || $profile['category']) : ?><div class="koopo-pro-pills"><?php if ($profile['category']) : ?><span class="koopo-pro-pill--category"><?php echo esc_html(($profile['category']['glyph'] ? $profile['category']['glyph'] . ' ' : '') . $profile['category']['name']); ?></span><?php endif; ?><?php foreach ($modes as $mode) : ?><span><?php echo esc_html($mode); ?></span><?php endforeach; ?></div><?php endif; ?>
      <a class="koopo-pro-member-link" href="<?php echo esc_url($profile['buddyboss_profile_url']); ?>"><?php esc_html_e('View member profile', 'koopo-appointments'); ?> ↗</a>
    </div>
  </header>

  <div class="koopo-pro-profile__grid">
    <div class="koopo-pro-profile__main">
      <section class="koopo-pro-section" id="services">
        <span class="koopo-pro-section__number"><?php echo esc_html(sprintf('%02d', $section_index++)); ?></span><div><h2><?php esc_html_e('Services', 'koopo-appointments'); ?></h2><p class="koopo-pro-section__lede"><?php esc_html_e('Choose what you need. You’ll select an available time during booking.', 'koopo-appointments'); ?></p></div>
        <div class="koopo-pro-services">
          <?php if ($services) : foreach ($services as $service) : ?><article><div><h3><?php echo esc_html($service['title']); ?></h3><?php if ($service['description']) : ?><p><?php echo esc_html($service['description']); ?></p><?php endif; ?><?php if ($service['duration_minutes']) : ?><small><?php echo esc_html($service['duration_minutes']); ?> <?php esc_html_e('minutes', 'koopo-appointments'); ?></small><?php endif; ?></div><strong><?php echo esc_html($service['price_label'] ?: $currency . number_format_i18n($service['price'], 2)); ?></strong></article><?php endforeach; else : ?><p><?php esc_html_e('This professional is preparing their service menu.', 'koopo-appointments'); ?></p><?php endif; ?>
        </div>
      </section>
      <?php if (trim(wp_strip_all_tags($profile['bio']))) : ?><section class="koopo-pro-section"><span class="koopo-pro-section__number"><?php echo esc_html(sprintf('%02d', $section_index++)); ?></span><div><h2><?php esc_html_e('About', 'koopo-appointments'); ?></h2><div class="koopo-pro-copy"><?php echo wp_kses_post(wpautop($profile['bio'])); ?></div></div></section><?php endif; ?>
      <?php if ($gallery) : ?><section class="koopo-pro-section koopo-pro-gallery-section" id="gallery"><span class="koopo-pro-section__number"><?php echo esc_html(sprintf('%02d', $section_index++)); ?></span><div><h2><?php esc_html_e('Work gallery', 'koopo-appointments'); ?></h2><p class="koopo-pro-section__lede"><?php esc_html_e('A closer look at their work, space, and process.', 'koopo-appointments'); ?></p></div><div class="koopo-pro-gallery" data-koopo-gallery><?php foreach ($gallery as $index => $image) : ?><button type="button" data-gallery-index="<?php echo esc_attr($index); ?>" aria-label="<?php echo esc_attr(sprintf(__('Open portfolio photo %d', 'koopo-appointments'), $index + 1)); ?>"><img src="<?php echo esc_url($image['url']); ?>" data-full-src="<?php echo esc_url($image['full_url']); ?>" alt="<?php echo esc_attr($image['alt'] ?: $profile['name']); ?>" loading="lazy" /></button><?php endforeach; ?></div></section><?php endif; ?>
      <section class="koopo-pro-section"><span class="koopo-pro-section__number"><?php echo esc_html(sprintf('%02d', $section_index++)); ?></span><div><h2><?php esc_html_e('Where they work', 'koopo-appointments'); ?></h2><p class="koopo-pro-section__lede"><?php esc_html_e('A professional can work independently while being affiliated with one or more locations.', 'koopo-appointments'); ?></p></div>
        <?php if ($profile['locations']) : ?><div class="koopo-pro-locations"><?php foreach ($profile['locations'] as $location) : $tag = !empty($location['url']) ? 'a' : 'div'; ?><<?php echo $tag; ?><?php if (!empty($location['url'])) : ?> href="<?php echo esc_url($location['url']); ?>"<?php endif; ?>><strong><?php echo esc_html($location['name']); ?></strong><span><?php echo esc_html($location['address'] ?: ucwords(str_replace('_', ' ', $location['relationship_type']))); ?></span><b><?php echo !empty($location['url']) ? esc_html__('View place', 'koopo-appointments') . ' ↗' : esc_html__('Service profile location', 'koopo-appointments'); ?></b></<?php echo $tag; ?>><?php endforeach; ?></div><?php else : ?><div class="koopo-pro-location-flex"><strong><?php esc_html_e('No fixed public location', 'koopo-appointments'); ?></strong><span><?php esc_html_e('This professional may offer mobile or virtual services.', 'koopo-appointments'); ?></span></div><?php endif; ?>
        <?php if ($points) : ?><div id="koopo-provider-map" class="koopo-pro-map koopo-pro-map--single"></div><?php endif; ?>
      </section>
      <section class="koopo-pro-section koopo-pro-reviews-section" id="reviews"><span class="koopo-pro-section__number"><?php echo esc_html(sprintf('%02d', $section_index++)); ?></span><div><h2><?php esc_html_e('Reviews', 'koopo-appointments'); ?></h2><?php if ($review_summary['count']) : ?><p class="koopo-pro-section__lede"><strong><?php echo esc_html(number_format_i18n($review_summary['average'], 1)); ?> / 5</strong> · <?php printf(esc_html(_n('%s community review', '%s community reviews', $review_summary['count'], 'koopo-appointments')), number_format_i18n($review_summary['count'])); ?></p><?php else : ?><p class="koopo-pro-section__lede"><?php esc_html_e('Be the first customer to share an experience.', 'koopo-appointments'); ?></p><?php endif; ?></div>
        <?php if ($reviews) : ?><div class="koopo-pro-reviews"><?php foreach ($reviews as $review) : $review_data = \Koopo_Appointments\Provider_Reviews::format($review); ?><article><header><?php if ($review_data['avatar_url']) : ?><img src="<?php echo esc_url($review_data['avatar_url']); ?>" alt="" /><?php else : ?><b class="koopo-pro-review-initial" aria-hidden="true"><?php echo esc_html(mb_substr($review_data['author'], 0, 1)); ?></b><?php endif; ?><div><strong><?php echo esc_html($review_data['author']); ?><?php if ($review_data['verified']) : ?> <small><?php esc_html_e('Verified booking', 'koopo-appointments'); ?></small><?php endif; ?></strong><span aria-label="<?php echo esc_attr(sprintf(__('%d out of 5 stars', 'koopo-appointments'), $review_data['rating'])); ?>"><?php echo esc_html(str_repeat('★', $review_data['rating']) . str_repeat('☆', 5 - $review_data['rating'])); ?></span></div><time datetime="<?php echo esc_attr($review_data['date']); ?>"><?php echo esc_html(human_time_diff(strtotime($review_data['date']), time())); ?> <?php esc_html_e('ago', 'koopo-appointments'); ?></time></header><div><?php echo wp_kses_post(wpautop(esc_html($review_data['content']))); ?></div></article><?php endforeach; ?></div><?php endif; ?>
        <?php if (is_user_logged_in() && get_current_user_id() !== $owner_id) : ?><form class="koopo-pro-review-form" data-provider-review-form data-provider-id="<?php echo esc_attr($provider_id); ?>"><h3><?php esc_html_e('Share your experience', 'koopo-appointments'); ?></h3><fieldset><legend><?php esc_html_e('Your rating', 'koopo-appointments'); ?></legend><?php for ($rating=5;$rating>=1;$rating--) : ?><input type="radio" name="rating" value="<?php echo esc_attr($rating); ?>" id="koopo-rating-<?php echo esc_attr($rating); ?>" required><label for="koopo-rating-<?php echo esc_attr($rating); ?>" aria-label="<?php echo esc_attr(sprintf(__('%d stars', 'koopo-appointments'), $rating)); ?>">★</label><?php endfor; ?></fieldset><label><span><?php esc_html_e('Review', 'koopo-appointments'); ?></span><textarea name="content" minlength="10" maxlength="3000" required></textarea></label><button type="submit"><?php esc_html_e('Submit review', 'koopo-appointments'); ?></button><p data-review-status aria-live="polite"></p></form><?php elseif (!is_user_logged_in()) : ?><p class="koopo-pro-review-login"><a href="<?php echo esc_url(wp_login_url(get_permalink($provider_id) . '#reviews')); ?>"><?php esc_html_e('Sign in to leave a review', 'koopo-appointments'); ?></a></p><?php endif; ?>
      </section>
    </div>
    <aside class="koopo-pro-booking-rail"><span><?php esc_html_e('Ready when you are', 'koopo-appointments'); ?></span><h2><?php esc_html_e('Book with', 'koopo-appointments'); ?><br /><?php echo esc_html($profile['name']); ?></h2><?php echo UI::render_booking(['provider_id' => $provider_id, 'button_text' => __('Choose a service', 'koopo-appointments')]); ?><small><?php esc_html_e('Your appointment is tied to this professional’s own calendar.', 'koopo-appointments'); ?></small></aside>
  </div>
  <script type="application/json" id="koopo-provider-map-data"><?php echo wp_json_encode($points); ?></script>
  <dialog class="koopo-pro-lightbox" data-koopo-lightbox><button type="button" data-lightbox-close aria-label="<?php esc_attr_e('Close gallery', 'koopo-appointments'); ?>">×</button><button type="button" data-lightbox-prev aria-label="<?php esc_attr_e('Previous photo', 'koopo-appointments'); ?>">←</button><img src="" alt=""><button type="button" data-lightbox-next aria-label="<?php esc_attr_e('Next photo', 'koopo-appointments'); ?>">→</button></dialog>
</main>
<?php get_footer(); ?>
