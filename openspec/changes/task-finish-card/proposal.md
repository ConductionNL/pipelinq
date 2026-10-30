# Proposal: task-finish-card

## Summary

Board PqTaak draws "Deze taak afronden" between the task and its documents: a Resultaat text area ("Wat kwam er uit het terugbellen?"), an outlined "Poging vastleggen" and a primary "Afronden". Live has no such card, so the documents sit 402px higher than drawn and the attempt log has no way in from the task page.

The card is the library's record-notes widget with actions: the typed result goes to the existing callback endpoints, `POST /api/callbacks/{id}/attempts` as the attempt's result and `POST /api/callbacks/{id}/complete` as the task's result. Both endpoints check that the user may change the task before they write. Finish shows only while the task is in progress, as the header's Finish does, because the task lifecycle completes from in progress. Nothing is written while typing.

## Scope

- `src/manifest.json`: widget `task-finish` in the Overview tab between `task-data` and `task-files`.
- Four strings (en, nl).
- Needs nextcloud-vue feat/pq-c30-record-notes-actions (record-notes `actions`, `autosave: false`). With an older library the card shows the text area saving as you type and no buttons.
