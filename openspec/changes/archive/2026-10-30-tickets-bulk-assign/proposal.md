# Proposal: tickets-bulk-assign

## Summary

Board PqTicketsSelectie draws "Toewijzen aan" in the tickets bulk band: the selected tickets go to one handler. The tickets list declares it as a bulk action that opens the library's CnAssignDialog with `bulkJob`, so OpenRegister's generic `openregister:assign` job writes `assignee` on every selected ticket. OpenRegister checks each ticket's update rule for the user on the server before it writes, reports the tickets the user may not change as refused, and keeps the typed reason on every ticket. pipelinq adds no endpoint and no loop.

## Scope

- `src/manifest.json`: the Tickets page `bulkActions` entry `assign`.
- Three strings (en, nl).
- Needs nextcloud-vue feat/pq-c30-assign-dialog-bulk-job (CnAssignDialog `bulkJob`). With an older library the dialog opens but posts nothing.
