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
- [ ] 2.3 Keep the merged-away Nextcloud contact during the window and relink it on reversal
  - Verify: PHPUnit on the contact handling

## 3. Screens

- [ ] 3.1 Merge into another record dialog in `src/modals/`, previewing through `merge#preview` and executing through `merge#execute`
  - Verify: Playwright `tests/e2e/client-merge.spec.ts` merges two clients and sees the second one's lead on the survivor
- [ ] 3.2 Merges section on the survivor with Undo inside the window, through `merge#reverse`
  - Verify: Playwright undoes the merge from 3.1 and sees both clients with their own leads again
- [x] 3.3 Merged-away record shows merged into with a link
  - Verify: Vitest on the detail header state
  - done: already built by #3113 (MergedAwayRecordListener, the merged-into line under the party strip).

## 4. Docs

- [x] 4.1 `docs/Features/client-merge.md`: what moves, what the thirty days mean
  - Verify: docs build exit 0
  - done: `docs/Features/client-merge.md`.
