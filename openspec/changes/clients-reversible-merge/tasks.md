# Tasks: clients-reversible-merge

## 1. Register

- [ ] 1.1 Declare `x-openregister-merge` on `client` and `contact`; add `merged` to `client.accountStatus` and a `recordStatus` to `contact`
  - Verify: register import logs no `PARTIAL IMPORT` and no `merge.invalid-*` warning from `MergeAnnotationValidator`

## 2. References

- [ ] 2.1 `lib/Listener/ClientMergeReferenceListener.php` moving the seventeen references on execute and back on reversal, with the operation stamp
  - Verify: PHPUnit with a fake object service: execute moves a lead and a ticket, reversal returns them, a row edited in between is skipped and reported
- [ ] 2.2 Guard test listing every property that references `client` or `contact` across `lib/Settings` and failing when the listener's list misses one
  - Verify: the test fails when a fixture adds `invoice.client`, passes on the real register
- [ ] 2.3 Keep the merged-away Nextcloud contact during the window and relink it on reversal
  - Verify: PHPUnit on the contact handling

## 3. Screens

- [ ] 3.1 Merge into another record dialog in `src/modals/`, previewing through `merge#preview` and executing through `merge#execute`
  - Verify: Playwright `tests/e2e/client-merge.spec.ts` merges two clients and sees the second one's lead on the survivor
- [ ] 3.2 Merges section on the survivor with Undo inside the window, through `merge#reverse`
  - Verify: Playwright undoes the merge from 3.1 and sees both clients with their own leads again
- [ ] 3.3 Merged-away record shows merged into with a link
  - Verify: Vitest on the detail header state

## 4. Docs

- [ ] 4.1 `docs/Features/client-merge.md`: what moves, what the thirty days mean
  - Verify: docs build exit 0
