# Proposal: pos-shift-approve-secondary

## Why

pl4 on board PqKassadienst: a counted shift showed "Verschil goedkeuren" as the primary (blue) header button without an
icon. The board draws it as a secondary header button with its check icon.

## What changes

- The cash shift page no longer makes Approve difference the counted shift's primary action; it is a quick action, a
  secondary header button that keeps its `Check` icon and its `visibleWhen` (closed and pending).
- Close and count shift stays the open shift's primary action.
