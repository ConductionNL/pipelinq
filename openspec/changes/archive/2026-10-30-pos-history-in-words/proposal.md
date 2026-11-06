# Proposal: pos-history-in-words

## Why

pl4 on boards PqKassabon (7) and PqKassadienst (4): the side History card lists what happened in words ("Aangemaakt door
Pieter Jansen", "Bevestigd", "Gesloten en geteld"). Live printed the raw audit action, "update", with "System" under
it. The Dq history cards solve this with the audit-trail card's `actionLabels` and `changeLabels`, drawn stacked and
compact (DqTaak, the LHS recommendation).

## What changes

- The receipt's side History: create reads "Created by {actor}", an update "Updated", and a status change to
  confirmed, settled or refunded reads "Confirmed", "Settled" or "Refunded".
- The shift's side History: create reads "Opened by {actor}", an update "Updated", a status change to closed "Closed and
  counted" and to reconciled "Reconciled".
- Both cards are `layout: "stacked"`, `density: "compact"`, as Dq's side history.
- New strings en and nl: "Created by {actor}" (Aangemaakt door {actor}), "Opened by {actor}" (Geopend door {actor}),
  "Closed and counted" (Gesloten en geteld).

The live example data holds only system updates, so it will read "Bijgewerkt" until real events happen.
