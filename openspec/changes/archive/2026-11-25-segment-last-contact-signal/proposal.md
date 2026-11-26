# Proposal: segment-last-contact-signal

## Why

The board PqSegmentBouwer (design-system #760) draws a customer segment "Winkels en restaurants in Zuiddrecht die we de afgelopen 12 maanden spraken" with the rules Plaats is gelijk aan Zuiddrecht, Branche is one of two values, and Laatste contact Na a date. Neither audience offers "Laatste contact", and a client stores no last-contact date. Storing one would be a second source of truth that goes stale with every new contact moment.

## What changes

- `SegmentSignalService` publishes a ninth derived field, `pipelinqLastContactAt`: the latest `occurredAt` of the customer's contact moments (tickets with `ticketType: interaction`). It is read, never stored, like the two pipelinq signals that exist.
- The customer audience in the segment builder offers it as "Last contact" (Laatste contact), a date field with the Before / After operators, next to Plaats (`place`).

## Not changed

- No schema change. `client` and `ticket` keep their properties.
- The contact audience does not get the field.

## Rollback

Revert the commit. Segments saved with the field then match nobody (an unknown field), which is the same shrink-not-widen rule every signal follows.
