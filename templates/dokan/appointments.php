<?php
defined('ABSPATH') || exit;
$koopo_sms_status = class_exists('\Koopo_Appointments\SMS_Provider') ? \Koopo_Appointments\SMS_Provider::status() : ['ready'=>false,'unavailable_reason'=>'not_configured'];
$koopo_sms_unavailable = in_array((string)($koopo_sms_status['unavailable_reason']??''), ['paused','sms_daily_limit_reached','sms_monthly_limit_reached'], true)
  ? __('(SMS is temporarily unavailable)', 'appointments')
  : __('(SMS is not configured by the site administrator)', 'appointments');
?>
<div class="dokan-dashboard-wrap">
<?php
            do_action( 'dokan_dashboard_content_before' );
    ?>
  <div class="dokan-dashboard-content koopo-vendor-page">

  <?php if ( ! \Koopo_Appointments\Dokan_Dashboard::vendor_has_booking_contexts( get_current_user_id() ) ) : ?>
    <?php \Koopo_Appointments\Dokan_Dashboard::render_no_listing_cta(); ?>
    </div>
    </div>
    <?php return; ?>
  <?php endif; ?>
  
  <header class="koopo-vendor-header">
    <h2 class="koopo-page-title">Appointments</h2>
    <div class="koopo-vendor-header__right">
      <a id="koopo-view-listing-appointments" class="koopo-btn koopo-btn--secondary koopo-view-listing is-disabled" href="#" target="_blank" rel="noopener noreferrer">
        <?php esc_html_e('View Listing', 'appointments'); ?>
      </a>
    </div>
  </header>

  <div id="koopo-appointments-analytics" class="koopo-appointments-analytics">
    <div class="koopo-analytics-loading">
      <div class="koopo-analytics-skeletons">
        <div class="koopo-analytics-skeleton-card"></div>
        <div class="koopo-analytics-skeleton-card"></div>
        <div class="koopo-analytics-skeleton-card"></div>
        <div class="koopo-analytics-skeleton-chart"></div>
        <div class="koopo-analytics-skeleton-list">
          <div class="koopo-analytics-skeleton-line"></div>
          <div class="koopo-analytics-skeleton-line"></div>
          <div class="koopo-analytics-skeleton-line"></div>
        </div>
      </div>
    </div>
    <div class="koopo-analytics-cards">
      <div class="koopo-analytics-card">
        <span>Total Appointments</span>
        <strong data-stat="total">0</strong>
      </div>
      <div class="koopo-analytics-card">
        <span>Total Cancellations</span>
        <strong data-stat="cancelled">0</strong>
      </div>
      <div class="koopo-analytics-card">
        <span>Total Earnings</span>
        <strong data-stat="earnings">$0.00</strong>
      </div>
    </div>
    <div class="koopo-analytics-chart">
      <div class="koopo-analytics-pie" aria-label="Appointments by service"></div>
      <div class="koopo-analytics-legend"></div>
    </div>
  </div>

  <div class="koopo-row koopo-row--gap">
    <div class="koopo-field">
      <label for="koopo-appointments-picker">Booking profile</label>
      <select id="koopo-appointments-picker" class="koopo-select">
        <option value="">Loading…</option>
      </select>
    </div>

    <div class="koopo-field">
      <label for="koopo-appointments-status">Status</label>
      <select id="koopo-appointments-status" class="koopo-select">
        <option value="all">All</option>
        <option value="pending_invitation">Awaiting Customer</option>
        <option value="pending_payment">Pending Payment</option>
        <option value="confirmed">Confirmed</option>
        <option value="expired">Expired</option>
        <option value="cancelled">Cancelled</option>
        <option value="refunded">Refunded</option>
      </select>
    </div>

    <div class="koopo-field">
      <label for="koopo-appointments-search">Search Customer</label>
      <input type="text" id="koopo-appointments-search" class="koopo-input" placeholder="Search by name, email, or phone..." />
    </div>

    <div class="koopo-field">
      <label for="koopo-appointments-month">Month</label>
      <select id="koopo-appointments-month" class="koopo-select">
        <option value="">All Months</option>
        <option value="1">January</option>
        <option value="2">February</option>
        <option value="3">March</option>
        <option value="4">April</option>
        <option value="5">May</option>
        <option value="6">June</option>
        <option value="7">July</option>
        <option value="8">August</option>
        <option value="9">September</option>
        <option value="10">October</option>
        <option value="11">November</option>
        <option value="12">December</option>
      </select>
    </div>

    <div class="koopo-field">
      <label for="koopo-appointments-year">Year</label>
      <select id="koopo-appointments-year" class="koopo-select">
        <option value="">All Years</option>
      </select>
    </div>

    <div class="koopo-field">
      <label style="opacity: 0;">Export</label>
      <button id="koopo-appointments-export" class="koopo-btn koopo-btn--secondary" style="width: 100%;">Export to CSV</button>
    </div>
  </div>

  <div class="koopo-appointments-toolbar">
    <div class="koopo-view-toggle">
      <button type="button" class="koopo-btn koopo-btn--sm koopo-view-btn is-active" data-view="table">Table</button>
      <button type="button" class="koopo-btn koopo-btn--sm koopo-view-btn" data-view="calendar">Calendar</button>
    </div>
    <div class="koopo-toolbar-actions">
      <button type="button" id="koopo-appt-create" class="koopo-btn koopo-btn--gold">Create Appointment</button>
    </div>
  </div>

  <div id="koopo-appointments-table" class="koopo-card koopo-table-wrap">
    <div class="koopo-muted">Pick a listing to load appointments.</div>
  </div>

  <div id="koopo-appointments-calendar" class="koopo-card koopo-calendar-view" style="display:none;">
    <div class="koopo-calendar-header">
      <div class="koopo-calendar-title"></div>
      <div class="koopo-calendar-controls">
        <div class="koopo-calendar-view-toggle">
          <button type="button" class="koopo-btn koopo-btn--sm koopo-cal-view is-active" data-view="month">Month</button>
          <button type="button" class="koopo-btn koopo-btn--sm koopo-cal-view" data-view="week">Week</button>
          <button type="button" class="koopo-btn koopo-btn--sm koopo-cal-view" data-view="day">Day</button>
          <button type="button" class="koopo-btn koopo-btn--sm koopo-cal-view koopo-cal-view--agenda" data-view="agenda">Agenda</button>
        </div>
        
      </div>
      <div class="koopo-calendar-nav">
          <button type="button" class="koopo-btn koopo-cal-prev">‹</button>
          <button type="button" class="koopo-btn  koopo-cal-today">Today</button>
          <button type="button" class="koopo-btn koopo-cal-next">›</button>
        </div>
    </div>
    <div id="koopo-calendar-body"></div>
  </div>

  <div id="koopo-appointments-pagination" class="koopo-pagination"></div>

  <div class="koopo-modal" id="koopo-appt-create-modal" style="display:none;">
    <div class="koopo-modal__card koopo-modal__card--wide">
      <div class="koopo-modal__loading" style="display:none;">
        <div class="koopo-spinner"></div>
        <div class="koopo-modal__loading-text">Loading...</div>
      </div>
      <button class="koopo-modal__close" type="button">X</button>
      <h3><?php esc_html_e('Create Appointment', 'appointments'); ?></h3>

      <div class="koopo-form-grid">
        <label class="koopo-label">
          <?php esc_html_e('Service', 'appointments'); ?>
          <select class="koopo-input" id="koopo-appt-service">
            <option value=""><?php esc_html_e('Select a service...', 'appointments'); ?></option>
          </select>
        </label>

        <label class="koopo-label">
          <?php esc_html_e('Date', 'appointments'); ?>
          <input type="date" class="koopo-input" id="koopo-appt-date" />
        </label>

        <label class="koopo-label koopo-label--full" id="koopo-appt-fulfillment-wrap">
          <?php esc_html_e('Appointment location', 'koopo-appointments'); ?>
          <select class="koopo-input" id="koopo-appt-fulfillment">
            <option value="at_location"><?php esc_html_e('At the provider location', 'koopo-appointments'); ?></option>
          </select>
        </label>

        <div class="koopo-form-grid koopo-label--full" id="koopo-appt-mobile-address" style="display:none;">
          <label class="koopo-label koopo-label--full"><?php esc_html_e('Customer service address', 'koopo-appointments'); ?><input type="text" class="koopo-input" id="koopo-appt-address-1" autocomplete="street-address" /></label>
          <label class="koopo-label"><?php esc_html_e('City', 'koopo-appointments'); ?><input type="text" class="koopo-input" id="koopo-appt-city" autocomplete="address-level2" /></label>
          <label class="koopo-label"><?php esc_html_e('State/Region', 'koopo-appointments'); ?><input type="text" class="koopo-input" id="koopo-appt-region" autocomplete="address-level1" /></label>
          <label class="koopo-label"><?php esc_html_e('Postal code', 'koopo-appointments'); ?><input type="text" class="koopo-input" id="koopo-appt-postal-code" autocomplete="postal-code" /></label>
          <label class="koopo-label"><?php esc_html_e('Country', 'koopo-appointments'); ?><input type="text" class="koopo-input" id="koopo-appt-country" value="United States" autocomplete="country-name" /></label>
        </div>

        <div class="koopo-label koopo-label--full">
          <?php esc_html_e('Available Times', 'appointments'); ?>
          <div class="koopo-appt-slot-list" id="koopo-appt-slot-list">
            <div class="koopo-muted"><?php esc_html_e('Select a service and date to view available times.', 'appointments'); ?></div>
          </div>
        </div>

        <label class="koopo-label koopo-label--full">
          <?php esc_html_e('Customer Type', 'appointments'); ?>
          <div class="koopo-inline-toggle">
            <label class="koopo-inline-toggle__item">
              <input type="radio" name="koopo-appt-customer-type" value="user" checked />
              <span><?php esc_html_e('Existing User', 'appointments'); ?></span>
            </label>
            <label class="koopo-inline-toggle__item">
              <input type="radio" name="koopo-appt-customer-type" value="guest" />
              <span><?php esc_html_e('Non-User / Guest', 'appointments'); ?></span>
            </label>
          </div>
        </label>

        <div class="koopo-appt-customer koopo-appt-customer--user">
          <label class="koopo-label">
            <?php esc_html_e('User Email', 'appointments'); ?>
            <input type="email" class="koopo-input" id="koopo-appt-user-email" placeholder="user@email.com" />
          </label>
          <label class="koopo-label">
            <?php esc_html_e('User ID (optional)', 'appointments'); ?>
            <input type="number" class="koopo-input" id="koopo-appt-user-id" min="1" />
          </label>
        </div>

        <div class="koopo-appt-customer koopo-appt-customer--guest" style="display:none;">
          <label class="koopo-label">
            <?php esc_html_e('Guest Name', 'appointments'); ?>
            <input type="text" class="koopo-input" id="koopo-appt-guest-name" />
          </label>
          <label class="koopo-label">
            <?php esc_html_e('Guest Email', 'appointments'); ?>
            <input type="email" class="koopo-input" id="koopo-appt-guest-email" />
          </label>
          <label class="koopo-label">
            <?php esc_html_e('Guest Phone', 'appointments'); ?>
            <input type="text" class="koopo-input" id="koopo-appt-guest-phone" />
          </label>
          <div class="koopo-label koopo-label--full koopo-appt-invite-options">
            <strong><?php esc_html_e('Registration invitation', 'appointments'); ?></strong>
            <p class="koopo-muted"><?php esc_html_e('The appointment is held while this person creates a Koopo account. After registration, reminders move to their Koopo inbox, push notifications, and email.', 'appointments'); ?></p>
            <label><input type="checkbox" id="koopo-appt-invite-email" checked /> <?php esc_html_e('Send email invitation', 'appointments'); ?></label>
            <label><input type="checkbox" id="koopo-appt-invite-sms" <?php disabled(empty($koopo_sms_status['ready'])); ?> /> <?php esc_html_e('Send one text invitation', 'appointments'); ?><?php if(empty($koopo_sms_status['ready'])): ?> <span class="koopo-muted"><?php echo esc_html($koopo_sms_unavailable); ?></span><?php endif; ?></label>
            <div class="koopo-appt-sms-consent" style="display:none" data-method="<?php echo esc_attr(\Koopo_Appointments\SMS_Compliance::CONSENT_METHOD); ?>" data-version="<?php echo esc_attr(\Koopo_Appointments\SMS_Compliance::DISCLOSURE_VERSION); ?>">
              <div class="koopo-sms-disclosure">
                <strong><?php esc_html_e('Read this disclosure to the customer', 'appointments'); ?></strong>
                <p>&ldquo;<?php echo esc_html(\Koopo_Appointments\SMS_Compliance::DISCLOSURE); ?>&rdquo;</p>
              </div>
              <label><input type="checkbox" id="koopo-appt-sms-consent" /> <?php echo esc_html(\Koopo_Appointments\SMS_Compliance::CONFIRMATION); ?></label>
              <p class="koopo-muted"><?php esc_html_e('This consent covers one transactional appointment invitation only.', 'appointments'); ?></p>
            </div>
            <label><?php esc_html_e('Hold the time for', 'appointments'); ?>
              <select class="koopo-input" id="koopo-appt-invite-hold">
                <option value="30"><?php esc_html_e('30 minutes', 'appointments'); ?></option>
                <option value="120" selected><?php esc_html_e('2 hours', 'appointments'); ?></option>
                <option value="720"><?php esc_html_e('12 hours', 'appointments'); ?></option>
                <option value="1440"><?php esc_html_e('24 hours', 'appointments'); ?></option>
              </select>
            </label>
          </div>
        </div>

        <label class="koopo-label koopo-label--full">
          <?php esc_html_e('Notes (optional)', 'appointments'); ?>
          <textarea class="koopo-input" id="koopo-appt-notes" rows="3"></textarea>
        </label>

        <div class="koopo-section-title"><?php esc_html_e('Add-ons', 'appointments'); ?></div>

        <div class="koopo-appt-addons">
          <div id="koopo-appt-addon-options" class="koopo-appt-addon-options"></div>
          <div id="koopo-appt-addon-selected" class="koopo-appt-addon-selected"></div>
        </div>

        <div class="koopo-appt-total">
          <span><?php esc_html_e('Total', 'appointments'); ?></span>
          <strong id="koopo-appt-total-amount">$0.00</strong>
        </div>

        <label class="koopo-label koopo-appt-status-control">
          <?php esc_html_e('Status', 'appointments'); ?>
          <select class="koopo-input" id="koopo-appt-status">
            <option value="confirmed"><?php esc_html_e('Confirmed', 'appointments'); ?></option>
            <option value="pending_payment"><?php esc_html_e('Pending Payment', 'appointments'); ?></option>
          </select>
        </label>
      </div>

      <div class="koopo-modal__footer koopo-modal__footer--between">
        <button type="button" class="koopo-btn" id="koopo-appt-create-cancel"><?php esc_html_e('Cancel', 'appointments'); ?></button>
        <button type="button" class="koopo-btn koopo-btn--gold" id="koopo-appt-create-save"><?php esc_html_e('Create Appointment', 'appointments'); ?></button>
      </div>
    </div>
  </div>

  <div class="koopo-modal" id="koopo-appt-details-modal" style="display:none;">
    <div class="koopo-modal__card koopo-modal__card--wide">
      <button class="koopo-modal__close" type="button">&times;</button>
      <h3><?php esc_html_e('Appointment Details', 'appointments'); ?></h3>

      <div class="koopo-appt-details">
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Customer', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-customer"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Customer Info', 'appointments'); ?></div>
          <div class="koopo-appt-details__value">
            <div class="koopo-appt-details__meta" id="koopo-appt-details-meta"></div>
          </div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Service', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-service"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Add-ons', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-addons"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Duration', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-duration"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('When', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-when"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Status', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-status"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Cancellation', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-cancelled"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Refund', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-refund"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Total', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-total"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Price Breakdown', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-pricing"></div>
        </div>
        <div class="koopo-appt-details__row" id="koopo-appt-details-sms-evidence-row" style="display:none">
          <div class="koopo-appt-details__label"><?php esc_html_e('SMS consent evidence', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-sms-evidence"></div>
        </div>
      </div>

      <div class="koopo-appt-details__actions" id="koopo-appt-details-actions"></div>

      <div class="koopo-modal__footer koopo-modal__footer--between">
        <button type="button" class="koopo-btn" id="koopo-appt-details-close"><?php esc_html_e('Close', 'appointments'); ?></button>
      </div>
    </div>
  </div>
</div>
</div>
