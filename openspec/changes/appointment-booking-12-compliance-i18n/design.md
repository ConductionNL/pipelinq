# Design: Appointment Booking — Compliance & i18n (Member 12)

## Overview

Final chain member: regulatory compliance, accessibility, internationalisation, and
code-quality verification across the booking module.

## Backend

### DataDeletionService (`lib/Service/DataDeletionService.php`)
- `pseudonymizeCustomerBookings(string $customerId): void` — replace name, email,
  phone on all that customer's Bookings with `hash('sha256', original)`.
- Keep Booking records (7-year Boekhoudplicht retention); keep aggregates (counts,
  totals) unchanged; log the pseudonymization with a timestamp.

### Retention invariant (REQ-APT-017)
- No auto-delete mechanism; terminal statuses (completed/cancelled/no-show) are not
  purged; audit trail preserved via OpenRegister (immutable).

## Accessibility (REQ-APT-017)

WCAG 2.1 AA verification of the portal (member 06) + admin forms (member 11):
axe-core zero violations, keyboard navigation, screen-reader labels, 4.5:1 contrast,
mobile 320px, both languages.

## i18n (REQ-APT-020, ADR-007/025)

Complete `l10n/en.json` + `l10n/nl.json`: portal, admin, queue, status labels, error
messages, email subjects/bodies. Identical key sets (parity). No hardcoded strings —
verified by grep. Portal renders in Dutch under nl locale; emails use customer locale
(Dutch default).

## Verification

Pre-commit gates: SPDX headers on all new PHP/Vue; ObjectService 3-positional-arg
calls; no `$e->getMessage()` in controllers; store + router registration present;
`npm run lint`; `php -l`; `composer test` (≥80% coverage on services); `composer lint`
(phpcs/phpstan/phpmd); `npm run build`.

## Tests

`DataDeletionService` unit tests: pseudonymization hashes fields, retains records,
preserves aggregates.
