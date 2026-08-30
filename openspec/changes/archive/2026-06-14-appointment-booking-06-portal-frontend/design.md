# Design: Appointment Booking — Portal Frontend (Member 06)

## Overview

Two public Vue views consuming the member-05 portal API. Minimal Pinia (anonymous
portal); axios from `@nextcloud/axios`; components from `@conduction/nextcloud-vue`
only.

## Frontend (per giant design.md)

### BookingPortal.vue (`src/views/portal/BookingPortal.vue`)
- Route `/book/:serviceSlug` (public, no auth).
- On mount: fetch Service via `GET /portal/services`.
- Service header (name, description, duration, price).
- Date picker: only dates with available slots enabled; fetch
  `GET /portal/availability?serviceId=X&date=Y` per date.
- Slot picker: 15-minute interval buttons.
- Booking form: name (required), email (required, validated), phone, notes.
- Submit → `POST /portal/book`; on success show confirmation or redirect to
  payment provider (member 08); on error show friendly message (not stack trace).

### BookingConfirmationPage.vue (`src/views/portal/BookingConfirmationPage.vue`)
- Route `/booking-confirmation/:bookingId`; fetch via `GET /portal/booking/{id}`.
- Display customer name, service, resource, date/time, status, price.
- "Confirmation email sent to {email}" notice; deposit-pending state.
- Reschedule/cancel signed links.

## Accessibility + i18n

WCAG 2.1 AA: keyboard-navigable, proper labels, contrast, screen-reader
announcements. All strings via `this.t('pipelinq', 'key')` (en + nl). CSS variables
only (NL Design System), no hardcoded colors. SPDX header first line of each file.

## Constraints (ADR-004)

No raw `fetch()` (use axios), no direct `@nextcloud/vue` imports, every `<CnFoo>`/
`<NcFoo>` has matching import + `components: {}` entry.
