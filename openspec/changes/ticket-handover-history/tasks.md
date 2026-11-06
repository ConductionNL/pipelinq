# Tasks: ticket-handover-history

## 1. Widget
- [ ] 1.1 Check nextcloud-vue's `audit-trail` widget for `{from}` and `{to}` placeholders in `changeLabels` and for a `note` field shown under an entry; if missing, add them in nextcloud-vue with tests (one PR there), and bump pipelinq to the release that has them (V1)
- [ ] 1.2 Check that the widget hides values of fields the reader may not read (REQ-THH-002); if it does not, fix it in nextcloud-vue, where the audit entry's field-level RBAC is known (V1)

## 2. Ticket page
- [ ] 2.1 `src/manifest.json` TicketDetail: a History tab (`ticket-history`) with the `audit-trail` widget, `actionLabels` create "Created by {actor}", `changeLabels` for `assignee` ("Passed to {to} by {actor}"), `team` ("Moved to team {to} by {actor}") and each ticket status (spec_ref `#REQ-THH-001`) (V1)
- [ ] 2.2 The same tab in the simple structure (`src/menu-layout.simple.json`) (V1)
- [ ] 2.3 l10n nl and en for every label (V1)
- [ ] 2.4 Vitest over the manifest: the tab exists, its labels cover every status in the ticket schema's enum (V1)
- [ ] 2.5 (live pass) On :8080: pass a ticket to a colleague with a note, change its status as that colleague, read the History tab as the first user (V1)
