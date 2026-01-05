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
    page: 1,
    orderby: '',
    order: 'asc'
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
    html += '<th>ID</th>';
    html += '<th class="sortable" data-sort="customer">Customer <span class="sorting-indicator"></span></th>';
    html += '<th class="sortable" data-sort="vendor">Vendor <span class="sorting-indicator"></span></th>';
    html += '<th>Service</th>';
    html += '<th class="sortable" data-sort="date">Date/Time <span class="sorting-indicator"></span></th>';
    html += '<th>Duration</th><th>Status</th><th>Price</th>';
    html += '<th class="sortable" data-sort="created">Created <span class="sorting-indicator"></span></th>';
    html += '<th>Order</th><th>Actions</th>';
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
      html += '<td>' + item.created_formatted + '<br><small class="description">' + item.created_relative + '</small></td>';
      html += '<td>' + (item.wc_order_id ? '<a href="post.php?post=' + item.wc_order_id + '&action=edit">#' + item.wc_order_id + '</a>' : '—') + '</td>';
      html += '<td>';
      html += '<button class="button button-small koopo-quick-view" data-booking="' + item.id + '">Quick View</button> ';
      if (item.wc_order_id) {
        html += '<a href="post.php?post=' + item.wc_order_id + '&action=edit" class="button button-small">View Order</a>';
      }
      html += '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    $('#koopo-bookings-table-container').html(html);
    
    // Update sorting indicators
    $('.sortable').removeClass('sorted-asc sorted-desc');
    if (currentFilters.orderby) {
      const sortedCol = $('.sortable[data-sort="' + currentFilters.orderby + '"]');
      sortedCol.addClass(currentFilters.order === 'asc' ? 'sorted-asc' : 'sorted-desc');
    }
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

  // Sorting handler
  $(document).on('click', '.sortable', function() {
    const sortBy = $(this).data('sort');
    
    if (currentFilters.orderby === sortBy) {
      // Toggle order
      currentFilters.order = currentFilters.order === 'asc' ? 'desc' : 'asc';
    } else {
      // New column
      currentFilters.orderby = sortBy;
      currentFilters.order = 'asc';
    }
    
    currentFilters.page = 1; // Reset to first page
    loadBookings();
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

<!-- Quick View Modal -->
<div id="koopo-quick-view-modal" style="display:none;">
  <div class="koopo-modal-overlay"></div>
  <div class="koopo-modal-content">
    <div class="koopo-modal-header">
      <h2>Booking Details</h2>
      <button class="koopo-modal-close">&times;</button>
    </div>
    <div class="koopo-modal-body" id="koopo-modal-booking-details">
      <div class="koopo-loading"><div class="koopo-spinner"></div></div>
    </div>
  </div>
</div>

<style>
.koopo-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.7);
  z-index: 100000;
}

.koopo-modal-content {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  max-width: 600px;
  width: 90%;
  max-height: 80vh;
  overflow: hidden;
  z-index: 100001;
}

.koopo-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #ddd;
}

.koopo-modal-header h2 {
  margin: 0;
  font-size: 20px;
}

.koopo-modal-close {
  background: none;
  border: none;
  font-size: 32px;
  cursor: pointer;
  color: #666;
  padding: 0;
  width: 32px;
  height: 32px;
  line-height: 1;
}

.koopo-modal-close:hover {
  color: #d63638;
}

.koopo-modal-body {
  padding: 20px;
  overflow-y: auto;
  max-height: calc(80vh - 80px);
}

.koopo-detail-row {
  display: flex;
  padding: 12px 0;
  border-bottom: 1px solid #f0f0f0;
}

.koopo-detail-row:last-child {
  border-bottom: none;
}

.koopo-detail-label {
  font-weight: 600;
  width: 150px;
  color: #666;
}

.koopo-detail-value {
  flex: 1;
  color: #333;
}
</style>

<script>
jQuery(document).ready(function($) {
  // Quick view handler
  $(document).on('click', '.koopo-quick-view', function() {
    const bookingId = $(this).data('booking');
    showQuickView(bookingId);
  });

  // Close modal handlers
  $(document).on('click', '.koopo-modal-close, .koopo-modal-overlay', function() {
    $('#koopo-quick-view-modal').hide();
  });

  function showQuickView(bookingId) {
    $('#koopo-quick-view-modal').show();
    $('#koopo-modal-booking-details').html('<div class="koopo-loading"><div class="koopo-spinner"></div></div>');

    $.ajax({
      url: KOOPO_ADMIN.api_url + '/admin/bookings?search=' + bookingId,
      headers: { 'X-WP-Nonce': KOOPO_ADMIN.nonce },
      success: function(data) {
        if (data.items && data.items.length > 0) {
          renderQuickView(data.items[0]);
        } else {
          $('#koopo-modal-booking-details').html('<p>Booking not found.</p>');
        }
      },
      error: function() {
        $('#koopo-modal-booking-details').html('<p>Error loading booking details.</p>');
      }
    });
  }

  function renderQuickView(booking) {
    let html = '';
    
    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Booking ID:</div>';
    html += '<div class="koopo-detail-value"><strong>#' + booking.id + '</strong></div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Status:</div>';
    html += '<div class="koopo-detail-value"><span class="koopo-status-badge koopo-status--' + booking.status + '">' + booking.status + '</span></div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Customer:</div>';
    html += '<div class="koopo-detail-value">' + booking.customer_name + '<br><small>' + booking.customer_email + '</small></div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Vendor:</div>';
    html += '<div class="koopo-detail-value">' + booking.vendor_name + '</div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Service:</div>';
    html += '<div class="koopo-detail-value">' + booking.service_title + '</div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Location:</div>';
    html += '<div class="koopo-detail-value">' + booking.listing_title + '</div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Date & Time:</div>';
    html += '<div class="koopo-detail-value">' + booking.start_formatted + '</div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Duration:</div>';
    html += '<div class="koopo-detail-value">' + booking.duration_formatted + '</div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Price:</div>';
    html += '<div class="koopo-detail-value"><strong>$' + booking.price.toFixed(2) + '</strong></div>';
    html += '</div>';

    html += '<div class="koopo-detail-row">';
    html += '<div class="koopo-detail-label">Booked:</div>';
    html += '<div class="koopo-detail-value">' + booking.created_formatted + '<br><small class="description">' + booking.created_relative + '</small></div>';
    html += '</div>';

    if (booking.wc_order_id) {
      html += '<div class="koopo-detail-row">';
      html += '<div class="koopo-detail-label">WooCommerce Order:</div>';
      html += '<div class="koopo-detail-value"><a href="post.php?post=' + booking.wc_order_id + '&action=edit" target="_blank">#' + booking.wc_order_id + '</a></div>';
      html += '</div>';
    }

    html += '<div style="margin-top: 20px; text-align: right;">';
    if (booking.wc_order_id) {
      html += '<a href="post.php?post=' + booking.wc_order_id + '&action=edit" class="button button-primary">View Full Order</a>';
    }
    html += '</div>';

    $('#koopo-modal-booking-details').html(html);
  }
});
</script>
