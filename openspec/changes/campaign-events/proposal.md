---
kind: code
screens: [PqEvenement]
---

## Why

The PqEvenement board draws an event a campaign holds (an information evening): when and where, the places and how
many are taken, the waiting list, where the registrations came from, the registrations themselves with their status,
the sign-up form and the history. pipelinq had campaigns but no event and no registration, so there was nothing to
open.

## What changes

- Two schemas (`register.d/98-campaign-events.json`): `campaignEvent` (title, reference, campaign, registration
  status, start and end, venue, room, places, hosts, reminder, the portaliq form and page) and `eventRegistration`
  (event, name, e-mail, address and street, people, registered via, registered on, status confirmed, waiting list or
  cancelled, waiting list position, question, contact, new in pipelinq).
- An event page (`/campaign-events/:id`): four counts over its registrations, tabs Overview, Registrations and
  History, and the form, the location and the history beside them. The counts are queries on the registrations of
  this event, never numbers kept on the event.
- An events list (`/campaign-events`, table, cards and calendar) and a registrations list (`/event-registrations`,
  with Confirmed, Waiting list and Cancelled lenses and a board by status).
- The campaign page lists its events in an Events card on the Overview tab.

Not in this change: the reminder mail, the door check-in, and turning a registration without a record into a contact
and a lead (the board's "New in pipelinq" count reads the `newContact` flag a later intake sets). Those are actions
with side effects that need their own change; the page does not draw buttons for them.
