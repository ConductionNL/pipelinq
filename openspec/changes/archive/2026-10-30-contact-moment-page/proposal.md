# Contact moment page

## Why

Board PqContactmoment draws the detail of a contact moment (a ticket of type `interaction`). Live showed the generic ticket overview (Ticketgegevens, Gesprek, Zaak) on it.

## What changes

The simple TicketDetail overlay serves both kinds, gated by `ticketType`:

- **Contact moment:**
  - Overview: Contact details (client, channel, when, duration, handler, subject), Conversation notes (`record-notes`: saves as you type, outcome select with "Note the outcome"), About this request (the parent ticket).
  - Side column: Client, Handling, Linked case, Suggested colleagues, Follow-up (open callback tasks on the moment, "Plan a callback").
  - Its own what-now steps (`nextStep.variants`) and the Contact moments crumb with the reference.
- **Request, complaint and report:** keep their overview and side cards; they hide on a contact moment.

Needs nc-vue 24t2l feat/contact-moment-page-parts: `record-notes`, crumb `visibleWhen`, `nextStep.variants` and the schema for them.
