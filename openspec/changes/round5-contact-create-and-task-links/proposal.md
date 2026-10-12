# Proposal: round5-contact-create-and-task-links

kind: fix. Round 5 of the pipelinq review (cloud check of 10 October 2026), lane R5-PQ-A.

## Summary

The round-4 cloud check found five things wrong around contact persons and tasks, and one that turned out to be fine.

1. **Adding a contact person on a client page failed.** "Add contact person" on the Contacts tab sent the form straight to OpenRegister, which refused it with 400 "The required property (contactsUid) is missing". The `contact` schema requires the uid of the Nextcloud contact card, and only `POST /api/contacts-sync/create` provisions it. The Clients and Contacts index pages already go through that path; the object-list widget in the tab could not. The tab now uses `ContactAwareObjectList`, the library's CnObjectListWidget with one method replaced: a create for `client` or `contact` goes through the contact-first path, anything else keeps the library's create. A failed create shows the backend's own sentence.
2. **The task notification opened OpenRegister.** OpenRegister builds the notification link from the app's manifest deep links, and the manifest had none for `crmTask`. It now has `/apps/pipelinq/tasks/{uuid}`.
3. **Tasks had no name.** The `crmTask` schema had no `objectNameField`, and its title property `subject` is not in OpenRegister's name fallback. So a task's name was its uuid: the activity read "Task <uuid> updated", and the task page heading fell back to "Task". A register fragment names a task after its subject. Existing tasks get their name on their next save.
4. **The Contacts list on a lead page.** Its note says the Add button is off because a created contact would get the lead as a scalar in the `leads` array and be refused. The button was still on, because the widget treats a missing `allowCreate` as on. It is now off, as the note says.

## Not changed, with the reason

- **The edit form sends no unlock on Save.** Measured on :8099: after `POST .../lock` the object carries `@self.locked`; after a `PUT` with the same body the form sends, `@self.locked` is gone. OpenRegister releases the lock on the write, so the store copy no longer says the user holds it and CnDetailPage rightly skips the unlock. Cancel and close send it because no write happened.
- **The "Saved just now" text next to a failed create.** That is the local draft indicator of CnFormDialog in @conduction/nextcloud-vue: it reports that the form's draft was kept in the browser, not that the object was saved. It needs a library change (see the hand-back).
- **The card in the editing user's own address book.** The contacts-sync spec says every user syncs to their own address book ("Shared Pipelinq objects sync per-user"), so this is the specified behaviour. The same spec also mentions a "Pipelinq CRM" address book; that conflict is a question for Ruben.
