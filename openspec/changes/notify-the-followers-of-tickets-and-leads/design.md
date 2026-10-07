# Design: notify-the-followers-of-tickets-and-leads

## Context

OpenRegister owns following (archived change `2026-10-05-object-watchers`).
A watcher row is per user and per object, stored in `openregister_watchers`
outside the object. The notification engine resolves `{"watchers": true}`
when a rule fires. A leaf app opts in per rule, in its own register
configuration. Pipelinq declares its rules in the canonical
`x-openregister-notifications` dialect (ADR-031, hydra gate 18).

## Decisions

### Which rules get the watchers block

| Schema | Rule | Fragment today | Watchers |
|---|---|---|---|
| ticket | `newTicket` (created) | `99-unify-ticket-supertype.json` | no: nobody can follow an object before it exists |
| ticket | `ticketUpdated` (updated) | `98-update-notifications.json` | **yes** |
| lead | `newLead` (created) | `pipelinq_register.json` | no, same reason |
| lead | `leadUpdated` (updated) | `98-update-notifications.json` | **yes** |
| lead | `leadWon` (transition `win`) | `pipelinq_register.json` | **yes** |
| lead | `leadLost` (transition `lose`) | `pipelinq_register.json` | **yes** |

A ticket's status change is an update, so `ticketUpdated` already covers it.

### One new fragment, lists written in full

`ConfigFileLoaderService` deep-merges fragments in sorted filename order;
objects merge, lists replace. A fragment that wrote only
`recipients: [{"watchers": true}]` would silently drop the assignee and the
`sales` group. So the fragment restates every recipient that is there today
and appends the watchers block:

```json
"leadWon": {
  "recipients": [
    {"kind": "groups", "groups": ["sales"]},
    {"kind": "field", "field": "assignee"},
    {"watchers": true}
  ]
}
```

The file is named `99-watchers-on-ticket-and-lead.json` so it sorts after
`98-update-notifications.json` and `99-unify-ticket-supertype.json`. Editing
those two files in place was the alternative; a separate fragment keeps this
change reviewable on its own and follows the README's "own fragment per
feature" rule. The fragment touches only the `recipients` key, so trigger,
channels and subject keep coming from where they are declared now.

The block is spelled `{"watchers": true}` with no `kind`, exactly as
OpenRegister's validator expects; `{"watchers": "yes"}` fails the schema save
with HTTP 422.

### Twice told

A watcher who is also the assignee or in `sales` gets one notification:
OpenRegister merges and deduplicates the recipient blocks
(`NotificationRecipientResolverWatchersTest::testAWatcherWhoIsAlsoTheAssigneeIsToldOnce`).
Pipelinq adds nothing for that.

### Lost access

A watcher who can no longer read the ticket hears nothing and is dropped from
the list by OpenRegister. Pipelinq adds nothing for that either.

## Screens

No Pq board on canvas `5NkFW28vZUUij43xzxHg5a` draws a Follow control,
a watcher list or a Followed filter. `PqTicket` shows "Suggested colleagues"
for assignment, which is a different feature. This change has no screen; the
row keeps `screen.board: PqTicket` because that is where the nextcloud-vue
Follow control will appear.

## Risks

- Until nextcloud-vue ships the Follow control, the rules address an empty
  list on every live instance. That is harmless, and it means the row stays
  partial after this change lands.
- The fragment duplicates today's recipient lists. A later change to the
  assignee or group recipients of these four rules must edit this fragment
  too, or this fragment wins and the change is lost. The fragment's `_meta`
  says so.
