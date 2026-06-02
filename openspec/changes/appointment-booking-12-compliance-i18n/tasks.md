# Tasks: Appointment Booking — Compliance & i18n (Member 12)

## Section 1: AVG Pseudonymization

- [ ] Create DataDeletionService with `pseudonymizeCustomerBookings(customerId): void`
- [ ] Replace customer name, email, phone on all linked Bookings with SHA-256 hashes
- [ ] Keep Booking records (7-year Boekhoudplicht retention)
- [ ] Keep aggregates (count, totals) unchanged
- [ ] Log the pseudonymization action with timestamp
- [ ] Unit tests: hashes fields, retains records, preserves aggregates

## Section 2: Retention invariant

- [ ] Bookings have no auto-delete mechanism (no soft-delete/archival that purges after time)
- [ ] Terminal statuses (completed/cancelled/no-show) are not deleted
- [ ] Audit trail preserved via OpenRegister (immutable)

## Section 3: WCAG 2.1 AA

- [ ] Run axe-core on BookingPortal.vue and all forms: zero WCAG AA violations
- [ ] Test keyboard navigation (Tab through fields, submit with Enter)
- [ ] Test screen reader: labels, aria-labels, announced error messages
- [ ] Test color contrast (4.5:1 normal, 3:1 large) and 320px mobile
- [ ] Test English and Dutch render correctly

## Section 4: i18n

- [ ] Portal strings in l10n/en.json + l10n/nl.json
- [ ] Admin strings (Services, Resources, Bookings, field labels, status values)
- [ ] Email subjects/bodies; error messages
- [ ] Verify key parity: both files have identical key sets
- [ ] grep verify no hardcoded user-visible strings in bookings components

## Section 5: Pre-commit verification

- [ ] SPDX headers on all new PHP and Vue files
- [ ] ObjectService calls use 3 positional args; no `$e->getMessage()` in controllers
- [ ] Store + router registration present; `npm run lint`, `php -l`, `composer test` (≥80%), `composer lint`, `npm run build` all green
