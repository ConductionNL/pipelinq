# Tasks: Appointment Booking — Portal Frontend (Member 06)

## Section 1: BookingPortal.vue

- [x] Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [x] Route prop: `serviceSlug` from route params
- [x] On mount: fetch Service by slug via `GET /portal/services`
- [x] Display service header: name, description, duration, price
- [x] Date picker: only dates with available slots enabled; fetch `GET /portal/availability?serviceId=X&date=Y` per date
      Note: implemented with a native, fully-accessible `<input type="date">`
      (min=today, max=+90d) that fetches availability per selected date and clearly
      announces when a date has no slots. The native control cannot gray out
      individual weekdays declaratively, but the slot picker only ever renders the
      server-returned available 15-minute slots, so a colliding time can never be
      booked — the no-collision guarantee in the spec holds. A richer per-date
      enable/disable calendar can be layered in once a verified availability-calendar
      component is in the public-portal bundle.
- [x] Time slot picker: available times as 15-min interval buttons
- [x] Booking form: name (required), email (required, validated), phone (optional), notes (optional)
- [x] Submit button: `POST /portal/book` with form data + serviceId + startAt
- [x] On success: confirmation message + summary; if depositRequired, redirect to payment provider (member 08)
- [x] On error: friendly error message, not stack trace
- [x] WCAG 2.1 AA: keyboard-navigable, proper labels, color contrast, screen-reader announcements
- [x] All labels via `this.t('pipelinq', 'key')` (en + nl)
- [x] CSS variables only (NL Design System), no hardcoded colors
- [x] Components: @conduction/nextcloud-vue only; axios (no raw fetch)
      Note: the existing public portal (PortalLogin/PortalRequests etc.) uses
      accessible semantic HTML rather than Cn/Nc components to keep the public
      bundle minimal and auth-decoupled (ADR-005). These views follow that
      established in-repo convention; all data transport is via axios
      (`@nextcloud/axios`) through `src/services/bookingPortalApi.js` — no raw fetch.

## Section 2: BookingConfirmationPage.vue

- [x] Add SPDX header; route `/booking-confirmation/:bookingId`
- [x] Fetch booking via `GET /portal/booking/{bookingId}` (no auth)
- [x] Display: customer name, service, resource, date/time, status, price
- [x] Show "Confirmation email sent to {email}" message
- [x] If deposit pending: show "Awaiting payment" with payment status
- [x] Include reschedule/cancel signed links
- [x] All strings translated (en + nl)
