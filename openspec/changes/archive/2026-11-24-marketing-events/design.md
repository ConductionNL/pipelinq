# Design: marketing-events

## Context (read at pipelinq development cfe0a0a51)

- **Campaigns and their landing page.** `lib/Settings/register.d/98-marketing-campaigns.json`
  declares `campaign` (`name`, `goal`, `status` planned, running, finished,
  cancelled, `landingPage`, `formRef`, `startsAt`, `endsAt`, and more),
  `touchpoint` (`kind` click, visit, submit, reply) and the `lead` fields
  `firstTouch`, `lastTouch`, `campaignId`. `formRef` (:146-151) is "The
  portaliq form bound to the landing page. A submission on this form becomes a
  lead in this campaign." The seed campaign "Webinar AI voor gemeenten" is at
  :526 (the matrix says :523).
- **Provisioning.** `LandingPageProvisioningService` asks portaliq for a page
  with a form by a same-instance typed event (ADR-041) and records the answer
  on the campaign. Its form is name, email and an optional organisation
  (`formDefinition()`, :193-203).
- **A sign-up today.** portaliq dispatches `LandingPageFormSubmittedEvent`,
  whose positional constructor is a frozen cross-app contract
  (`lib/Event/LandingPageFormSubmittedEvent.php` docblock).
  `LandingPageFormSubmittedListener::ingest()` (:141-185) checks the nonce
  first, finds the campaign by the external reference or by `formRef`
  (`campaignIdFor()`, :199-222), matches or creates the contact, creates a
  lead, appends a touchpoint, and sets `leadId` and `handled` on the event.
  No event, capacity, attendee or confirmation exists.
- **Campaign page.** `src/manifest.d/78-marketing-campaigns.json` page
  CampaignDetail has a data widget and one body widget,
  `CampaignLandingPageSection` (`src/registry.js:735`). The Marketing menu
  group holds Campaigns.
- **Reporting.** `TouchpointService::KINDS` is click, visit, submit, reply
  (:64); `CampaignReportService` counts touchpoints per kind by iterating
  that list (:183-189), so a new kind is counted without a report change.
- **Mails with a calendar file and a signed link.** `AppointmentEmailService`
  composes a confirmation with an RFC 5545 `.ics` attachment and a 24-hour
  reminder, signs cancel links as `hash_hmac('sha256', "<id>.<action>.<expiresAt>", secret)`
  (`signLinkToken()`, :227-231) with a per-instance secret (`linkSecret()`,
  :699), and sends through `IMailer` (`dispatch()`, :398).
  `ReminderDispatchJob` (registered in `appinfo/info.xml:119`) sends reminders
  every five minutes for bookings starting in 23 to 24 hours. Appointment
  bookings are one customer per slot, so they do not model a room of seats.
- **Slugs.** A schema slug is global per organisation. portaliq already has
  `schoolEvent`, `eventSignup` and `eventRsvp` for school events with
  guardians, and larpinq has `event`. This change uses `marketingEvent` and
  `eventAttendee`.

## Decisions

### D1. Two new records

`marketingEvent`: `title`, `campaignId`, `description`, `startsAt`, `endsAt`,
`timezone`, `venueName`, `venueAddress`, `online` (boolean), `joinUrl`,
`capacity` (0 means no limit), `registrationClosesAt`, `status` (draft, open,
closed, cancelled, held).

`eventAttendee`: `eventId`, `contactId`, `leadId`, `name`, `email`,
`organisation`, `status` (registered, waitlisted, cancelled, attended,
no-show), `registeredAt`, `source` (landing-page or manual), `nonce`,
`confirmationSentAt`, `reminderSentAt`.

`campaign` gains `eventId`. Both records live in a new fragment,
`lib/Settings/register.d/98-marketing-events.json`, with slugs, and
`x-openregister-archival` on `eventAttendee` so an attendee's personal data has
a retention period.

### D2. The landing page sign-up registers for the event

`ingest()` keeps everything it does. After the touchpoint, when the campaign
has an `eventId` and the sign-up has an email address, it calls
`EventRegistrationService::register()`. That service finds an existing
attendee for the same event and email and returns it unchanged, so a second
sign-up does not take a second seat. Otherwise it counts the registered
attendees and writes `registered` when a seat is free and `waitlisted` when
not. After `registrationClosesAt`, or when the event is not `open`, it writes
no attendee and mails the person that registration has closed. The event's
contract with portaliq does not change: the listener still sets only `leadId`
and `handled`.

### D3. Seats are counted at write time

The count and the write happen in one service call. Two sign-ups in the same
second can both see one free seat. The service re-counts after writing, and
the later of two over-capacity writes (by `registeredAt`, then id) is moved to
`waitlisted` before its mail is sent. The mail is sent after that check, so
nobody is told they have a seat they do not have.

### D4. Mails

`EventEmailService` follows `AppointmentEmailService`: a confirmation with an
`.ics` file (or a waiting list notice without one), a reminder 24 hours before
the start with the `joinUrl` for an online event, a "you have a seat now" mail
when someone moves up from the waiting list, and a notice to everyone when an
event is cancelled. It signs cancel links the same way with its own action
name. `EventReminderJob` runs every five minutes like `ReminderDispatchJob`
and stamps `reminderSentAt`.

### D5. Cancelling from the mail

`GET /apps/pipelinq/events/cancel?token=...` is a public page that shows the
event and a Cancel my registration button; the `POST` does the cancelling. A
GET never cancels, so a mail scanner that opens links cannot cancel anyone.
The token is verified by recomputing the signature and checking its expiry
(the event's start). Cancelling frees the seat and moves the first waitlisted
attendee up.

### D6. The event page

New declarative pages in `src/manifest.d/78-marketing-campaigns.json`:
`Events` (index over `marketingEvent`, under the Marketing group) and
`EventDetail`, with a data widget, count chips (registered, seats, waiting)
and an object-list widget of attendees filtered on `eventId`. A body widget
`EventAttendeesSection` adds Mark attended and Mark no-show per row and Add
attendee. CampaignDetail gets `CampaignEventSection` to create the event for
the campaign or open it.

### D7. Attending is a touchpoint

Marking an attendee attended appends a touchpoint of new kind `attend` for
the campaign, the contact and the lead. `TouchpointService::KINDS` gains
`attend`, and the campaign report counts it with no report change.

## Risks

- A popular event fills in minutes and the form keeps accepting sign-ups.
  They go on the waiting list and are told so; closing the form in portaliq is
  a named follow-up.
- Mail volume at the reminder hour. The job sends in batches per run, as the
  appointment reminder job does, and stamps each attendee so a retry does not
  send twice.
- An attendee who signs up with a second address gets a second seat. That is
  the same as the lead model today; the list shows both and the team can
  cancel one.
