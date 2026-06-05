# Tasks: Appointment Booking — Compliance & i18n (Member 12)

> Build note: this is the final chain member. The predecessor booking chain
> (members 01–11: Booking schema, BookingService, portal frontend, walk-in
> queue, admin UI) is **not yet implemented in the app** at the time of this
> build, so the compliance backend is built self-contained against a minimal
> `booking` schema fragment (`lib/Settings/register.d/60-appointment-booking.json`,
> ADR-037 — never edits the monolith) wired into the settings loader. Tasks that
> verify the not-yet-built portal/admin frontend (WCAG axe-core runs, portal
> component string grep) require those predecessor members + a live instance and
> are DEFERRED with reasons below.

## Section 1: AVG Pseudonymization

- [x] Create DataDeletionService with `pseudonymizeCustomerBookings(customerId): int` (returns count; void was widened to a count for testability)
- [x] Replace customer name, email, phone on all linked Bookings with SHA-256 hashes
- [x] Keep Booking records (7-year Boekhoudplicht retention) — no deleteObject call; asserted in test
- [x] Keep aggregates (count, totals) unchanged — deposit/currency/status/times untouched; asserted in test
- [x] Log the pseudonymization action with timestamp (customer UUID + count + ISO-8601 timestamp; hashed PII never logged in the clear)
- [x] Unit tests: hashes fields, retains records, preserves aggregates, scoped-to-customer, multi-booking, empty-id reject, config/OR-unavailable guards

## Section 2: Retention invariant

- [x] Bookings have no auto-delete mechanism — DataDeletionService never deletes; no background purge job introduced (verified: no BackgroundJob touches the booking schema)
- [x] Terminal statuses (completed/cancelled/no-show) are not deleted — schema documents retention; pseudonymisation preserves the record and status
- [x] Audit trail preserved via OpenRegister (immutable) — pseudonymisation routes through OR `saveObject` (versioned), records retained

## Section 3: WCAG 2.1 AA

- [ ] (DEFERRED — needs member 06 portal frontend + live instance) Run axe-core on BookingPortal.vue and all forms: zero WCAG AA violations. The booking portal component does not exist in the app yet; axe-core requires a rendered DOM on a live Nextcloud instance.
- [ ] (DEFERRED — needs member 06/11 frontend + live instance) Test keyboard navigation (Tab through fields, submit with Enter)
- [ ] (DEFERRED — needs member 06/11 frontend + live instance) Test screen reader: labels, aria-labels, announced error messages
- [ ] (DEFERRED — needs member 06/11 frontend + live instance) Test color contrast (4.5:1 normal, 3:1 large) and 320px mobile
- [ ] (DEFERRED — needs member 06/11 frontend + live instance) Test English and Dutch render correctly in the portal (i18n keys are present and at parity; rendering verification needs the frontend)

## Section 4: i18n

- [x] Portal/booking strings in l10n/en.json + l10n/nl.json (booking, status labels, queue, compliance, errors — added with proper Dutch translations)
- [x] Admin strings (Services, Resources, Bookings, field labels, status values) added to both locales
- [x] Email subjects/bodies; error messages — booking confirmation/failure error strings added (full email-template bodies belong to member 07, deferred there)
- [x] Verify key parity: both files have identical key sets — fixed a pre-existing 6 EN-only / 73 NL-only gap; en.json and nl.json now both 1445 keys, asserted equal
- [x] en.js / nl.js regenerated from the JSON and validated with `node --check`
- [ ] (DEFERRED — needs member 06/11 frontend) grep verify no hardcoded user-visible strings in bookings components: the bookings Vue components do not exist yet; the i18n key set is in place for them to consume via `t()`.

## Section 5: Pre-commit verification

- [x] SPDX headers in the docblock of all new PHP files (DataDeletionService + test); register.d fragment is JSON (no SPDX)
- [x] ObjectService calls use named/positional args matching the real API (find/findAll/saveObject); no `findObject`/`createFromArray`; no `$e->getMessage()` leaked to a client
- [x] `composer check:strict` (phpcs/phpmd/psalm/phpstan + phpunit) green for touched files; `node --check` on regenerated l10n JS; JSON validated. (`npm run build` / store+router registration apply to the deferred frontend.)
