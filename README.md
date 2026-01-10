# Koopo Appointments Plugin - Project Summary (Authoritative)

## 1. Project Goal (High-Level)

Build a **production-ready appointments / bookings system** for **Koopo** that:

* Works **only for Places** (`gd_place`)
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
* ✅ **Vendor = listing `post_author`**
* ✅ **Places only** (`gd_place`)
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
* Places-only enforcement

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

---

## 5. What Still Remains / Optional

### Optional / Later

* In-app BuddyBoss notifications (currently only a profile tab is added)
* Advanced reporting/exports (beyond vendor CSV and current analytics dashboard)
* Event tickets module (separate plugin)

---

## 6. Known Gaps / Tech Debt

* Duplicate vendor bookings API file exists (`includes/class-kgaw-vendor-bookings-api-enhanced.php`) while boot loads `includes/class-kgaw-vendor-bookings-api.php`.
* README history was previously tied to commit numbering; keep this doc aligned with current code to avoid drift.

---

## 7. Feature Matrix (UI + API Surface)

| Feature | UI / Template | JS / CSS | API / PHP |
| --- | --- | --- | --- |
| Vendor dashboard (appointments) | `templates/dokan/appointments.php` | `assets/vendor.js`, `assets/vendor.css` | `includes/class-kgaw-dokan-dashboard.php`, `includes/class-kgaw-vendor-bookings-api.php` |
| Vendor services CRUD | `templates/dokan/services.php` | `assets/vendor.js`, `assets/vendor.css` | `includes/class-kgaw-services-api.php`, `includes/class-kgaw-services-list.php` |
| Vendor settings | `templates/dokan/settings.php` | `assets/vendor.js`, `assets/appointments-settings.css` | `includes/class-kgaw-settings-api.php` |
| Customer dashboard | `templates/customer/my-appointments.php` | `assets/customer-dashboard.js`, `assets/customer-dashboard.css` | `includes/class-kgaw-customer-dashboard.php`, `includes/class-kgaw-customer-bookings-api.php` |
| Availability / slots | N/A | N/A | `includes/class-kgaw-availability.php` |
| Refund policy + processing | N/A | N/A | `includes/class-kgaw-refund-policy.php`, `includes/class-kgaw-refund-processor.php` |
| Reschedule (vendor) | Vendor modal in `assets/vendor.js` | `assets/vendor.js`, `assets/vendor.css` | `includes/class-kgaw-vendor-bookings-api.php`, `includes/class-kgaw-availability.php` |
| Reschedule (customer request) | `templates/customer/my-appointments.php` | `assets/customer-dashboard.js`, `assets/customer-dashboard.css` | `includes/class-kgaw-customer-bookings-api.php` |
| Order + email display | `templates/woocommerce/order/booking-details.php`, `templates/woocommerce/emails/booking-details.php` | N/A | `includes/class-kgaw-order-display.php` |
| Admin dashboards | `templates/admin/dashboard.php`, `templates/admin/bookings.php`, `templates/admin/analytics.php` | `assets/admin-dashboard.css` | `includes/class-kgaw-admin-dashboard.php`, `includes/class-kgaw-analytics-dashboard.php` |

---

## 8. Critical Lessons Learned (Important for Next Chat)

* ZIP artifacts must be **strictly chained**
* Never assume “commit N” unless built from “commit N-1”
* GitHub repo is **strongly recommended** to prevent this issue permanently

---

## 9. Recommended Next Chat Opening Message (Copy/Paste)

> The project is a **Places-only appointments plugin** integrated with **WooCommerce, Dokan, GeoDirectory, and BuddyBoss** using **cart-based checkout**.
>
> Please audit the repository, confirm state, and recommend the next priorities.

---
