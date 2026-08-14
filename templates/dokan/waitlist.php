<?php defined('ABSPATH') || exit; ?>
<div class="dokan-dashboard-wrap">
  <?php do_action('dokan_dashboard_content_before'); ?>
  <div class="dokan-dashboard-content koopo-vendor-page koopo-waitlist-page">
    <header class="koopo-vendor-header">
      <div><span class="koopo-eyebrow"><?php esc_html_e('Cancellation recovery', 'koopo-appointments'); ?></span><h2><?php esc_html_e('Waitlist', 'koopo-appointments'); ?></h2><p><?php esc_html_e('Match an opening to the people who already want it.', 'koopo-appointments'); ?></p></div>
      <select class="koopo-input" data-koopo-waitlist-context><option value=""><?php esc_html_e('Select booking profile…', 'koopo-appointments'); ?></option></select>
    </header>
    <div class="koopo-card koopo-waitlist-settings" data-koopo-waitlist-settings hidden>
      <div><span class="koopo-eyebrow"><?php esc_html_e('Offer behavior', 'koopo-appointments'); ?></span><h3><?php esc_html_e('How should openings be offered?', 'koopo-appointments'); ?></h3></div>
      <label><?php esc_html_e('Mode', 'koopo-appointments'); ?><select class="koopo-input" data-wait-mode><option value="sequential"><?php esc_html_e('Priority order', 'koopo-appointments'); ?></option><option value="first_to_confirm"><?php esc_html_e('First to confirm', 'koopo-appointments'); ?></option><option value="manual"><?php esc_html_e('Manual offers only', 'koopo-appointments'); ?></option></select></label>
      <label><?php esc_html_e('Offer expires after', 'koopo-appointments'); ?><select class="koopo-input" data-wait-minutes><option value="5">5 min</option><option value="10">10 min</option><option value="15">15 min</option><option value="30">30 min</option><option value="60">60 min</option></select></label>
      <label><?php esc_html_e('First-to-confirm group', 'koopo-appointments'); ?><input class="koopo-input" type="number" min="1" max="20" data-wait-batch></label>
      <button class="koopo-btn koopo-btn--secondary" type="button" data-wait-save><?php esc_html_e('Save behavior', 'koopo-appointments'); ?></button><span data-wait-status role="status"></span>
    </div>
    <section class="koopo-waitlist-board" data-koopo-waitlist-board><div class="koopo-card"><p><?php esc_html_e('Choose a booking profile to view demand.', 'koopo-appointments'); ?></p></div></section>
  </div>
  <?php do_action('dokan_dashboard_content_after'); ?>
</div>
