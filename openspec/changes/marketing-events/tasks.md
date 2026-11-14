# Tasks: marketing-events

## 1. Records

- [x] 1.1 Add `lib/Settings/register.d/98-marketing-events.json` with `marketingEvent` and `eventAttendee` (slugs, fields from design D1, archival), `campaign.eventId`, and `attend` in `TouchpointService::KINDS` and the `touchpoint.kind` enum
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0; PHPUnit on `CampaignReportService` counts an `attend` touchpoint

## 2. Registration

- [x] 2.1 Add `lib/Service/Marketing/EventRegistrationService.php`: `register()` with the same-email check, seat count, waiting list, closed refusal and the re-count after write; `cancel()` that moves the first waitlisted attendee up
  - Verify: PHPUnit `tests/Unit/Service/Marketing/EventRegistrationServiceTest.php`: seat free gives registered; full gives waitlisted; the same email twice gives one attendee; after the closing date nothing is written; two writes for the last seat leave one registered and one waitlisted; a cancel moves the first waitlisted up
- [x] 2.2 Call `register()` from `LandingPageFormSubmittedListener::ingest()` when the campaign has an `eventId`; leave the event contract untouched
  - Verify: PHPUnit on the listener with the real `LandingPageFormSubmittedEvent` class: a sign-up on an event campaign writes an attendee and still sets `leadId`; a replayed nonce writes nothing

## 3. Mails and the cancel page

- [x] 3.1 Add `lib/Service/Marketing/EventEmailService.php`: confirmation with `.ics`, waiting list notice, seat-freed mail, cancellation notice, signed cancel link
  - Verify: PHPUnit: the confirmation carries an `.ics` with the event start; a waitlisted attendee gets no `.ics`; the link signature verifies and a changed id does not
- [x] 3.2 Add `lib/BackgroundJob/EventReminderJob.php` and register it in `appinfo/info.xml`
  - Verify: PHPUnit: an attendee of an event starting in 23.5 hours gets one reminder and `reminderSentAt`; a second run sends nothing
- [x] 3.3 Add the public cancel page: GET shows the event and a button, POST cancels
  - Verify: PHPUnit controller test: GET changes nothing; POST with a valid token cancels; an expired token is refused; hydra gates route-auth pass

## 4. Pages

- [ ] 4.1 Add pages `Events` and `EventDetail` and the Events menu entry under Marketing in `src/manifest.d/78-marketing-campaigns.json`
  - Verify: `npm run check:manifest` exit 0
- [ ] 4.2 Add `EventAttendeesSection` (Mark attended, Mark no-show, Add attendee) and `CampaignEventSection` on CampaignDetail, registered in `src/registry.js`
  - Verify: Playwright `tests/e2e/marketing-events.spec.ts`: create an event with two seats for the seed campaign, feed three sign-ups through the listener, see two registered and one waiting, cancel one, see the waiting one registered, mark one attended and read one `attend` in the campaign report

## 5. Text and docs

- [x] 5.1 English source strings and Dutch translations for pages and mails, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [x] 5.2 User doc `docs/Features/marketing-events.md`: create an event on a campaign, what attendees receive, the waiting list, marking who came
  - Verify: docs build exit 0

## Build notes

- The event and registration records already exist as `campaignEvent` and
  `eventRegistration` (archived change campaign-events, with its pages
  CampaignEvents and CampaignEventDetail). This build adds the fields design D1
  asks for to those schemas (`99-zz-marketing-events.json`: `online`, `joinUrl`,
  and on the registration `lead`, `attendance`, `confirmationSentAt`,
  `reminderSentAt`, `cancelledAt`) instead of a second, parallel pair of schemas.
  An event names its campaign through `campaignEvent.campaign`, so `campaign.eventId`
  is not added. Registration statuses keep the existing `confirmed`, `waitlist`,
  `cancelled`; attended and no-show are the new `attendance` field.
- 1.1: the import log and the live report are not checked here; `check:schema-l10n`
  adds no uncovered string, and PHPUnit counts an `attend` touchpoint.
- 3.3: the cancel page is `GET|POST /apps/pipelinq/events/cancel?token=…`; the
  team actions are `POST /api/campaign-events/{id}/registrations` (add by hand)
  and `POST /api/event-registrations/{id}/attendance`.
- 4.1 and 4.2 are left open: the Events and event pages exist from campaign-events;
  wiring Mark attended, Mark no-show and Add attendee buttons on them to the new
  endpoints, and the Playwright run, are not done in this build.
- 3.1: the notice when an event is cancelled is `EventEmailService::sendEventCancelled()`,
  sent by `lib/Listener/EventCancelledListener.php` when an event's `signupStatus`
  turns `cancelled`: in the deferred job, to every `confirmed` and `waitlist`
  registration, and only if the event is still cancelled when the job runs.
- 4.2 (second build): Mark attended and Mark no-show are row actions on the
  event's Registrations tab, through `POST /api/event-registrations/{id}/attendance`,
  with an Attendance column. The campaign page already shows its events
  (the Events card from campaign-events), so no separate `CampaignEventSection`
  is added. Add attendee is not wired: the endpoint exists, but a manifest
  `api-call` action has no form for the name and address; it needs a dialog.
  The Playwright run is not done here.
- 4.1 stays open: the Events and event pages exist (`CampaignEvents`,
  `CampaignEventDetail`), but the board keeps the Marketing menu to campaigns and
  newsletters (the note on `CampaignEvents`), so a menu entry needs a decision first.
- 5.2: the docs build was not run here.
