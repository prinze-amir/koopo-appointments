<?php
/**
 * Template: templates/woocommerce/order/booking-details-injected.php
 * Booking summary injection for WooCommerce order pages.
 */
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

if (empty($booking)) {
  return;
}

$start = Date_Formatter::format($booking->start_datetime, $booking->timezone ?? '', 'full');
$end = Date_Formatter::format($booking->end_datetime, $booking->timezone ?? '', 'time');
$duration = Date_Formatter::format_duration((int) $booking->duration_minutes ?? 0);
$status = ucfirst(str_replace('_', ' ', $booking->status));
$service_title = get_the_title((int) $booking->service_id);

$addon_ids = get_option("koopo_booking_{$booking->id}_addon_ids", '');
$addon_ids = is_string($addon_ids) ? json_decode($addon_ids, true) : $addon_ids;
$addon_ids = is_array($addon_ids) ? array_map('absint', $addon_ids) : [];
$addon_ids = array_values(array_filter($addon_ids));
$addon_titles = [];
foreach ($addon_ids as $addon_id) {
  $title = get_the_title($addon_id);
  if ($title) $addon_titles[] = $title;
}
$addons_text = $addon_titles ? implode(', ', $addon_titles) : '—';

$business_initial = $business_name ? strtoupper(substr($business_name, 0, 1)) : 'B';
$business_link = $business_url ?: '';
$business_logo_html = $business_logo
  ? '<img src="' . esc_url($business_logo) . '" alt="' . esc_attr($business_name) . '">'
  : '<span>' . esc_html($business_initial) . '</span>';
?>

<section class="koopo-booking-card">
  <div class="koopo-booking-card__header">
    <div class="koopo-business">
      <div class="koopo-business__logo"><?php echo wp_kses_post($business_logo_html); ?></div>
      <div class="koopo-business__meta">
        <div class="koopo-business__name"><?php echo esc_html($business_name ?: 'Business'); ?></div>
        <?php if ($business_link): ?>
          <a class="koopo-business__link" href="<?php echo esc_url($business_link); ?>">View business</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="koopo-booking-status koopo-booking-status--<?php echo esc_attr($booking->status); ?>">
      <?php echo esc_html($status); ?>
    </div>
    <div class="bookingId">
          <span class="label">Booking # </span><?php echo esc_html($booking->id); ?>

    </div>
  </div>

  <div class="koopo-booking-card__grid">
    <div class="koopo-booking-details">
      <h3>Booking Details</h3>
      <div class="koopo-booking-details__row">
        <span>Service</span>
        <strong><?php echo esc_html($service_title); ?></strong>
      </div>
      <div class="koopo-booking-details__row">
        <span>Date & Time</span>
        <strong><?php echo esc_html($start) . ' -- ' . esc_html($end); ?></strong>
        <span>Timezone: </span>
        <strong><?php if (!empty($booking->timezone)) : ?> (<?php echo esc_html($booking->timezone); ?>)<?php endif; ?></strong>
      </div>
      <div class="koopo-booking-details__row">
        <span>Duration</span>
        <strong><?php echo esc_html($duration); ?></strong>
      </div>
      <div class="koopo-booking-details__row">
        <span>Add-ons</span>
        <strong><?php echo esc_html($addons_text); ?></strong>
      </div>
      <div class="koopo-booking-details__row totals">
        <span>Total</span>
        <?php echo wp_kses_post(wc_price((float) $booking->price, ['currency' => $booking->currency ?? get_woocommerce_currency()])); ?>
      </div>
    </div>

    <div class="koopo-business-contact">
      <h3>Business Contact</h3>
      <?php if ($business_address): ?>
        <div class="koopo-business-contact__row">
          <span>Address</span>
          <strong><?php echo esc_html($business_address); ?></strong>
        </div>
      <?php endif; ?>
      <?php if ($business_email): ?>
        <div class="koopo-business-contact__row">
          <span>Email</span>
          <a href="mailto:<?php echo esc_attr($business_email); ?>"><?php echo esc_html($business_email); ?></a>
        </div>
      <?php endif; ?>
      <?php if ($business_phone): ?>
        <div class="koopo-business-contact__row">
          <span>Phone</span>
          <a href="tel:<?php echo esc_attr($business_phone); ?>"><?php echo esc_html($business_phone); ?></a>
        </div>
      <?php endif; ?>
      <?php if (!$business_address && !$business_email && !$business_phone): ?>
        <div class="koopo-business-contact__row">
          <span>Contact</span>
          <strong>Contact details not available.</strong>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<style>
  .koopo-booking-card {
    margin: 24px 0;
    padding: 22px;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 12px 28px rgba(16, 24, 40, 0.08);
    text-align:left;
  }
  .bold {
    font-weight: bolder;
  }
  .koopo-booking-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
  }
  .koopo-business {
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .koopo-business__logo {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: #f6f2ea;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #7a5b12;
    overflow: hidden;
  }
  .koopo-business__logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .koopo-business__name {
    font-size: 18px;
    font-weight: 700;
    color: #111;
  }
  .koopo-business__link {
    font-size: 13px;
    color: #7a5b12;
    text-decoration: none;
  }
  .koopo-booking-status {
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    background: #f0f0f0;
    color: #555;
  }
  .koopo-booking-status--confirmed { background:#e8f5e9; color:#2c7a3c; }
  .koopo-booking-status--pending_payment { background:#fff3cd; color:#856404; }
  .koopo-booking-status--cancelled,
  .koopo-booking-status--refunded { background:#ffecec; color:#d63638; }

  .koopo-booking-card__grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 18px;
  }
  .koopo-booking-details,
  .koopo-business-contact {
    padding: 16px;
    border-radius: 12px;
    background: #f8f8f8;
  }
  .koopo-booking-details h3,
  .koopo-business-contact h3 {
    margin: 0 0 12px;
    font-size: 14px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #666;
  }
  .koopo-booking-details__row,
  .koopo-business-contact__row {
    display: grid;
    grid-template-columns: 85px 1fr;
    gap: 10px;
    align-items: start;
    padding: 8px 0;
    border-bottom: 1px solid #e9e9e9;
  }
  .koopo-booking-details__row:last-child,
  .koopo-business-contact__row:last-child {
    border-bottom: none;
  }
  .koopo-booking-details__row span,
  .koopo-business-contact__row span {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #888;
  }
  .koopo-booking-details__row strong,
  .koopo-business-contact__row strong {
    color: #111;
  }
  .koopo-booking-details__row small {
    display: block;
    color: #666;
    margin-top: 4px;
  }
  .koopo-business-contact a {
    color: #7a5b12;
    text-decoration: none;
    text-overflow: ellipsis;
    overflow: hidden;
  }
 .woocommerce-table, .woocommerce-order-details__title, #woocommerce-order-items {
    display: none;
}
.koopo-booking-details__row.totals span {
    color: #111;
    font-size: 14px;
    font-weight: 700;
}
  .koopo-business-contact a:hover { text-decoration: underline; }

  @media (max-width: 768px) {
    .koopo-booking-card__header {
      flex-direction: column;
      align-items: flex-start;
    }
    .koopo-booking-card__grid {
      grid-template-columns: 1fr;
    }
    .koopo-booking-details__row,
    .koopo-business-contact__row {
      grid-template-columns: 1fr;
    }
  }
</style>
