<?php
defined('ABSPATH') || exit;
?>
<div class="dokan-dashboard-wrap">
<?php
            do_action( 'dokan_dashboard_content_before' );
    ?>
  <div class="dokan-dashboard-content koopo-vendor-page">
  
  <h2 class="koopo-page-title">Appointments</h2>

  <div class="koopo-row koopo-row--gap">
    <div class="koopo-field">
      <label for="koopo-appointments-picker">Listing</label>
      <select id="koopo-appointments-picker" class="koopo-select">
        <option value="">Loading…</option>
      </select>
    </div>

    <div class="koopo-field">
      <label for="koopo-appointments-status">Status</label>
      <select id="koopo-appointments-status" class="koopo-select">
        <option value="all">All</option>
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

        <label class="koopo-label">
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
          <div class="koopo-appt-details__label"><?php esc_html_e('Total', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-total"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Price Breakdown', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-pricing"></div>
        </div>
        <div class="koopo-appt-details__row">
          <div class="koopo-appt-details__label"><?php esc_html_e('Order', 'appointments'); ?></div>
          <div class="koopo-appt-details__value" id="koopo-appt-details-order"></div>
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
