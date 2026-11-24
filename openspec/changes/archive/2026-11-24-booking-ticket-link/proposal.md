---
kind: code
screens: [PqAfspraak]
---

## Why

Board PqAfspraak shows the request an appointment belongs to, in a "Gekoppeld verzoek" card: Sanne's site visit is
for her permit request PQ-2026-0417. A booking had no field for that, so the link could not be stored.

## What changes

- `appointmentBooking` gets `ticket`, an optional reference to the ticket supertype (schema 1.2.0, fragment
  `99-zz-booking-ticket-link.json`). It is generic: any request, complaint or question can be the one a booking
  belongs to.
- The booking page shows it as a side card, a record list that reads the ticket by its id and opens its page. A booking
  without a linked request says so.
