---
kind: code
---

# Proposal: ticket-handover-history

## Summary

A handler who passes a ticket to a colleague wants to see afterwards what became of it. The data is there: every change to a ticket's assignee, status and notes is in OpenRegister's audit trail, and the host sidebar's Audit trail tab (nextcloud-vue `CnObjectSidebar`) lists it. But it lists raw field changes, it sits in a sidebar the ticket page does not open by default, and board PqTicket draws a History tab on the page that says in words who passed the ticket to whom and what happened next. No requirement says any of this; the row's spec is `none`.

nextcloud-vue already has what is needed: the `audit-trail` widget with `actionLabels` and `changeLabels` turns audit entries into sentences, and pipelinq uses it for the POS history (`shift-side-history` in `src/manifest.json`, spec `pos-history-in-words`). This change specifies the ticket history and mounts the same widget on the ticket page, configured for handovers. It is configuration over a shared widget, not ticket-specific code.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **req-handover-log** See what happened to a request after you passed it to a colleague (built in part: the sidebar Audit trail tab). Board: PqTicket (History tab).

## What changes

1. Spec `ticket-handover-history`: the History tab, handovers in words, what happened after, and who may see it.
2. `src/manifest.json` TicketDetail: a History tab with the `audit-trail` widget and change labels for assignee, status and team changes.
3. If the widget cannot put the old and new value into a label (`{from}`, `{to}`), that is added in nextcloud-vue, where every app gets it.

## Screens

PqTicket (see `screens.md`).

## Out of scope

- The audit trail itself (OpenRegister owns it).
- Notifying the original handler when the ticket changes later (a separate row if wanted).
