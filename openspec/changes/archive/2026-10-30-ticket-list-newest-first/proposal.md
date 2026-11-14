# Proposal: ticket-list-newest-first

## Summary

The ticket list ("Vragen en meldingen") in the simple structure shows the newest ticket first. Its count line says "nieuwste eerst".

## Why

Zuiddrecht live round 3 (14 Oct): a resident asked a question through the portal and the KCC agent did not find the ticket on the first page of `/tickets`. The list sorted by waiting time, oldest first, so 166-day-old tickets filled page one and the new question sat on the last of five pages.

The queue (Wachtrij) is where tickets are worked oldest first (queue-management). This list is where an agent looks for what just came in. Sorting by waiting time stays one click away on the column header.

## What changes

- `src/menu-layout.simple.json`: the ticket list sorts on `occurredAt` descending; the count line reads "newest first" / "nieuwste eerst".
- `tests/vitest/simpleTicketListNewestFirst.spec.js` pins the order and the Dutch count line.

## Noted, not changed

The "All" filter counts resolved and converted tickets although the count line says "open tickets". That is a separate decision on what "All" means.
