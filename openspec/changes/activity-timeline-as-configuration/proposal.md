# Activity timeline as configuration

## Why

The client page drew its activity with an app-local ActivityTimeline over GET /api/timeline. nextcloud-vue's `timeline` now reads an app endpoint a page at a time with type chips and a configured empty sentence, so the section is configuration.

## What changes

- ClientDetail's Activity section is a `timeline` body widget over `/api/timeline` (entityType client), with the chips Contact moments, Tasks, Email and Calendar and Load more.
- Removed: ActivityTimeline and its registry entry.
- Each event keeps its icon: a contact moment by its channel (phone, email, other), the rest by type (`endpointSource.icons`).

## Needs

nextcloud-vue `timeline` `endpointSource`, `typeChips`, `emptyText` and `icons` (branch `feature/pl-abstract-pl1-timeline-endpoint`); body sections with a widget `type` (branch `feature/pl-abstract-pl1-handoff`).
