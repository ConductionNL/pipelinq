# Tasks: Appointment Booking — Portal Frontend (Member 06)

## Section 1: BookingPortal.vue

- [ ] Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [ ] Route prop: `serviceSlug` from route params
- [ ] On mount: fetch Service by slug via `GET /portal/services`
- [ ] Display service header: name, description, duration, price
- [ ] Date picker: only dates with available slots enabled; fetch `GET /portal/availability?serviceId=X&date=Y` per date
- [ ] Time slot picker: available times as 15-min interval buttons
- [ ] Booking form: name (required), email (required, validated), phone (optional), notes (optional)
- [ ] Submit button: `POST /portal/book` with form data + serviceId + startAt
- [ ] On success: confirmation message + summary; if depositRequired, redirect to payment provider (member 08)
- [ ] On error: friendly error message, not stack trace
- [ ] WCAG 2.1 AA: keyboard-navigable, proper labels, color contrast, screen-reader announcements
- [ ] All labels via `this.t('pipelinq', 'key')` (en + nl)
- [ ] CSS variables only (NL Design System), no hardcoded colors
- [ ] Components: @conduction/nextcloud-vue only; axios (no raw fetch)

## Section 2: BookingConfirmationPage.vue

- [ ] Add SPDX header; route `/booking-confirmation/:bookingId`
- [ ] Fetch booking via `GET /portal/booking/{bookingId}` (no auth)
- [ ] Display: customer name, service, resource, date/time, status, price
- [ ] Show "Confirmation email sent to {email}" message
- [ ] If deposit pending: show "Awaiting payment" with payment status
- [ ] Include reschedule/cancel signed links
- [ ] All strings translated (en + nl)
