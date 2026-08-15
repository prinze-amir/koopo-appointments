# Koopo Appointments Plugin - Project Summary (Authoritative)

## 1. Project Goal (High-Level)

Build a **production-ready appointments / bookings system** for **Koopo** that:

* Supports both **Places** (`gd_place`) and independent **Service Profiles** (`koopo_provider`)
* Integrates cleanly with:

  * **GeoDirectory** (listings)
  * **WooCommerce** (checkout & orders)
  * **Dokan** (vendors / commission / Stripe Connect)
  * **BuddyBoss** (profiles & optional notifications)
* Uses **WooCommerce cart → checkout** (never manual order creation)
* Prevents double booking, ghost holds, and broken service/product states
* Is extensible for future modules (events/tickets later)

---

## 2. Core Architectural Decisions (Locked In)

These are **final and correct** decisions:

* ✅ **Cart-based checkout only** (required for Dokan commissions & Stripe split)
* ✅ **One booking = one Woo order**
* ✅ **Service ↔ Woo product mapping**
* ✅ **Payee = resource `payee_user_id`**, with legacy place ownership preserved
* ✅ **Places and independent professionals** use the same resource-based scheduler
* ✅ **Login required to book**
* ✅ **10-minute hold window**
* ✅ **No events/tickets in this module**
* ✅ **Commit-based ZIP iteration (Commit 16, 17, 18…)**

---

## 3. Current State (Based on Code)

The codebase includes the originally planned commits 17-22 and additional work labeled as "Commit 23" (customer dashboard). The implementation is ahead of the old roadmap.

---

## 4. What Is Already Complete (Current)

### Booking Flow

* Create booking
* Lock slot
* 10-minute hold
* Prevent double booking
* Auto-expire abandoned holds
* Release slots correctly

### Checkout

* Woo cart based
* Vendor-owned product
* Price locked from booking
* Dokan commission-safe
* Stripe-compatible

### Services

* Vendor CRUD via Dokan dashboard
* Auto-create Woo product
* Repair missing product
* Hide broken services
* Disable service if product deleted

### Security / UX

* Login-gated booking
* Hold window message shown to user
* Clean error handling
* Server-side ownership and service/resource enforcement

### Notifications

* Confirmed
* Cancelled
* Refunded
* Expired
* Conflict

### Vendor Booking Management (Commit 19)

* Dokan "Appointments" dashboard
* Filters (listing, status, month, year, search)
* Status badges and action buttons (cancel, reschedule, refund)
* CSV export

### Refund Tooling (Commit 20)

* Refund policy rules and calculations
* Vendor refund flow with WooCommerce refund creation
* Customer cancellation with policy-based refund messaging
* Booking and order status sync

### Reschedule UX (Commit 21)

* Vendor reschedule modal with calendar and slot picker
* Availability API for slot validation
* Customer reschedule request flow
* Reschedule notifications

### Display Improvements (Commit 22)

* Centralized date/time formatter (full, short, relative)
* Human-readable date/time across vendor, customer, admin, and order views

### Customer Dashboard (Commit 23)

* My Account "Appointments" endpoint
* Shortcode `[koopo_my_appointments]`
* BuddyBoss profile tab (appointments view)
* Customer actions: cancel, request reschedule, add to calendar

### Admin & Analytics

* Admin dashboard and analytics panels
* Automated reminders scaffolding

### Calendar Synchronization and Availability

Koopo remains the source of truth for Koopo appointments. Confirmed Koopo bookings synchronize outbound, while selected external calendars synchronize inbound as read-only busy periods. External titles and descriptions are never stored or displayed; the booking surface shows only **Unavailable**. External events cannot edit, reschedule, or cancel a Koopo booking. Virtual join links remain hidden from customer calendar links until the booking is confirmed.

The vendor settings screen supports each business or professional booking calendar:

* A private, revocable iCalendar subscription for Apple Calendar and compatible calendar apps
* Direct Google Calendar OAuth synchronization
* Direct Microsoft Outlook/Microsoft 365 OAuth synchronization
* Per-resource destination calendars and minimal/standard event privacy
* Background create, update, and delete operations with idempotent booking/event mappings
* Per-resource availability-calendar selection, busy interpretation, refresh interval, last-sync health, and a manual **Sync now** action
* Conflict checks at both slot presentation and locked booking confirmation

### Waitlist and Client Operations

Providers can use an expiring cancellation-fill waitlist in priority order, first-to-confirm batches, or manual mode. Registered customers choose a service, date range, preferred days, time range, and email/push channels. SMS is reserved for a provider's explicit, consented invitation to an unregistered guest; all messages after account claim use the Koopo inbox, push, and email.

Providers can schedule an appointment for an unregistered person from the Dokan Appointments dashboard. The provider chooses email and/or a one-time SMS invitation, records SMS consent, and selects a 30-minute to 24-hour hold. Koopo stores only a SHA-256 token hash, rotates the link on resend, limits delivery attempts, and releases unclaimed times after expiration. The recipient signs in or registers, claims the appointment into the matching account, and continues directly to WooCommerce checkout. Customer-facing booking forms cannot change appointment ownership or book for another person.

Registered-customer confirmations, reminders, and waitlist offers create real BuddyBoss messages for the Koopo app inbox, alongside BuddyBoss notifications and email. The existing private-message mobile hook provides the Expo push path. Delivery attempts are recorded by hashed recipient and idempotency key so retries cannot duplicate an already-sent message.

An SMS adapter must subscribe to the `koopo_appt_send_transactional_sms_result` filter and return an array with `accepted` (boolean), `provider_message_id` (string), `error_code` (string), and `retryable` (boolean). Calls include `guest_only`, `consent_recorded`, `consent_version`, booking, invitation, and attempt metadata, and are made only for provider-created unregistered-guest invitations. The legacy `koopo_appt_send_transactional_sms` action remains an observer seam, but action-only callbacks are never recorded as successful because they cannot report provider acceptance.

Service-profile geocoding is configured under **Settings → Koopo Appointments → Service Profile Geocoding**. Koopo supports GeocodeFarm, Geoapify, Google Geocoding, and public OpenStreetMap/Nominatim. Automatic mode uses the configured daily free allowances in administrator-defined order, caches eligible provider/profile results for 30 days, and temporarily removes unhealthy or rate-limited providers. API keys are encrypted and used only by the WordPress server.

Public Nominatim is eligible only for addresses explicitly displayed on public service profiles. It is never used for customer home addresses or private mobile-provider origins, and customer coverage coordinates are not retained in the geocode cache. The `koopo_appt_geocode_address` filter remains available as a site-specific override, and the Professionals map tile URL is independently filterable through `koopo_appt_provider_map_tile_url`.

Confirmed bookings create provider-private client records with an appointment timeline, preferences, formulas/specifications, and private notes. Providers can create service-specific intake and consent forms, request them before an appointment, require a typed electronic signature, and attach private JPEG, PNG, WebP, or PDF files through Media Gateway direct upload. Client attachments are stored as gateway asset references rather than WordPress Media Library attachments.

WordPress privacy export and erasure hooks cover appointment contact details, mobile addresses, waitlist entries, client notes, intake answers, and signature evidence. Financial appointment facts remain anonymized rather than deleted. Provider-held remote files are reported as retained until they are removed through the client-file workflow so the Media Gateway reference is released correctly. Administrators must publish an explicit retention policy before production use.

Configure provider client IDs and secrets under **Settings → Koopo Appointments → Calendar Integrations**. Client secrets are encrypted before storage, masked after saving, and can be replaced or removed by an administrator. These dashboard settings are the provider clients' configuration source.

Register these exact OAuth redirect URIs with the providers:

```text
https://YOUR-SITE/wp-json/koopo/v1/appointments/calendar/oauth/google/callback
https://YOUR-SITE/wp-json/koopo/v1/appointments/calendar/oauth/microsoft/callback
```

---

## 5. What Still Remains / Optional

### Optional / Later

* Advanced reporting/exports (beyond vendor CSV and current analytics dashboard)
* Venue-side roster and approval UI for professional/place affiliations
* Event tickets module (separate plugin)

---

## 6. Known Gaps / Tech Debt

* README history was previously tied to commit numbering; keep this doc aligned with current code to avoid drift.

---

## 6.1 Release Smoke Test

For production verification, run the admin smoke test script before shipping:

`SITE_URL=http://localhost:8085 WP_USER=admin WP_PASS=secret bash scripts/release-smoke-admin.sh`

If the site uses SSO or you already have a valid WordPress cookie jar, you can reuse that session instead of logging in through the script:

`SITE_URL=http://localhost:8085 SKIP_LOGIN=1 COOKIE_JAR_PATH=/tmp/wp-cookies.txt bash scripts/release-smoke-admin.sh`

What it checks:

* WordPress admin login succeeds
* Koopo admin dashboard page renders
* Koopo bookings page renders
* `KOOPO_ADMIN` localization is present
* `assets/admin-dashboard.js` is actually enqueued and returns `200 OK`
* Admin REST endpoints for stats, bookings, and export respond correctly
* Failures report the HTTP status and a response excerpt for faster diagnosis

This is a release guard, not a full E2E test suite. It is meant to catch broken admin asset loading, missing localized config, and fatal/API regressions before deployment.

---

## 7. Feature Matrix (UI + API Surface)

| Feature | UI / Template | JS / CSS | API / PHP |
| --- | --- | --- | --- |
| Service profile | `templates/dokan/provider-profile.php` and public `koopo_provider` pages | `assets/vendor-provider-profile.js`, `assets/appointments.js` | `includes/providers/class-kgaw-provider-profiles.php`, `includes/core/class-kgaw-resources.php` |
| Service profile/place affiliations | Public service profile | N/A | `includes/providers/class-kgaw-provider-affiliations.php` |
| Vendor dashboard (appointments) | `templates/dokan/appointments.php` | `assets/vendor-core.js`, `assets/vendor-appointments.js`, `assets/vendor.css` | `includes/dokan/class-kgaw-dokan-dashboard.php`, `includes/vendor/class-kgaw-vendor-bookings-api.php` |
| Vendor services CRUD | `templates/dokan/services.php` | `assets/vendor-core.js`, `assets/vendor-services.js`, `assets/vendor.css` | `includes/services/class-kgaw-services-api.php`, `includes/services/class-kgaw-services-list.php` |
| Vendor settings | `templates/dokan/settings.php` | `assets/vendor-core.js`, `assets/vendor-settings.js`, `assets/appointments-settings.css` | `includes/settings/class-kgaw-settings-api.php` |
| Customer dashboard | `templates/customer/my-appointments.php` | `assets/customer-dashboard-core.js`, `assets/customer-dashboard-app.js`, `assets/customer-dashboard.css` | `includes/customer/class-kgaw-customer-dashboard.php`, `includes/customer/class-kgaw-customer-bookings-api.php` |
| Availability / slots | N/A | N/A | `includes/core/class-kgaw-availability.php` |
| Refund policy + processing | N/A | N/A | `includes/refunds/class-kgaw-refund-policy.php`, `includes/refunds/class-kgaw-refund-processor.php` |
| Reschedule (vendor) | Vendor modal in `assets/vendor-appointments.js` | `assets/vendor-appointments.js`, `assets/vendor.css` | `includes/vendor/class-kgaw-vendor-bookings-api.php`, `includes/core/class-kgaw-availability.php` |
| Reschedule (customer request) | `templates/customer/my-appointments.php` | `assets/customer-dashboard-app.js`, `assets/customer-dashboard.css` | `includes/customer/class-kgaw-customer-bookings-api.php` |
| Order + email display | `templates/woocommerce/order/booking-details.php`, `templates/woocommerce/emails/booking-details.php` | N/A | `includes/woocommerce/class-kgaw-order-display.php` |
| Admin dashboards | `templates/admin/dashboard.php`, `templates/admin/bookings.php`, `templates/admin/analytics.php` | `assets/admin-dashboard.css` | `includes/admin/class-kgaw-admin-dashboard.php`, `includes/admin/class-kgaw-analytics-dashboard.php` |

---

## 8. Critical Lessons Learned (Important for Next Chat)

* ZIP artifacts must be **strictly chained**
* Never assume “commit N” unless built from “commit N-1”
* GitHub repo is **strongly recommended** to prevent this issue permanently

---

## 9. Recommended Next Chat Opening Message (Copy/Paste)

> The project is a **resource-based appointments plugin** for both independent professionals and GeoDirectory places, integrated with **WooCommerce, Dokan, GeoDirectory, and BuddyBoss** using **cart-based checkout**.
>
> Please audit the repository, confirm state, and recommend the next priorities.

---
