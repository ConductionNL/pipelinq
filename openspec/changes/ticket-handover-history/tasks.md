# Tasks: ticket-handover-history

## 1. Widget
- [x] 1.1 Check nextcloud-vue's `audit-trail` widget for `{from}` and `{to}` placeholders in `changeLabels` and for a `note` field shown under an entry; if missing, add them in nextcloud-vue with tests (one PR there), and bump pipelinq to the release that has them (V1)
  - Result: nextcloud-vue 2.181.0 (the version pipelinq resolves) fills `{from}`/`{to}` from the matched rule, names users with `values: "user"`, and shows a rule's `note` under its entry; nothing to add there. The handover rule now reads `Passed to {to} by {actor}` with `values: "user"`, so the new assignee shows by display name.
- [x] 1.2 Check that the widget hides values of fields the reader may not read (REQ-THH-002); if it does not, fix it in nextcloud-vue, where the audit entry's field-level RBAC is known (V1)
  - Result: nothing to build. The widget reads `/api/objects/{register}/{schema}/{id}/audit-trails`; on OpenRegister main a caller who is not an administrator gets that history through `ObjectHistoryForReaders`, whose `HistoryEntryRedactor` removes from every entry's `changed` the properties the object's read rules, `writeOnly` flags or lifecycle state hide from that caller (explicit-reveal values arrive masked). The values never reach the browser. With the field gone no `changeLabels` rule matches, so the entry falls back to the tab's `actionLabels.update`, "Updated by {actor}", without the values (nextcloud-vue 2.181.0 `changeRuleFor`).

## 2. Ticket page
- [x] 2.1 `src/manifest.json` TicketDetail: a History tab (`ticket-history`) with the `audit-trail` widget, `actionLabels` create "Created by {actor}", `changeLabels` for `assignee` ("Passed to {to} by {actor}"), `team` ("Moved to team {to} by {actor}") and each ticket status (spec_ref `#REQ-THH-001`) (V1)
- [x] 2.2 The same tab in the simple structure (`src/menu-layout.simple.json`) (V1)
- [x] 2.3 l10n nl and en for every label (V1)
- [x] 2.4 Vitest over the manifest: the tab exists, its labels cover every status in the ticket schema's enum (V1)
- [x] 2.5 (live pass) On :8080: pass a ticket to a colleague with a note, change its status as that colleague, read the History tab as the first user (V1)
  - Written: `tests/e2e/ticket-handover-history.spec.ts` (two non-admin `sales` handlers made for the run, Sanne passes the ticket to Joris, Joris resolves it, Sanne reads the History tab in an empty-storage context). Left unchecked: it has not run against an instance yet, and the note is not asserted because the ticket schema has no handover-note field (a schema and UI decision).

## Status (24 Nov 2026)

- 2.5: `tests/e2e/ticket-handover-history.spec.ts` is written and runs in nightly; it has not been checked live here.
- Not archived: REQ-THH-001 asks for a handover note under the entry, and the ticket schema has no handover-note field yet. That needs a task of its own (a `handoverNote` on the ticket written with the assignee change, and a `changeLabels` rule that shows it).
