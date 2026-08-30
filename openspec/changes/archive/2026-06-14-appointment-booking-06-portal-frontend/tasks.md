# Tasks: Appointment Booking — Portal Frontend (Member 06)

## Section 1: BookingPortal.vue

- [x] Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [x] Route prop: `serviceSlug` from route params
- [x] On mount: fetch Service by slug via `GET /portal/services`
- [x] Display service header: name, description, duration, price
- [x] Date picker: only dates with available slots enabled; fetch `GET /portal/availability?serviceId=X&date=Y` per date
- [x] Time slot picker: available times as 15-min interval buttons
- [x] Booking form: name (required), email (required, validated), phone (optional), notes (optional)
- [x] Submit button: `POST /portal/book` with form data + serviceId + startAt
- [x] On success: confirmation message + summary; if depositRequired, redirect to payment provider (member 08)
- [x] On error: friendly error message, not stack trace
- [x] WCAG 2.1 AA: keyboard-navigable, proper labels, color contrast, screen-reader announcements
- [x] All labels via `this.t('pipelinq', 'key')` (en + nl)
- [x] CSS variables only (NL Design System), no hardcoded colors
- [x] Components: @conduction/nextcloud-vue only; axios (no raw fetch)

## Section 2: BookingConfirmationPage.vue

- [x] Add SPDX header; route `/booking-confirmation/:bookingId`
- [x] Fetch booking via `GET /portal/booking/{bookingId}` (no auth)
- [x] Display: customer name, service, resource, date/time, status, price
- [x] Show "Confirmation email sent to {email}" message
- [x] If deposit pending: show "Awaiting payment" with payment status
- [x] Include reschedule/cancel signed links
- [x] All strings translated (en + nl)
