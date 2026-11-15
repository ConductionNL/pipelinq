---
kind: code
---

# Proposal: walk-in-queue-page

## Summary

The appointment-booking spec asks for a walk-in operator view: the walk-in tickets of today, in order, with call next,
serve and abandon. The only UI for it, `WalkInQueuePanel`, was mounted nowhere, so no user could reach it, and it has
been deleted. This change specifies the view again as a manifest page over the `walkInTicket` schema, so it is built
from library widgets (an index or work queue on a filter, header and row actions) instead of a pipelinq component.

`WalkInQueueService` keeps its write methods for that page.

## Status

Specified, not built. Ruben accepted the recommendation (PL-ABSTRACT question 10, 2026-10-21): delete the dead panel and
open this change.
