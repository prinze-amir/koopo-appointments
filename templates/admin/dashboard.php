<?php
/**
 * Admin Dashboard Template
 * Location: templates/admin/dashboard.php
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

?>

<div class="wrap koopo-admin-dashboard">
  <h1 class="wp-heading-inline"><?php esc_html_e('Appointments Dashboard', 'koopo-appointments'); ?></h1>
  
  <hr class="wp-header-end">

  <!-- Stats Overview -->
  <div id="koopo-stats-overview" class="koopo-stats-grid">
    <div class="koopo-stat-card koopo-stat-card--loading">
      <div class="koopo-spinner"></div>
      <p><?php esc_html_e('Loading statistics...', 'koopo-appointments'); ?></p>
    </div>
  </div>

  <div class="koopo-quick-actions"><h2><?php esc_html_e('Provider Operations', 'koopo-appointments'); ?></h2><div id="koopo-operations-overview" class="koopo-stats-grid"></div><p class="description"><?php esc_html_e('Client records and external event details remain provider-private. Administrators see operational counts and sync health only.', 'koopo-appointments'); ?></p></div>

  <!-- Quick Actions -->
  <div class="koopo-quick-actions">
    <h2><?php esc_html_e('Quick Actions', 'koopo-appointments'); ?></h2>
    <div class="koopo-action-buttons">
      <a href="<?php echo esc_url(admin_url('admin.php?page=koopo-appointments-bookings')); ?>" class="button button-primary">
        <?php esc_html_e('View All Bookings', 'koopo-appointments'); ?>
      </a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=koopo-appointments-analytics')); ?>" class="button">
        <?php esc_html_e('View Analytics', 'koopo-appointments'); ?>
      </a>
      <a href="<?php echo esc_url(admin_url('edit.php?post_type=koopo_provider')); ?>" class="button">
        <?php esc_html_e('Service Profiles', 'koopo-appointments'); ?>
      </a>
      <a href="<?php echo esc_url(admin_url('edit.php?post_type=koopo_service')); ?>" class="button">
        <?php esc_html_e('Services', 'koopo-appointments'); ?>
      </a>
      <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=koopo_service_category&post_type=koopo_service')); ?>" class="button">
        <?php esc_html_e('Service Categories', 'koopo-appointments'); ?>
      </a>
      <a href="<?php echo esc_url(admin_url('options-general.php?page=koopo-appointments-settings')); ?>" class="button">
        <?php esc_html_e('Settings', 'koopo-appointments'); ?>
      </a>
    </div>
  </div>

  <!-- Recent Bookings -->
  <div class="koopo-recent-bookings">
    <h2><?php esc_html_e('Recent Bookings', 'koopo-appointments'); ?></h2>
    <div id="koopo-recent-list"></div>
  </div>

  <!-- Conflicts Alert -->
  <div id="koopo-conflicts-alert" style="display:none;">
    <div class="notice notice-warning">
      <p>
        <strong><?php esc_html_e('Attention:', 'koopo-appointments'); ?></strong>
        <span class="koopo-conflict-count"></span>
        <a href="<?php echo esc_url(admin_url('admin.php?page=koopo-appointments-bookings&status=conflict')); ?>">
          <?php esc_html_e('View Conflicts', 'koopo-appointments'); ?>
        </a>
      </p>
    </div>
  </div>
</div>

<script>
jQuery(document).ready(function($) {
  // Load statistics
  $.ajax({
    url: KOOPO_ADMIN.api_url + '/admin/stats',
    headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
    success: function(data) {
      renderStats(data);
      
      if (data.conflicts > 0) {
        $('#koopo-conflicts-alert').show();
        $('.koopo-conflict-count').text(
          data.conflicts + ' booking conflict' + (data.conflicts !== 1 ? 's' : '') + ' need resolution.'
        );
      }
    }
  });

  function renderStats(data) {
    const html = `
      <div class="koopo-stat-card">
        <div class="koopo-stat-icon">📅</div>
        <div class="koopo-stat-content">
          <div class="koopo-stat-value">${data.today.bookings}</div>
          <div class="koopo-stat-label">Today's Bookings</div>
        </div>
      </div>

      <div class="koopo-stat-card">
        <div class="koopo-stat-icon">💰</div>
        <div class="koopo-stat-content">
          <div class="koopo-stat-value">$${data.today.revenue.toFixed(2)}</div>
          <div class="koopo-stat-label">Today's Revenue</div>
        </div>
      </div>

      <div class="koopo-stat-card">
        <div class="koopo-stat-icon">📊</div>
        <div class="koopo-stat-content">
          <div class="koopo-stat-value">${data.week.bookings}</div>
          <div class="koopo-stat-label">This Week</div>
        </div>
      </div>

      <div class="koopo-stat-card">
        <div class="koopo-stat-icon">⏰</div>
        <div class="koopo-stat-content">
          <div class="koopo-stat-value">${data.upcoming}</div>
          <div class="koopo-stat-label">Upcoming</div>
        </div>
      </div>
    `;
    $('#koopo-stats-overview').html(html);
    const ops = data.operations || {};
    $('#koopo-operations-overview').html(`
      <div class="koopo-stat-card"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.waitlist_active || 0}</div><div class="koopo-stat-label">Waiting customers</div></div></div>
      <div class="koopo-stat-card"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.offers_live || 0}</div><div class="koopo-stat-label">Live opening offers</div></div></div>
      <div class="koopo-stat-card"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.clients || 0}</div><div class="koopo-stat-label">Private client records</div></div></div>
      <div class="koopo-stat-card"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.forms_pending || 0}</div><div class="koopo-stat-label">Forms outstanding</div></div></div>
      <div class="koopo-stat-card"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.busy_sources || 0}</div><div class="koopo-stat-label">Availability calendars</div></div></div>
      <div class="koopo-stat-card ${ops.busy_errors ? 'koopo-stat-card--warning' : ''}"><div class="koopo-stat-content"><div class="koopo-stat-value">${ops.busy_errors || 0}</div><div class="koopo-stat-label">Calendar sync errors</div></div></div>`);
  }

  // Load recent bookings
  $.ajax({
    url: KOOPO_ADMIN.api_url + '/admin/bookings?per_page=5&status=all',
    headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
    success: function(data) {
      if (data.items.length === 0) {
        $('#koopo-recent-list').html('<p>No recent bookings.</p>');
        return;
      }

      let html = '<table class="wp-list-table widefat fixed striped">';
      html += '<thead><tr>';
      html += '<th>ID</th><th>Customer</th><th>Service</th><th>Date/Time</th><th>Status</th>';
      html += '</tr></thead><tbody>';

      data.items.forEach(function(item) {
        html += '<tr>';
        html += '<td>#' + item.id + '</td>';
        html += '<td>' + item.customer_name + '</td>';
        html += '<td>' + item.service_title + '</td>';
        html += '<td>' + item.start_formatted + '</td>';
        html += '<td><span class="koopo-status-badge koopo-status-badge--' + item.status + '">' + 
                item.status + '</span></td>';
        html += '</tr>';
      });

      html += '</tbody></table>';
      $('#koopo-recent-list').html(html);
    }
  });
});
</script>
