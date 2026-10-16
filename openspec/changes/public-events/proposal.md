# Public events with registrations

## Why

The event board (PqEvenement) draws a public gathering under a campaign: an
information evening with a place, a number of places, a sign-up form on the
portal and the people who signed up. Pipelinq had no event and no
registration, so the board had no page to land on.

## What changes

- Two schemas: `event` (name, status, place, places, hosts, the sign-up form,
  the reminder moment) and `eventRegistration` (who, where they live, how they
  found the event, whether they have a place, their e-mail address and when
  they checked in).
- A declarative events list and event page, as the board draws them.
- Two actions on the event page: send a reminder to everyone with a place, and
  check a registration in at the door.

## Impact

Additive. The register import has to run again (the fragment moves the
register version) before the pages show data.
