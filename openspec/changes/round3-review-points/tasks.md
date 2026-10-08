# Tasks: round3-review-points

- [x] 1.1 `lib/Settings/register.d/98-party-identity-editable.json`: name, email and phone of client and contact are not read-only.
- [x] 1.2 `tests/Unit/Settings/ReviewRound3RegisterTest.php` on the merged register; the e2e round trip expects the rename to take effect.
- [x] 1.3 The contact write-back also listens on the library's default object store and knows its `pipelinq-client` type, so the client page's Edit dialog reaches the Nextcloud Contact; `tests/vitest/contactWriteBackDetailPage.spec.js`.
- [x] 2.1 `src/services/userDisplayName.js`: the `userDisplayName` formatter, registered in `createAppFormatters`.
- [x] 2.2 Manifest: every user-field column and data widget field uses the formatter; `tests/vitest/userDisplayName.spec.js` walks the manifest against the merged register.
- [x] 2.3 `lib/Listener/TaskCreatedByCreatingListener.php` fills `createdBy` of a new task; unit test.
- [x] 3.1 `ServiceForm.vue` keeps product, quantity and unit through `serializeStep()` in `src/services/serviceSteps.js`; vitest.
- [x] 4.1 `leadProduct.configuration.objectNameField` is `{{ product }}`; test on the merged register.
- [x] 4.2 `src/services/pageRefreshOnCreate.js`: a created line item refreshes the page; vitest.
- [x] 5.1 Every notification rule gets an "Open" action to the object detail; test on the merged register.
- [x] 6.1 No new UI strings; the notification action label carries its own en and nl in the register.
- [x] 6.2 Live check on :8099 for items 1 to 5. The count refresh fires; OpenRegister then answers from a stale aggregation cache until it evicts by slug (see proposal).
