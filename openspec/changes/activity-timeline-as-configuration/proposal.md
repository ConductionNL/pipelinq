# Activity timeline as configuration

## Why

The client page drew its activity with an app-local ActivityTimeline over GET /api/timeline. nextcloud-vue's `timeline` now reads an app endpoint a page at a time with type chips and a configured empty sentence, so the section is configuration.

## What changes

- ClientDetail's Activity section is a `timeline` body widget over `/api/timeline` (entityType client), with the chips Contact moments, Tasks, Email and Calendar and Load more.
- Removed: ActivityTimeline and its registry entry.
- Not kept: the per-channel icon of a contact moment (phone, email, other); every event shows its type label and author instead.

## Needs

nextcloud-vue `timeline` `endpointSource`, `typeChips` and `emptyText` (branch `feature/pl-abstract-pl1-timeline-endpoint`); body sections with a widget `type` (branch `feature/pl-abstract-pl1-handoff`).
