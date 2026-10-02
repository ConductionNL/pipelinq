---
kind: code
depends_on: []
---

# Proposal: marketing-events

## Summary

A team that runs a webinar or an open day needs more than a list of leads.
They need an event with a date, a place and a number of seats, a list of who
signed up, a confirmation for each person, a reminder the day before, and a
record of who came. Today a sign-up on the campaign's landing page becomes a
lead and nothing more. This change adds the event, and turns each sign-up on
its landing page into a registration for it.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`mkt-events`**, "Organise an event and take the registrations for it from a
web form". Rated partial, built.state built. Matrix evidence:
"lib/Settings/register.d/98-marketing-campaigns.json:149 'The portaliq form
bound to the landing page. A submission on this form becomes a lead in this
campaign.' (seed example 'Webinar AI voor gemeenten', :523);
lib/Service/LandingPageProvisioningService.php provisions the page. There is
no event object: no date, venue, capacity, attendee list or confirmation".
Note: "an event can be run as a campaign whose landing-page form turns each
sign-up into a lead; registrations are not kept as attendees of an event".
Demand: tender https://www.tenderned.nl/aankondigingen/overzicht/422277, with
intelligence-db requirements#194381 (Kadaster CRM, 2026-04-23: "aanmeldingen
of registraties voor evenementen") and requirements#75222 (Senzer,
2025-08-13: "evenementenmodule"). One competitor rates it yes:

- odoo-crm: source read, `addons/event` (Community) manages events, tickets
  and attendees, and `addons/website_event` publishes the event page with a
  registration form that creates `event.registration` records; attendees get
  confirmation and reminder mails from the event's mail schedule.

hubspot-crm rates it partial
(https://knowledge.hubspot.com/integrations/use-marketing-events: events are
created by hand or synced from webinar apps, and "Register contacts to
marketing events with workflows"). espocrm rates it partial (a web campaign
with a lead capture form, no event object with capacity,
`application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json:30`).
pipedrive unknown, kiss no.

## What changes

- An event record: title, start and end, a venue or an online join link, a
  number of seats, a closing date for registration and a status.
- An event belongs to a campaign. The campaign page gets an Event section to
  create it or open it.
- A sign-up on that campaign's landing page still becomes a contact, a lead
  and a touchpoint, as today. It now also becomes a registration for the
  event: registered while there are seats, on the waiting list when there are
  none.
- Each person gets a confirmation mail with a calendar file and a link to
  cancel. A cancellation frees the seat for the first person on the waiting
  list, who gets a mail.
- A reminder goes out a day before, with the join link for an online event.
- The event page lists the registrations with their status and counts, lets
  the team add someone by hand, and lets them mark who attended.
- Attending counts as a touchpoint of the campaign, so the campaign report
  shows how many came, not only how many signed up.

## Out of scope

- Paid tickets and ticket types. An event is free in this change.
- Closing the form on the landing page when the event is full. The form stays
  open and a late sign-up goes on the waiting list; the confirmation says so.
  Showing "full" on the page is a portaliq follow-up.
- Check-in by QR code at the door.
- Syncing webinar tools such as Zoom or Teams.

## Impact

- New schemas `marketingEvent` and `eventAttendee` in a new fragment
  `lib/Settings/register.d/98-marketing-events.json`; `campaign` gains
  `eventId`; `TouchpointService::KINDS` gains `attend`.
- `LandingPageFormSubmittedListener::ingest()` registers the sign-up when the
  campaign has an event.
- New `lib/Service/Marketing/EventRegistrationService.php`,
  `lib/Service/Marketing/EventEmailService.php`,
  `lib/BackgroundJob/EventReminderJob.php`, and a public cancel route.
- New pages Events and EventDetail in `src/manifest.d/78-marketing-campaigns.json`,
  an Event section on CampaignDetail.
