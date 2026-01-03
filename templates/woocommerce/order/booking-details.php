<?php
/**
 * Commit 22: Booking Details Display on Order Pages
 * Template: templates/woocommerce/order/booking-details.php
 * 
 * Displays appointment booking information on:
 * - Order confirmation page
 * - My Account > View Order
 * - Admin order edit screen
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

if (empty($booking)) {
  return;
}

$start = Date_Formatter::format($booking->start_datetime, $booking->timezone ?? '', 'full');
$duration = Date_Formatter::format_duration((int) $booking->duration_minutes ?? 0);
$relative = Date_Formatter::relative($booking->start_datetime, $booking->timezone ?? '');
$status = ucfirst(str_replace('_', ' ', $booking->status));

// Calendar links
$calendar_links = Date_Formatter::get_calendar_links($booking);

// Service and listing
$service_title = get_the_title((int) $booking->service_id);
$listing_title = get_the_title((int) $booking->listing_id);

?>

<section class="koopo-booking-details woocommerce-order-booking-details">
  <h2 class="woocommerce-order-booking-details__title"><?php esc_html_e('Appointment Details', 'koopo-appointments'); ?></h2>

  <table class="woocommerce-table woocommerce-table--booking-details shop_table booking_details">
    <tbody>
      <tr>
        <th><?php esc_html_e('Service', 'koopo-appointments'); ?></th>
        <td>
          <strong><?php echo esc_html($service_title); ?></strong>
          <?php if ($listing_title): ?>
            <br><small><?php echo esc_html($listing_title); ?></small>
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <th><?php esc_html_e('Date & Time', 'koopo-appointments'); ?></th>
        <td>
          <strong><?php echo esc_html($start); ?></strong>
          <?php if (!empty($booking->timezone)): ?>
            <br><small><?php echo esc_html($booking->timezone); ?></small>
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <th><?php esc_html_e('Duration', 'koopo-appointments'); ?></th>
        <td><?php echo esc_html($duration); ?></td>
      </tr>

      <tr>
        <th><?php esc_html_e('Status', 'koopo-appointments'); ?></th>
        <td>
          <span class="koopo-booking-status koopo-booking-status--<?php echo esc_attr($booking->status); ?>">
            <?php echo esc_html($status); ?>
          </span>
        </td>
      </tr>

      <?php if ($booking->status === 'confirmed'): ?>
      <tr>
        <th><?php esc_html_e('Starts', 'koopo-appointments'); ?></th>
        <td><em><?php echo esc_html(ucfirst($relative)); ?></em></td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($booking->status === 'confirmed' || $booking->status === 'pending_payment'): ?>
  <div class="koopo-booking-calendar-links">
    <h3><?php esc_html_e('Add to Calendar', 'koopo-appointments'); ?></h3>
    <p class="koopo-calendar-buttons">
      <a href="<?php echo esc_url($calendar_links['google']); ?>" 
         target="_blank" 
         rel="noopener noreferrer"
         class="button koopo-calendar-btn koopo-calendar-btn--google">
        <?php esc_html_e('Google Calendar', 'koopo-appointments'); ?>
      </a>

      <a href="<?php echo esc_url($calendar_links['ical']); ?>" 
         download="appointment.ics"
         class="button koopo-calendar-btn koopo-calendar-btn--ical">
        <?php esc_html_e('Apple Calendar', 'koopo-appointments'); ?>
      </a>

      <a href="<?php echo esc_url($calendar_links['outlook']); ?>" 
         target="_blank" 
         rel="noopener noreferrer"
         class="button koopo-calendar-btn koopo-calendar-btn--outlook">
        <?php esc_html_e('Outlook', 'koopo-appointments'); ?>
      </a>
    </p>
  </div>
  <?php endif; ?>

  <style>
    .koopo-booking-details {
      margin: 30px 0;
      padding: 20px;
      background: #f9f9f9;
      border-radius: 8px;
    }

    .koopo-booking-details h2 {
      margin-top: 0;
      margin-bottom: 20px;
      font-size: 20px;
    }

    .woocommerce-table--booking-details th {
      font-weight: 600;
      width: 30%;
      padding: 12px;
    }

    .woocommerce-table--booking-details td {
      padding: 12px;
    }

    .koopo-booking-status {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 4px;
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .koopo-booking-status--confirmed {
      background: #e8f5e9;
      color: #2c7a3c;
    }

    .koopo-booking-status--pending_payment {
      background: #fff3cd;
      color: #856404;
    }

    .koopo-booking-status--cancelled,
    .koopo-booking-status--refunded {
      background: #ffecec;
      color: #d63638;
    }

    .koopo-booking-calendar-links {
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #ddd;
    }

    .koopo-booking-calendar-links h3 {
      margin-top: 0;
      margin-bottom: 12px;
      font-size: 16px;
    }

    .koopo-calendar-buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .koopo-calendar-btn {
      flex: 1;
      min-width: 140px;
      text-align: center;
      padding: 10px 16px;
      font-size: 14px;
      font-weight: 600;
      border-radius: 6px;
      text-decoration: none;
      transition: all 0.2s;
    }

    .koopo-calendar-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .koopo-calendar-btn--google {
      background: #4285f4;
      color: #fff;
      border-color: #4285f4;
    }

    .koopo-calendar-btn--google:hover {
      background: #357ae8;
    }

    .koopo-calendar-btn--ical {
      background: #555;
      color: #fff;
      border-color: #555;
    }

    .koopo-calendar-btn--ical:hover {
      background: #333;
    }

    .koopo-calendar-btn--outlook {
      background: #0078d4;
      color: #fff;
      border-color: #0078d4;
    }

    .koopo-calendar-btn--outlook:hover {
      background: #106ebe;
    }

    @media (max-width: 600px) {
      .koopo-calendar-buttons {
        flex-direction: column;
      }

      .koopo-calendar-btn {
        width: 100%;
      }

      .woocommerce-table--booking-details th,
      .woocommerce-table--booking-details td {
        display: block;
        width: 100%;
        padding: 8px;
      }

      .woocommerce-table--booking-details th {
        background: #f0f0f0;
        font-weight: 700;
      }
    }
  </style>
</section>
