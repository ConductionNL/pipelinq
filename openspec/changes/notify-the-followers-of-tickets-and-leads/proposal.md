---
kind: code
depends_on: []
---

# Proposal: notify-the-followers-of-tickets-and-leads

## Summary

Colleagues who follow a ticket or a lead get the same updates as its
assignee. Pipelinq adds the recipient block `{"watchers": true}` to the
notification rules of the `ticket` and `lead` schemas that fire after an
object exists: ticket changed, lead changed, lead won and lead lost.
OpenRegister already stores who follows what and resolves that block at
dispatch time. This change is the pipelinq half of the row; the Follow
button itself belongs to nextcloud-vue.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`).

**`work-collaborators`**, "Add colleagues to a case so they can work on it
with you and follow its updates". Rated partial, built.state specified,
linked to the delivered change `openregister/2026-10-05-object-watchers`
(archived, OpenRegister PR #3707).

What that change built, read on openregister development on 7 October 2026:

- `PUT` and `DELETE /api/objects/{register}/{schema}/{id}/watch`, the
  watchers list for a user with `update`, and adding or removing another
  user for a user with `manage` (`appinfo/routes.php:142-161`,
  `lib/Service/Interaction/WatcherService.php`).
- `@self.watching` on object reads and the `_watching=true` lens.
- The recipient block `{"watchers": true}` in `x-openregister-notifications`,
  accepted by `NotificationAnnotationValidator` (`VALID_RECIPIENT_KINDS`,
  line 52) and resolved by `NotificationRecipientResolver` (line 163) to the
  object's watchers, deduplicated against the other recipients, with a read
  check at dispatch time (OpenRegister main spec `notificatie-engine`,
  "A notification rule may address the object's watchers").

What is missing for pipelinq users:

1. Nothing in pipelinq addresses watchers. The ticket and lead rules tell
   only the assignee and, on create, the `sales` group
   (`lib/Settings/register.d/98-update-notifications.json`,
   `lib/Settings/register.d/99-unify-ticket-supertype.json`,
   `lib/Settings/pipelinq_register.json`). A colleague who follows a ticket
   hears nothing. **This change.**
2. No user can follow anything: the ticket and lead detail pages are
   manifest `type: detail` pages rendered by nextcloud-vue, and nextcloud-vue
   has no Follow control and no caller of `/watch`. **Cross-repo,
   nextcloud-vue.** Not written here.

Competitors, from the matrix: hubspot-crm, pipedrive, espocrm and odoo-crm
yes; kiss no.

## What changes

- A new register fragment `lib/Settings/register.d/99-watchers-on-ticket-and-lead.json`
  restates the recipients of `ticket.ticketUpdated`, `lead.leadUpdated`,
  `lead.leadWon` and `lead.leadLost` with `{"watchers": true}` added. The
  fragment loader replaces lists rather than merging them
  (`register.d/README.md`, "Merge semantics"), so each list is written in full.
- `newTicket` and `newLead` stay as they are: an object has no watchers at
  the moment it is created.
- No PHP, no Vue, no new schema property. The watcher list lives in
  OpenRegister, outside the object (ADR-022).

## Out of scope

- The Follow button, the watcher count and the "add a colleague" picker on
  the detail page. They belong to nextcloud-vue's detail page, so every app
  that renders a `type: detail` page gets them at once.
- A "Followed" filter on My Work. No Pq board draws one; it waits for a board.
- Clients, tasks and the other schemas. The row is about cases: tickets and
  leads.
- Leaving out the person who made the change. OpenRegister rules cannot do
  that yet; Ruben accepted that on 7 October for the update rules
  (`98-update-notifications.json` `_meta`).

## Impact

- One new file under `lib/Settings/register.d/`. The fragment hash moves
  `info.version`, so OpenRegister re-imports the register on the next upgrade.
- Users who follow a ticket or lead get Nextcloud notifications for it,
  subject to their own notification preferences.
