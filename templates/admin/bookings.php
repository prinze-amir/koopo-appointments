<?php
/**
 * Admin Bookings Management Template
 * Location: templates/admin/bookings.php
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

?>

<div class="wrap koopo-admin-bookings">
  <h1 class="wp-heading-inline"><?php esc_html_e('All Bookings', 'koopo-appointments'); ?></h1>
  
  <hr class="wp-header-end">

  <!-- Filters -->
  <div class="koopo-filters">
    <div class="koopo-filter-row">
      <select id="koopo-filter-status" class="koopo-filter-select">
        <option value="all"><?php esc_html_e('All Statuses', 'koopo-appointments'); ?></option>
        <option value="confirmed"><?php esc_html_e('Confirmed', 'koopo-appointments'); ?></option>
        <option value="pending_payment"><?php esc_html_e('Pending Payment', 'koopo-appointments'); ?></option>
        <option value="cancelled"><?php esc_html_e('Cancelled', 'koopo-appointments'); ?></option>
        <option value="refunded"><?php esc_html_e('Refunded', 'koopo-appointments'); ?></option>
        <option value="expired"><?php esc_html_e('Expired', 'koopo-appointments'); ?></option>
        <option value="conflict"><?php esc_html_e('Conflicts', 'koopo-appointments'); ?></option>
      </select>

      <input type="date" id="koopo-filter-date-from" class="koopo-filter-input" placeholder="<?php esc_attr_e('From', 'koopo-appointments'); ?>" />
      <input type="date" id="koopo-filter-date-to" class="kooop-filter-input" placeholder="<?php esc_attr_e('To', 'koopo-appointments'); ?>" />
      
      <input type="text" id="koopo-filter-search" class="koopo-filter-input" placeholder="<?php esc_attr_e('Search by ID...', 'koopo-appointments'); ?>" />
      
      <button type="button" id="koopo-filter-apply" class="button"><?php esc_html_e('Filter', 'koopo-appointments'); ?></button>
      <button type="button" id="koopo-filter-reset" class="button"><?php esc_html_e('Reset', 'koopo-appointments'); ?></button>
    </div>
  </div>

  <!-- Bulk Actions -->
  <div class="tablenav top">
    <div class="alignleft actions bulkactions">
      <select id="koopo-bulk-action" class="koopo-bulk-select">
        <option value=""><?php esc_html_e('Bulk Actions', 'koopo-appointments'); ?></option>
        <option value="confirm"><?php esc_html_e('Confirm', 'koopo-appointments'); ?></option>
        <option value="cancel"><?php esc_html_e('Cancel', 'koopo-appointments'); ?></option>
        <option value="delete"><?php esc_html_e('Delete', 'koopo-appointments'); ?></option>
      </select>
      <button type="button" id="koopo-bulk-apply" class="button action"><?php esc_html_e('Apply', 'koopo-appointments'); ?></button>
    </div>

    <div class="alignleft actions">
      <button type="button" id="koopo-export-csv" class="button"><?php esc_html_e('Export CSV', 'koopo-appointments'); ?></button>
    </div>
  </div>

  <!-- Bookings Table -->
  <div id="koopo-bookings-table-container">
    <div class="koopo-loading">
      <div class="koopo-spinner"></div>
      <p><?php esc_html_e('Loading bookings...', 'koopo-appointments'); ?></p>
    </div>
  </div>

  <!-- Pagination -->
  <div class="tablenav bottom">
    <div class="tablenav-pages" id="koopo-pagination"></div>
  </div>
</div>

<script>
jQuery(document).ready(function($) {
  let currentFilters = {
    status: 'all',
    date_from: '',
    date_to: '',
    search: '',
    page: 1
  };

  function loadBookings() {
    const params = new URLSearchParams(currentFilters);
    
    $('#koopo-bookings-table-container').html('<div class="koopo-loading"><div class="koopo-spinner"></div><p>Loading...</p></div>');

    $.ajax({
      url: KOOPO_ADMIN.api_url + '/admin/bookings?' + params.toString(),
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        renderBookingsTable(data.items);
        renderPagination(data.pagination);
      },
      error: function() {
        $('#koopo-bookings-table-container').html('<div class="notice notice-error"><p>Failed to load bookings.</p></div>');
      }
    });
  }

  function renderBookingsTable(items) {
    if (items.length === 0) {
      $('#koopo-bookings-table-container').html('<p>No bookings found.</p>');
      return;
    }

    let html = '<table class="wp-list-table widefat fixed striped">';
    html += '<thead><tr>';
    html += '<td class="check-column"><input type="checkbox" id="koopo-select-all" /></td>';
    html += '<th>ID</th><th>Customer</th><th>Vendor</th><th>Service</th><th>Date/Time</th>';
    html += '<th>Duration</th><th>Status</th><th>Price</th><th>Order</th><th>Actions</th>';
    html += '</tr></thead><tbody>';

    items.forEach(function(item) {
      const rowClass = item.status === 'conflict' ? 'koopo-row--conflict' : '';
      html += '<tr class="' + rowClass + '">';
      html += '<th class="check-column"><input type="checkbox" class="koopo-booking-checkbox" value="' + item.id + '" /></th>';
      html += '<td><strong>#' + item.id + '</strong></td>';
      html += '<td>' + item.customer_name + '<br><small>' + item.customer_email + '</small></td>';
      html += '<td>' + item.vendor_name + '</td>';
      html += '<td>' + item.service_title + '<br><small>' + item.listing_title + '</small></td>';
      html += '<td>' + item.start_formatted + '</td>';
      html += '<td>' + item.duration_formatted + '</td>';
      html += '<td><span class="koopo-status-badge koopo-status--' + item.status + '">' + item.status + '</span></td>';
      html += '<td>$' + item.price.toFixed(2) + '</td>';
      html += '<td>' + (item.wc_order_id ? '<a href="post.php?post=' + item.wc_order_id + '&action=edit">#' + item.wc_order_id + '</a>' : '—') + '</td>';
      html += '<td><a href="admin.php?page=koopo-booking-detail&id=' + item.id + '">View</a></td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    $('#koopo-bookings-table-container').html(html);
  }

  function renderPagination(pagination) {
    if (pagination.total_pages <= 1) {
      $('#koopo-pagination').html('');
      return;
    }

    let html = '<span class="displaying-num">' + pagination.total + ' items</span>';
    html += '<span class="pagination-links">';
    
    if (pagination.page > 1) {
      html += '<a class="button" data-page="1">«</a> ';
      html += '<a class="button" data-page="' + (pagination.page - 1) + '">‹</a> ';
    }

    html += '<span class="paging-input">';
    html += '<label class="screen-reader-text">Current Page</label>';
    html += '<input class="current-page" type="text" value="' + pagination.page + '" size="2" /> ';
    html += '<span class="tablenav-paging-text">of <span class="total-pages">' + pagination.total_pages + '</span></span>';
    html += '</span>';

    if (pagination.page < pagination.total_pages) {
      html += '<a class="button" data-page="' + (pagination.page + 1) + '">›</a> ';
      html += '<a class="button" data-page="' + pagination.total_pages + '">»</a>';
    }

    html += '</span>';
    $('#koopo-pagination').html(html);
  }

  // Event handlers
  $('#koopo-filter-apply').on('click', function() {
    currentFilters.status = $('#koopo-filter-status').val();
    currentFilters.date_from = $('#koopo-filter-date-from').val();
    currentFilters.date_to = $('#koopo-filter-date-to').val();
    currentFilters.search = $('#koopo-filter-search').val();
    currentFilters.page = 1;
    loadBookings();
  });

  $('#koopo-filter-reset').on('click', function() {
    currentFilters = { status: 'all', date_from: '', date_to: '', search: '', page: 1 };
    $('#koopo-filter-status').val('all');
    $('#koopo-filter-date-from').val('');
    $('#koopo-filter-date-to').val('');
    $('#koopo-filter-search').val('');
    loadBookings();
  });

  $(document).on('click', '#koopo-pagination a[data-page]', function(e) {
    e.preventDefault();
    currentFilters.page = parseInt($(this).data('page'));
    loadBookings();
  });

  $(document).on('change', '#koopo-select-all', function() {
    $('.koopo-booking-checkbox').prop('checked', $(this).is(':checked'));
  });

  $('#koopo-bulk-apply').on('click', function() {
    const action = $('#koopo-bulk-action').val();
    const selected = $('.koopo-booking-checkbox:checked').map(function() {
      return parseInt($(this).val());
    }).get();

    if (!action) {
      alert('Please select a bulk action.');
      return;
    }

    if (selected.length === 0) {
      alert('Please select at least one booking.');
      return;
    }

    let confirmMsg = 'Apply this action to ' + selected.length + ' booking(s)?';
    if (action === 'delete') {
      confirmMsg = KOOPO_ADMIN.i18n.confirm_delete;
    } else if (action === 'cancel') {
      confirmMsg = KOOPO_ADMIN.i18n.confirm_cancel;
    }

    if (!confirm(confirmMsg)) {
      return;
    }

    $.ajax({
      url: KOOPO_ADMIN.ajax_url,
      method: 'POST',
      data: {
        action: 'koopo_bulk_action',
        nonce: KOOPO_ADMIN.ajax_nonce,
        bulk_action: action,
        booking_ids: selected
      },
      success: function(response) {
        if (response.success) {
          alert(response.data.message);
          loadBookings();
        } else {
          alert('Error: ' + (response.data.message || 'Unknown error'));
        }
      }
    });
  });

  $('#koopo-export-csv').on('click', function() {
    const params = new URLSearchParams(currentFilters);
    
    $.ajax({
      url: KOOPO_ADMIN.api_url + '/admin/export',
      method: 'POST',
      headers: { 
        'X-WP-Nonce': KOOPO_ADMIN.nonce,
        'Content-Type': 'application/json'
      },
      data: JSON.stringify(currentFilters),
      success: function(data) {
        // Convert to CSV and download
        let csv = '';
        data.csv.forEach(function(row) {
          csv += row.map(cell => '"' + String(cell).replace(/"/g, '""') + '"').join(',') + '\n';
        });

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = data.filename;
        a.click();
        window.URL.revokeObjectURL(url);
      }
    });
  });

  // Check URL for status filter
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('status')) {
    currentFilters.status = urlParams.get('status');
    $('#koopo-filter-status').val(currentFilters.status);
  }

  // Initial load
  loadBookings();
});
</script>
