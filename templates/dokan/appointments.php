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

  <div id="koopo-appointments-table" class="koopo-card koopo-table-wrap">
    <div class="koopo-muted">Pick a listing to load appointments.</div>
  </div>

  <div id="koopo-appointments-pagination" class="koopo-pagination"></div>
</div>
</div>
