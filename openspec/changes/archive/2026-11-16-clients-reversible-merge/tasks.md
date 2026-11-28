# Tasks: clients-reversible-merge

## 1. Register

- [x] 1.1 Declare `x-openregister-merge` on `client` and `contact`; add `merged` to `client.accountStatus` and a `recordStatus` to `contact`
  - Verify: register import logs no `PARTIAL IMPORT` and no `merge.invalid-*` warning from `MergeAnnotationValidator`
  - done: `lib/Settings/register.d/99-zzzzzz-client-reversible-merge.json`: `x-openregister-merge` on client (`statusField: accountStatus`) and contact (new `recordStatus`), `mergedStatus: merged`, `reversalWindowDays: 30`; `accountStatus` gains `merged`; client 1.7.0, contact 1.2.0. The PARTIAL IMPORT check is a live import, not run here.

## 2. References

- [x] 2.1 `lib/Listener/ClientMergeReferenceListener.php` moving the seventeen references on execute and back on reversal, with the operation stamp
  - Verify: PHPUnit with a fake object service: execute moves a lead and a ticket, reversal returns them, a row edited in between is skipped and reported
  - done: `lib/Listener/ClientMergeReferenceListener.php` over `lib/Service/Merge/PartyReferenceMover.php`, registered before MergedAwayRecordListener. The moved rows are listed on the merged-away record (`mergeMovedRows`) rather than stamped on each row, so no reference schema needs a new property; a reversal moves exactly those rows back, skips one that no longer points at the survivor, and logs it. Contacts are handled the same way. The reference list is `PartyReferences` (17 client, 18 contact properties, read from the register; `zgwEndpoint.clientId`, `socialAccount.clientId` and the survey `contactRef`s hold other ids and are listed as no reference). `MergedAwayRecordGuard` lets the merge write the list. `tests/Unit/Listener/ClientMergeReferenceListenerTest.php`.
- [x] 2.2 Guard test listing every property that references `client` or `contact` across `lib/Settings` and failing when the listener's list misses one
  - Verify: the test fails when a fixture adds `invoice.client`, passes on the real register
  - done: `tests/Unit/Service/Merge/PartyReferencesRegisterTest.php`, by property name and by `$ref`; it found `salesQuote.client`, `posTransaction.customer` and `relationship.from/toContact`, which the design's list lacked.
- [x] 2.3 Keep the merged-away Nextcloud contact during the window and relink it on reversal
  - Verify: PHPUnit on the contact handling
  - done: `lib/Service/Merge/MergedAwayCard.php`, called by `ClientMergeReferenceListener`. Nothing deletes the card; on a merge one note line `[merged-into:<uuid>] Merged into <name>` is written to the card, on a reversal it is taken off again. OpenRegister keeps the merged-away record's data (only its status changes) and restores it on reversal, so its `contactsUid` still names that card. A card in a read-only address book, or one that cannot be written, is logged and never stops the merge. `tests/Unit/Service/Merge/MergedAwayCardTest.php`, `tests/Unit/Listener/ClientMergeReferenceListenerTest.php`.

## 3. Screens

- [x] 3.1 Merge into another record dialog in `src/modals/`, previewing through `merge#preview` and executing through `merge#execute`
  - Verify: Playwright `tests/e2e/client-merge.spec.ts` merges two clients and sees the second one's lead on the survivor
  - done: not built in pipelinq, by decision. The spec (REQ-CRM-001: "OpenRegister's steward surface SHALL offer the merge and its preview") and screens.md ("pipelinq has no board of its own for merging") put starting a merge in OpenRegister, so pipelinq adds no dialog of its own. The Playwright check is a live test and was not run.
- [x] 3.2 Merges section on the survivor with Undo inside the window, through `merge#reverse`
  - Verify: Playwright undoes the merge from 3.1 and sees both clients with their own leads again
  - done: `src/components/merge/RecordMergesSection.vue` over `src/services/mergeOperations.js`, registered as the `RecordMergesSection` body section and placed on ClientDetail and ContactDetail (`merges`). It lists OpenRegister's `mergeOperation` rows with `mergedIntoUuid` = this record, offers Undo (one confirmation, then `merge#reverse`) while `mergedAt` + 30 days has not passed and the row is still reversible, and shows "Final since <date>" after it (REQ-CRM-003). Kept in pipelinq because the spec's scenarios open the Merges section of the client. `tests/vitest/recordMergesSection.spec.js`, `tests/vitest/mergeOperations.spec.js`. The Playwright check is a live test and was not run.
- [x] 3.3 Merged-away record shows merged into with a link
  - Verify: Vitest on the detail header state
  - done: already built by #3113 (MergedAwayRecordListener, the merged-into line under the party strip).

## 4. Docs

- [x] 4.1 `docs/Features/client-merge.md`: what moves, what the thirty days mean
  - Verify: docs build exit 0
  - done: `docs/Features/client-merge.md`.
