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
  </div>

  <div id="koopo-appointments-table" class="koopo-card koopo-table-wrap">
    <div class="koopo-muted">Pick a listing to load appointments.</div>
  </div>

  <div id="koopo-appointments-pagination" class="koopo-pagination"></div>
</div>
</div>
