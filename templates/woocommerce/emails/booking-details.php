<?php
/**
 * Booking Details in Email Template
 *
 * Displays appointment booking information in WooCommerce emails
 */

namespace Koopo_Appointments;

defined('ABSPATH') || exit;

if (empty($booking)) {
  return;
}

$start = Date_Formatter::format($booking->start_datetime, $booking->timezone ?? '', 'full');
$duration = Date_Formatter::format_duration((int) $booking->duration_minutes ?? 0);
$status = ucfirst(str_replace('_', ' ', $booking->status));

// Service and listing
$service_title = get_the_title((int) $booking->service_id);
$listing_title = get_the_title((int) $booking->listing_id);

// Get customer name from booking meta
$customer_name = get_option("koopo_booking_{$booking->id}_customer_name", '');
$customer_email = get_option("koopo_booking_{$booking->id}_customer_email", '');
$customer_phone = get_option("koopo_booking_{$booking->id}_customer_phone", '');
$customer_notes = get_option("koopo_booking_{$booking->id}_customer_notes", '');

?>

<div style="margin-bottom: 40px;">
  <h2 style="color: #333; font-size: 20px; font-weight: 600; margin-bottom: 20px; margin-top: 0;">
    <?php esc_html_e('Appointment Details', 'koopo-appointments'); ?>
  </h2>

  <table cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;" border="1">
    <tbody>
      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; width: 35%; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Service', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <strong><?php echo esc_html($service_title); ?></strong>
          <?php if ($listing_title): ?>
            <br><small style="color: #666;"><?php echo esc_html($listing_title); ?></small>
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Date & Time', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <strong><?php echo esc_html($start); ?></strong>
          <?php if (!empty($booking->timezone)): ?>
            <br><small style="color: #666;"><?php echo esc_html($booking->timezone); ?></small>
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Duration', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <?php echo esc_html($duration); ?>
        </td>
      </tr>

      <?php if ($customer_name): ?>
      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Customer Name', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <?php echo esc_html($customer_name); ?>
        </td>
      </tr>
      <?php endif; ?>

      <?php if ($customer_email): ?>
      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Customer Email', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <a href="mailto:<?php echo esc_attr($customer_email); ?>"><?php echo esc_html($customer_email); ?></a>
        </td>
      </tr>
      <?php endif; ?>

      <?php if ($customer_phone): ?>
      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Customer Phone', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <a href="tel:<?php echo esc_attr($customer_phone); ?>"><?php echo esc_html($customer_phone); ?></a>
        </td>
      </tr>
      <?php endif; ?>

      <?php if ($customer_notes): ?>
      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Special Requests', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <?php echo nl2br(esc_html($customer_notes)); ?>
        </td>
      </tr>
      <?php endif; ?>

      <tr>
        <th scope="row" style="text-align: left; padding: 12px; border-right: 1px solid #ddd; background: #f0f0f0; font-weight: 600;">
          <?php esc_html_e('Status', 'koopo-appointments'); ?>
        </th>
        <td style="text-align: left; padding: 12px;">
          <span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 600; text-transform: uppercase;
            <?php
            if ($booking->status === 'confirmed') {
              echo 'background: #e8f5e9; color: #2c7a3c;';
            } elseif ($booking->status === 'pending_payment') {
              echo 'background: #fff3cd; color: #856404;';
            } else {
              echo 'background: #ffecec; color: #d63638;';
            }
            ?>">
            <?php echo esc_html($status); ?>
          </span>
        </td>
      </tr>
    </tbody>
  </table>
</div>
