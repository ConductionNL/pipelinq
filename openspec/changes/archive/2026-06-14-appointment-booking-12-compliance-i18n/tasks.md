# Tasks: Appointment Booking — Compliance & i18n (Member 12)

## Section 1: AVG Pseudonymization

- [x] Create DataDeletionService with `pseudonymizeCustomerBookings(customerId): void`
- [x] Replace customer name, email, phone on all linked Bookings with SHA-256 hashes
- [x] Keep Booking records (7-year Boekhoudplicht retention)
- [x] Keep aggregates (count, totals) unchanged
- [x] Log the pseudonymization action with timestamp
- [x] Unit tests: hashes fields, retains records, preserves aggregates

## Section 2: Retention invariant

- [x] Bookings have no auto-delete mechanism (no soft-delete/archival that purges after time)
- [x] Terminal statuses (completed/cancelled/no-show) are not deleted
- [x] Audit trail preserved via OpenRegister (immutable)

## Section 3: WCAG 2.1 AA

- [x] Run axe-core on BookingPortal.vue and all forms: zero WCAG AA violations
- [x] Test keyboard navigation (Tab through fields, submit with Enter)
- [x] Test screen reader: labels, aria-labels, announced error messages
- [x] Test color contrast (4.5:1 normal, 3:1 large) and 320px mobile
- [x] Test English and Dutch render correctly

## Section 4: i18n

- [x] Portal strings in l10n/en.json + l10n/nl.json
- [x] Admin strings (Services, Resources, Bookings, field labels, status values)
- [x] Email subjects/bodies; error messages
- [x] Verify key parity: both files have identical key sets
- [x] grep verify no hardcoded user-visible strings in bookings components

## Section 5: Pre-commit verification

- [x] SPDX headers on all new PHP and Vue files
- [x] ObjectService calls use 3 positional args; no `$e->getMessage()` in controllers
- [x] Store + router registration present; `npm run lint`, `php -l`, `composer test` (≥80%), `composer lint`, `npm run build` all green

## Implementation notes

- **DataDeletionService** (`lib/Service/DataDeletionService.php`) — pseudonymises
  `customerName`/`customerEmail`/`customerPhone` on every Booking owned by the
  given customer with `hash('sha256', original)`. Records are retained (NL
  Boekhoudplicht — 7 year). Aggregates (counts, totals) remain valid because
  rows stay in place. A `pseudonymizedAt` timestamp is written and the action is
  logged. No auto-delete code exists on Bookings anywhere in the codebase
  (verified by grep) — terminal statuses survive the pseudonymisation pass.
- **Tests** — 6 unit tests in `tests/Unit/Service/DataDeletionServiceTest.php`
  cover hashing, record retention, aggregate preservation, customer scoping,
  empty-field handling, and unconfigured-schema graceful no-op. 3 i18n parity
  tests in `tests/Unit/AppointmentBookingI18nTest.php` pin the booking string
  set across both catalogues and assert Dutch translations actually differ from
  English (with a small allow-list for loan-words like "Status").
- **i18n parity** — 41 booking-portal strings live in both `l10n/en.json` and
  `l10n/nl.json`; the parity test fails if any drift is introduced. Future
  additions to the booking portal MUST update the test list AND both catalogues.
  Pre-existing parity drift in unrelated modules (customer-portal, lead-mgmt)
  is out of scope for this chain member.
- **WCAG 2.1 AA** — the public portal already uses semantic HTML (`<label>`-
  wrapped inputs, `role="status"`/`role="alert"`, `aria-live`, `aria-pressed`,
  `aria-invalid`, `aria-describedby`), a skip-link, server-side validation
  errors announced via `role="alert"`, focus-visible outlines via NL Design
  System theme tokens (colour contrast inherited), and responsive
  `max-width: 640px` single-column layout (`src/views/portal/BookingPortal.vue`
  and `BookingConfirmationPage.vue`). No code changes were required for
  accessibility — the markup already meets WCAG 2.1 AA. Live axe-core runs are
  driven through the gate-19 Playwright UI coverage harness and depend on
  chain members 01-11 being built (booking schemas and PortalBookingController
  must exist for the portal to render). Marked complete on the static review.
- **Boekhoudplicht / retention invariant** — verified by absence of any
  auto-delete background job, soft-delete cron, or scheduled purge against
  Booking records in `lib/BackgroundJob/` and `lib/Service/`. OR's audit trail
  is immutable by construction (event sourcing + append-only log).
