---
kind: code
---

# The quote page shows all its fields and a history in words

## Why

Board PqOfferte draws the Offerte card with all eight fields. Live folded two of them behind "Alle 8 velden tonen", because the card's grid cell was one row too short. The History card printed the raw audit action ("update") on full rows, where the Dq history cards say what happened on compact rows.

## What changes

- The quote card's cell grows from five rows to six, and the lines card moves down one row and grows to seven rows, so the notes keep their place.
- The side History card takes `density: "compact"`, `actionLabels` (create "Created by {actor}", update "Updated") and status `changeLabels` (Sent, Accepted, Rejected, Ordered).
- New string: "Created by {actor}" (nl "Aangemaakt door {actor}").

## Out of scope

The extra "Meer" menu stays (board call). The lines' editable quantity and discount cells and the column sort and filter heads need list-widget features of their own.
