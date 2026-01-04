<?php
/**
 * Admin Analytics Template
 * Location: templates/admin/analytics.php
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

?>

<div class="wrap koopo-admin-analytics">
  <h1 class="wp-heading-inline"><?php esc_html_e('Appointments Analytics', 'koopo-appointments'); ?></h1>
  
  <hr class="wp-header-end">

  <!-- Period Selector -->
  <div class="koopo-period-selector">
    <label><?php esc_html_e('Period:', 'koopo-appointments'); ?></label>
    <select id="koopo-analytics-period" class="koopo-period-select">
      <option value="7"><?php esc_html_e('Last 7 Days', 'koopo-appointments'); ?></option>
      <option value="30" selected><?php esc_html_e('Last 30 Days', 'koopo-appointments'); ?></option>
      <option value="90"><?php esc_html_e('Last 90 Days', 'koopo-appointments'); ?></option>
      <option value="365"><?php esc_html_e('Last Year', 'koopo-appointments'); ?></option>
    </select>
  </div>

  <!-- Overview Metrics -->
  <div id="koopo-analytics-overview" class="koopo-analytics-section">
    <h2><?php esc_html_e('Overview', 'koopo-appointments'); ?></h2>
    <div class="koopo-metrics-grid" id="koopo-metrics-cards">
      <div class="koopo-loading"><div class="koopo-spinner"></div></div>
    </div>
  </div>

  <!-- Revenue Chart -->
  <div class="koopo-analytics-section">
    <h2><?php esc_html_e('Revenue & Bookings', 'koopo-appointments'); ?></h2>
    <div class="koopo-chart-container">
      <canvas id="koopo-revenue-chart"></canvas>
    </div>
  </div>

  <!-- Two Column Layout -->
  <div class="koopo-analytics-row">
    
    <!-- Popular Services -->
    <div class="koopo-analytics-column">
      <div class="koopo-analytics-section">
        <h2><?php esc_html_e('Popular Services', 'koopo-appointments'); ?></h2>
        <div id="koopo-popular-services">
          <div class="koopo-loading"><div class="koopo-spinner"></div></div>
        </div>
      </div>
    </div>

    <!-- Top Vendors -->
    <div class="koopo-analytics-column">
      <div class="koopo-analytics-section">
        <h2><?php esc_html_e('Top Vendors', 'koopo-appointments'); ?></h2>
        <div id="koopo-top-vendors">
          <div class="koopo-loading"><div class="koopo-spinner"></div></div>
        </div>
      </div>
    </div>

  </div>

  <!-- Booking Trends -->
  <div class="koopo-analytics-section">
    <h2><?php esc_html_e('Booking Trends', 'koopo-appointments'); ?></h2>
    <div class="koopo-trends-grid" id="koopo-trends">
      <div class="koopo-loading"><div class="koopo-spinner"></div></div>
    </div>
  </div>

</div>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
jQuery(document).ready(function($) {
  let currentPeriod = 30;
  let revenueChart = null;

  function loadAnalytics() {
    loadOverview();
    loadRevenue();
    loadPopularServices();
    loadTopVendors();
    loadTrends();
  }

  function loadOverview() {
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/analytics/overview?period=' + currentPeriod,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderOverview(data);
      }
    });
  }

  function renderOverview(data) {
    const metrics = data.metrics;
    const changes = data.changes;

    const html = `
      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Total Bookings</div>
        <div class="koopo-metric-value">${metrics.total_bookings.toLocaleString()}</div>
        <div class="koopo-metric-change ${changes.bookings_change_percent >= 0 ? 'positive' : 'negative'}">
          ${changes.bookings_change_percent >= 0 ? '↑' : '↓'} ${Math.abs(changes.bookings_change_percent).toFixed(1)}%
        </div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Total Revenue</div>
        <div class="koopo-metric-value">$${metrics.total_revenue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
        <div class="koopo-metric-change ${changes.revenue_change_percent >= 0 ? 'positive' : 'negative'}">
          ${changes.revenue_change_percent >= 0 ? '↑' : '↓'} ${Math.abs(changes.revenue_change_percent).toFixed(1)}%
        </div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Avg Booking Value</div>
        <div class="koopo-metric-value">$${metrics.avg_booking_value.toFixed(2)}</div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Cancellation Rate</div>
        <div class="koopo-metric-value">${metrics.cancellation_rate.toFixed(1)}%</div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">No-Show Rate</div>
        <div class="koopo-metric-value">${metrics.no_show_rate.toFixed(1)}%</div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Unique Customers</div>
        <div class="koopo-metric-value">${metrics.unique_customers.toLocaleString()}</div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Active Vendors</div>
        <div class="koopo-metric-value">${metrics.active_vendors.toLocaleString()}</div>
      </div>

      <div class="koopo-metric-card">
        <div class="koopo-metric-label">Confirmed Bookings</div>
        <div class="koopo-metric-value">${metrics.confirmed_bookings.toLocaleString()}</div>
      </div>
    `;

    $('#koopo-metrics-cards').html(html);
  }

  function loadRevenue() {
    const groupby = currentPeriod <= 30 ? 'day' : 'week';
    
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/analytics/revenue?period=' + currentPeriod + '&groupby=' + groupby,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderRevenueChart(data);
      }
    });
  }

  function renderRevenueChart(data) {
    const ctx = document.getElementById('koopo-revenue-chart').getContext('2d');
    
    if (revenueChart) {
      revenueChart.destroy();
    }

    const labels = data.data.map(d => d.period);
    const revenue = data.data.map(d => parseFloat(d.revenue));
    const bookings = data.data.map(d => parseInt(d.bookings));

    revenueChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Revenue ($)',
            data: revenue,
            borderColor: '#2c7a3c',
            backgroundColor: 'rgba(44, 122, 60, 0.1)',
            yAxisID: 'y',
            tension: 0.3
          },
          {
            label: 'Bookings',
            data: bookings,
            borderColor: '#4285f4',
            backgroundColor: 'rgba(66, 133, 244, 0.1)',
            yAxisID: 'y1',
            tension: 0.3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false,
        },
        scales: {
          y: {
            type: 'linear',
            display: true,
            position: 'left',
            title: {
              display: true,
              text: 'Revenue ($)'
            }
          },
          y1: {
            type: 'linear',
            display: true,
            position: 'right',
            title: {
              display: true,
              text: 'Bookings'
            },
            grid: {
              drawOnChartArea: false,
            }
          }
        }
      }
    });
  }

  function loadPopularServices() {
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/analytics/popular-services?period=' + currentPeriod,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderPopularServices(data);
      }
    });
  }

  function renderPopularServices(data) {
    if (data.length === 0) {
      $('#koopo-popular-services').html('<p>No data available.</p>');
      return;
    }

    let html = '<table class="widefat">';
    html += '<thead><tr><th>Service</th><th>Bookings</th><th>Revenue</th></tr></thead><tbody>';

    data.slice(0, 10).forEach(function(item) {
      html += '<tr>';
      html += '<td>' + item.service_title + '</td>';
      html += '<td>' + item.bookings + '</td>';
      html += '<td>$' + item.revenue.toFixed(2) + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    $('#koopo-popular-services').html(html);
  }

  function loadTopVendors() {
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/analytics/top-vendors?period=' + currentPeriod,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderTopVendors(data);
      }
    });
  }

  function renderTopVendors(data) {
    if (data.length === 0) {
      $('#koopo-top-vendors').html('<p>No data available.</p>');
      return;
    }

    let html = '<table class="widefat">';
    html += '<thead><tr><th>Vendor</th><th>Bookings</th><th>Revenue</th><th>Customers</th></tr></thead><tbody>';

    data.slice(0, 10).forEach(function(item) {
      html += '<tr>';
      html += '<td>' + item.vendor_name + '</td>';
      html += '<td>' + item.bookings + '</td>';
      html += '<td>$' + item.revenue.toFixed(2) + '</td>';
      html += '<td>' + item.unique_customers + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    $('#koopo-top-vendors').html(html);
  }

  function loadTrends() {
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/analytics/trends?period=' + currentPeriod,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderTrends(data);
      }
    });
  }

  function renderTrends(data) {
    let html = '<div class="koopo-trends-container">';

    // Peak hours
    html += '<div class="koopo-trend-card">';
    html += '<h3>Peak Booking Hours</h3>';
    html += '<ul class="koopo-trend-list">';
    data.peak_hours.forEach(function(item) {
      const hour = parseInt(item.hour);
      const time = hour === 0 ? '12 AM' : hour < 12 ? hour + ' AM' : hour === 12 ? '12 PM' : (hour - 12) + ' PM';
      html += '<li>' + time + ' <span class="koopo-trend-value">(' + item.bookings + ' bookings)</span></li>';
    });
    html += '</ul></div>';

    // Peak days
    html += '<div class="koopo-trend-card">';
    html += '<h3>Peak Booking Days</h3>';
    html += '<ul class="koopo-trend-list">';
    data.peak_days.forEach(function(item) {
      html += '<li>' + item.day_name + ' <span class="koopo-trend-value">(' + item.bookings + ' bookings)</span></li>';
    });
    html += '</ul></div>';

    // Lead time
    html += '<div class="koopo-trend-card">';
    html += '<h3>Average Lead Time</h3>';
    html += '<div class="koopo-trend-stat">' + data.avg_lead_time_days + ' days</div>';
    html += '<p class="koopo-trend-description">Average time between booking creation and appointment date</p>';
    html += '</div>';

    html += '</div>';
    $('#koopo-trends').html(html);
  }

  // Period change handler
  $('#koopo-analytics-period').on('change', function() {
    currentPeriod = parseInt($(this).val());
    loadAnalytics();
  });

  // Initial load
  loadAnalytics();
});
</script>
