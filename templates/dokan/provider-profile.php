<?php
defined('ABSPATH') || exit;
$koopo_profile_categories = \Koopo_Appointments\Service_Categories::get_all_categories();
$koopo_profile_entitlement = \Koopo_Appointments\Provider_Profiles::profile_entitlement(get_current_user_id());
?>
<div class="dokan-dashboard-wrap">
  <?php do_action('dokan_dashboard_content_before'); ?>
  <div class="dokan-dashboard-content koopo-vendor-page">
    <div class="koopo-provider-library" data-koopo-provider-library>
      <header class="koopo-provider-library__header">
        <div><span class="koopo-section-kicker"><?php esc_html_e('Appointments', 'koopo-appointments'); ?></span><h2><?php esc_html_e('Service profiles', 'koopo-appointments'); ?></h2><p><?php esc_html_e('Manage each professional’s public profile, portfolio, location, and delivery settings.', 'koopo-appointments'); ?></p></div>
        <button type="button" class="koopo-btn koopo-btn--gold" data-koopo-create-toggle aria-expanded="false" aria-controls="koopo-provider-create-panel" <?php disabled(!$koopo_profile_entitlement['can_create']); ?>><?php esc_html_e('Create new profile', 'koopo-appointments'); ?></button>
      </header>
      <div class="koopo-provider-entitlement<?php echo $koopo_profile_entitlement['can_create'] ? '' : ' is-at-limit'; ?>" data-koopo-profile-entitlement>
        <span><strong data-koopo-profile-usage><?php echo esc_html($koopo_profile_entitlement['used'] . ' of ' . $koopo_profile_entitlement['limit_label']); ?></strong> <?php esc_html_e('profiles used', 'koopo-appointments'); ?></span>
        <?php if (!$koopo_profile_entitlement['can_create']) : ?><a href="<?php echo esc_url(\Koopo_Appointments\Dokan_Dashboard::get_upgrade_plan_url()); ?>"><?php esc_html_e('Upgrade plan', 'koopo-appointments'); ?> →</a><?php endif; ?>
      </div>
      <section class="koopo-provider-create-panel" id="koopo-provider-create-panel" hidden>
      <div class="koopo-provider-create-panel__heading"><div><span class="koopo-section-kicker"><?php esc_html_e('New profile', 'koopo-appointments'); ?></span><h3><?php esc_html_e('Create a service profile', 'koopo-appointments'); ?></h3><p><?php esc_html_e('Use a separate profile when a professional manages their own services and availability.', 'koopo-appointments'); ?></p></div><button type="button" class="koopo-provider-icon-button" data-koopo-create-close aria-label="<?php esc_attr_e('Close new profile form', 'koopo-appointments'); ?>">×</button></div>
      <label class="koopo-provider-image-field"><span class="koopo-provider-image-preview" data-koopo-provider-create-preview><span aria-hidden="true">+</span></span><span><strong><?php esc_html_e('Profile image', 'koopo-appointments'); ?></strong><small><?php esc_html_e('Optional. We use your member photo when you do not upload one.', 'koopo-appointments'); ?></small><input id="koopo-provider-image" type="file" accept="image/jpeg,image/png,image/webp,image/avif" /></span></label>
      <label class="koopo-label"><?php esc_html_e('Service profile name', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-name" type="text" /></label>
      <label class="koopo-label"><?php esc_html_e('Primary service category', 'koopo-appointments'); ?><select class="koopo-input" id="koopo-provider-category" required><option value=""><?php esc_html_e('Choose what you offer…', 'koopo-appointments'); ?></option><?php foreach ($koopo_profile_categories as $category) : ?><option value="<?php echo esc_attr($category['id']); ?>"><?php echo esc_html(($category['glyph'] ? $category['glyph'] . ' ' : '') . $category['name']); ?></option><?php endforeach; ?></select><small><?php esc_html_e('This category applies to the profile and all of its services.', 'koopo-appointments'); ?></small></label>
      <label class="koopo-label"><?php esc_html_e('Short headline', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-headline" type="text" placeholder="<?php esc_attr_e('Barber, therapist, consultant…', 'koopo-appointments'); ?>" /></label>
      <details class="koopo-provider-location-panel"><summary><?php esc_html_e('Add an independent location', 'koopo-appointments'); ?></summary><p><?php esc_html_e('Optional. Use this if you want map discovery without owning a business listing.', 'koopo-appointments'); ?></p><div class="koopo-form-grid"><label class="koopo-label"><?php esc_html_e('Location name', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-location-name" placeholder="My studio" /></label><label class="koopo-label"><?php esc_html_e('Street address', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-address" /></label><label class="koopo-label"><?php esc_html_e('City', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-city" /></label><label class="koopo-label"><?php esc_html_e('State or region', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-region" /></label><label class="koopo-label"><?php esc_html_e('Postal code', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-postal" /></label><label class="koopo-label"><?php esc_html_e('Country', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-country" value="United States" /></label><input type="hidden" id="koopo-provider-latitude"><input type="hidden" id="koopo-provider-longitude"></div><p><button type="button" class="koopo-btn koopo-btn--secondary" data-koopo-use-location="create"><?php esc_html_e('Use my current map position', 'koopo-appointments'); ?></button> <span data-koopo-location-status="create" aria-live="polite"></span></p><label><input type="checkbox" id="koopo-provider-location-public" checked /> <?php esc_html_e('Show this location publicly', 'koopo-appointments'); ?></label></details>
      <p>
        <label><input type="checkbox" class="koopo-provider-mode" value="at_location" checked /> <?php esc_html_e('At a business location', 'koopo-appointments'); ?></label>
        <label><input type="checkbox" class="koopo-provider-mode" value="mobile" /> <?php esc_html_e('Mobile service', 'koopo-appointments'); ?></label>
        <label><input type="checkbox" class="koopo-provider-mode" value="virtual" /> <?php esc_html_e('Virtual service', 'koopo-appointments'); ?></label>
      </p>
      <button type="button" class="koopo-btn koopo-btn--secondary" id="koopo-create-provider"><?php esc_html_e('Create Profile', 'koopo-appointments'); ?></button>
      <span class="koopo-provider-create-status" aria-live="polite"></span>
      </section>
      <section class="koopo-provider-index" aria-labelledby="koopo-provider-index-title">
        <div class="koopo-provider-index__heading"><div><h3 id="koopo-provider-index-title"><?php esc_html_e('Your profiles', 'koopo-appointments'); ?></h3><p data-koopo-provider-count><?php esc_html_e('Loading service profiles…', 'koopo-appointments'); ?></p></div></div>
        <div class="koopo-provider-profile-grid" data-koopo-provider-grid aria-live="polite"></div>
      </section>
    </div>
    <div class="koopo-provider-modal" data-koopo-provider-modal hidden>
      <button type="button" class="koopo-provider-modal__backdrop" data-koopo-modal-close aria-label="<?php esc_attr_e('Close profile editor', 'koopo-appointments'); ?>"></button>
      <section class="koopo-provider-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="koopo-provider-editor-title" tabindex="-1">
        <header class="koopo-provider-modal__header"><div><span class="koopo-section-kicker"><?php esc_html_e('Profile settings', 'koopo-appointments'); ?></span><h2 id="koopo-provider-editor-title"><?php esc_html_e('Edit service profile', 'koopo-appointments'); ?></h2></div><button type="button" class="koopo-provider-icon-button" data-koopo-modal-close aria-label="<?php esc_attr_e('Close profile editor', 'koopo-appointments'); ?>">×</button></header>
        <div class="koopo-provider-modal__body koopo-provider-editor">
      <label class="koopo-label koopo-provider-picker-fallback"><?php esc_html_e('Service profile', 'appointments'); ?><select class="koopo-input" id="koopo-provider-picker"><option value=""><?php esc_html_e('Select service profile…', 'appointments'); ?></option></select></label>
      <div class="koopo-provider-image-editor">
        <div class="koopo-provider-image-preview" data-koopo-provider-edit-preview><span aria-hidden="true">+</span></div>
        <div><strong><?php esc_html_e('Service profile image', 'koopo-appointments'); ?></strong><p><?php esc_html_e('Upload a square-friendly portrait or work photo. Images go directly to Koopo storage.', 'koopo-appointments'); ?></p><label class="koopo-btn koopo-btn--secondary"><?php esc_html_e('Choose image', 'koopo-appointments'); ?><input id="koopo-provider-edit-image" type="file" accept="image/jpeg,image/png,image/webp,image/avif" hidden /></label><span class="koopo-provider-image-status" aria-live="polite"></span></div>
      </div>
      <section class="koopo-provider-gallery-editor">
        <div><span class="koopo-section-kicker"><?php esc_html_e('Portfolio', 'koopo-appointments'); ?></span><h3><?php esc_html_e('Show your work', 'koopo-appointments'); ?></h3><p><?php printf(esc_html__('Add up to %d photos. Drop several images at once, then drag them into the order customers should see. Photos upload directly to Koopo storage.', 'koopo-appointments'), \Koopo_Appointments\Provider_Profiles::GALLERY_LIMIT); ?></p></div>
        <label class="koopo-provider-portfolio-drop" data-koopo-provider-gallery-drop tabindex="0">
          <input id="koopo-provider-gallery-files" type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple hidden />
          <span class="koopo-provider-portfolio-drop__icon" aria-hidden="true">＋</span>
          <strong><?php esc_html_e('Drop portfolio photos here', 'koopo-appointments'); ?></strong>
          <small><?php esc_html_e('or choose JPEG, PNG, WebP, or AVIF images', 'koopo-appointments'); ?></small>
        </label>
        <div class="koopo-provider-gallery-grid" data-koopo-provider-gallery></div>
        <span class="koopo-provider-gallery-status" aria-live="polite"></span>
      </section>
      <div class="koopo-form-grid">
        <label class="koopo-label"><?php esc_html_e('Service profile name', 'appointments'); ?><input class="koopo-input" id="koopo-provider-edit-name" /></label>
        <label class="koopo-label"><?php esc_html_e('Primary service category', 'koopo-appointments'); ?><select class="koopo-input" id="koopo-provider-edit-category"><option value=""><?php esc_html_e('Choose what you offer…', 'koopo-appointments'); ?></option><?php foreach ($koopo_profile_categories as $category) : ?><option value="<?php echo esc_attr($category['id']); ?>"><?php echo esc_html(($category['glyph'] ? $category['glyph'] . ' ' : '') . $category['name']); ?></option><?php endforeach; ?></select><small><?php esc_html_e('Inherited by every service on this profile.', 'koopo-appointments'); ?></small></label>
        <label class="koopo-label"><?php esc_html_e('Headline', 'appointments'); ?><input class="koopo-input" id="koopo-provider-edit-headline" /></label>
        <label class="koopo-label koopo-label--full"><?php esc_html_e('About', 'appointments'); ?><textarea class="koopo-input" rows="6" id="koopo-provider-edit-bio"></textarea></label>
        <label><input type="checkbox" class="koopo-provider-edit-mode" value="at_location" /> <?php esc_html_e('At a business location', 'appointments'); ?></label>
        <label><input type="checkbox" class="koopo-provider-edit-mode" value="mobile" /> <?php esc_html_e('Mobile service', 'appointments'); ?></label>
        <label><input type="checkbox" class="koopo-provider-edit-mode" value="virtual" /> <?php esc_html_e('Virtual service', 'appointments'); ?></label>
      </div>
      <section class="koopo-provider-location-editor"><div><span class="koopo-section-kicker"><?php esc_html_e('Map discovery', 'koopo-appointments'); ?></span><h3><?php esc_html_e('Independent location', 'koopo-appointments'); ?></h3><p><?php esc_html_e('This can appear alongside approved business affiliations. Exact coordinates place the map marker.', 'koopo-appointments'); ?></p></div><div class="koopo-form-grid"><label class="koopo-label"><?php esc_html_e('Location name', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-location-name" /></label><label class="koopo-label"><?php esc_html_e('Street address', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-address" /></label><label class="koopo-label"><?php esc_html_e('City', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-city" /></label><label class="koopo-label"><?php esc_html_e('State or region', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-region" /></label><label class="koopo-label"><?php esc_html_e('Postal code', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-postal" /></label><label class="koopo-label"><?php esc_html_e('Country', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-edit-country" /></label><label class="koopo-label"><?php esc_html_e('Latitude', 'koopo-appointments'); ?><input class="koopo-input" type="number" step="0.0000001" id="koopo-provider-edit-latitude" /></label><label class="koopo-label"><?php esc_html_e('Longitude', 'koopo-appointments'); ?><input class="koopo-input" type="number" step="0.0000001" id="koopo-provider-edit-longitude" /></label></div><p><button type="button" class="koopo-btn koopo-btn--secondary" data-koopo-use-location="edit"><?php esc_html_e('Use my current map position', 'koopo-appointments'); ?></button> <span data-koopo-location-status="edit" aria-live="polite"></span></p><label><input type="checkbox" id="koopo-provider-edit-location-public" /> <?php esc_html_e('Show this location publicly and on the map', 'koopo-appointments'); ?></label></section>
      <section class="koopo-provider-service-area" data-koopo-service-area>
        <div><span class="koopo-section-kicker"><?php esc_html_e('Mobile services', 'koopo-appointments'); ?></span><h3><?php esc_html_e('Service area', 'koopo-appointments'); ?></h3><p><?php esc_html_e('Set the private starting point and maximum distance you travel. Customers see only the public coverage label and an approximate area.', 'koopo-appointments'); ?></p></div>
        <label><input type="checkbox" id="koopo-provider-area-enabled" /> <?php esc_html_e('Accept appointments at customer locations', 'koopo-appointments'); ?></label>
        <div class="koopo-form-grid koopo-provider-area-fields">
          <label class="koopo-label koopo-label--full"><?php esc_html_e('Private starting address', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-address" autocomplete="street-address" /><small><?php esc_html_e('Used only to calculate coverage. It is not displayed publicly.', 'koopo-appointments'); ?></small></label>
          <label class="koopo-label"><?php esc_html_e('City', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-city" autocomplete="address-level2" /></label>
          <label class="koopo-label"><?php esc_html_e('State or region', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-region" autocomplete="address-level1" /></label>
          <label class="koopo-label"><?php esc_html_e('Postal code', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-postal" autocomplete="postal-code" /></label>
          <label class="koopo-label"><?php esc_html_e('Country', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-country" value="United States" autocomplete="country-name" /></label>
          <label class="koopo-label"><?php esc_html_e('Travel radius', 'koopo-appointments'); ?><span class="koopo-input-with-unit"><input class="koopo-input" type="number" min="1" max="100" step="1" id="koopo-provider-area-radius" value="20" /><b><?php esc_html_e('miles', 'koopo-appointments'); ?></b></span></label>
          <label class="koopo-label"><?php esc_html_e('Travel buffer', 'koopo-appointments'); ?><select class="koopo-input" id="koopo-provider-area-buffer"><option value="0"><?php esc_html_e('No extra buffer', 'koopo-appointments'); ?></option><option value="15">15 minutes</option><option value="30" selected>30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option><option value="90">90 minutes</option></select></label>
          <label class="koopo-label koopo-label--full"><?php esc_html_e('Public coverage label', 'koopo-appointments'); ?><input class="koopo-input" id="koopo-provider-area-label" placeholder="Serving Detroit and nearby communities" /></label>
        </div>
        <p><button type="button" class="koopo-btn koopo-btn--secondary" id="koopo-provider-area-test"><?php esc_html_e('Validate area', 'koopo-appointments'); ?></button> <span class="koopo-provider-area-status" aria-live="polite"></span></p>
      </section>
      <section class="koopo-provider-virtual" data-koopo-virtual>
        <div><span class="koopo-section-kicker"><?php esc_html_e('Virtual services', 'koopo-appointments'); ?></span><h3><?php esc_html_e('Online meeting delivery', 'koopo-appointments'); ?></h3><p><?php esc_html_e('Choose how confirmed customers receive meeting access. Meeting links stay private.', 'koopo-appointments'); ?></p></div>
        <div class="koopo-form-grid">
          <label class="koopo-label"><?php esc_html_e('Meeting method', 'koopo-appointments'); ?><select class="koopo-input" id="koopo-provider-virtual-method"><option value="provider_sends"><?php esc_html_e('I send meeting details', 'koopo-appointments'); ?></option><option value="custom_link"><?php esc_html_e('Use another private meeting link', 'koopo-appointments'); ?></option><option value="google_meet"><?php esc_html_e('Use my Google Meet link', 'koopo-appointments'); ?></option><option value="zoom"><?php esc_html_e('Use my Zoom link', 'koopo-appointments'); ?></option></select></label>
          <label class="koopo-label"><?php esc_html_e('Private meeting link', 'koopo-appointments'); ?><input class="koopo-input" type="url" id="koopo-provider-virtual-url" placeholder="https://…" /></label>
          <label class="koopo-label koopo-label--full"><?php esc_html_e('Customer instructions', 'koopo-appointments'); ?><textarea class="koopo-input" rows="3" id="koopo-provider-virtual-instructions" placeholder="Join five minutes early…"></textarea></label>
        </div>
      </section>
      <p><button type="button" class="koopo-btn koopo-btn--gold" id="koopo-provider-edit-save"><?php esc_html_e('Save Service Profile', 'appointments'); ?></button> <a class="koopo-btn koopo-btn--secondary is-disabled" id="koopo-provider-view" href="#" target="_blank" rel="noopener"><?php esc_html_e('View Service Profile', 'appointments'); ?></a></p>
      <div class="koopo-provider-edit-status" aria-live="polite"></div>
        </div>
      </section>
    </div>
  </div>
  <?php do_action('dokan_dashboard_content_after'); ?>
</div>
