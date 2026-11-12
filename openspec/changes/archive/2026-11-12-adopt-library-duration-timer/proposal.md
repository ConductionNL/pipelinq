---
kind: code
---

## Why

The boards PqContactmoment and PqTicketContactLoggen draw the duration of a contact moment as a running clock with Start, Stop and Reset. The agent can still type a time. pipelinq had its own CallTimer for this, which nothing used, while both forms asked for typed text. nextcloud-vue now offers the clock as `CnDurationField` with `timer` and `autoStart`.

## What Changes

- The quick log on the client page (PqContactmoment) shows the duration as `CnDurationField` with `timer` and `autoStart`. The clock starts when the form opens and stops when the agent saves.
- The ticket's Log contact dialog (PqTicketContactLoggen) shows the duration as `CnDurationField` with `timer`. The agent starts it by hand.
- Both forms store the duration as an ISO 8601 duration, such as `PT4M23S`.
- The unused `src/components/CallTimer.vue` is removed.

## Impact

- Needs a nextcloud-vue release that contains `CnDurationField` `timer` (nextcloud-vue development, merge #2088). The dependency bump follows that release.
- The werkplek header keeps its own read-only call clock. It counts from the call's start time, which a form field cannot do.
