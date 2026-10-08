# Tasks: round3-review-points

- [ ] 1.1 `lib/Settings/register.d/98-party-identity-editable.json`: name, email and phone of client and contact are not read-only.
- [ ] 1.2 `tests/Unit/Settings/ReviewRound3RegisterTest.php` on the merged register; the e2e round trip expects the rename to take effect.
- [ ] 2.1 `src/services/userDisplayName.js`: the `userDisplayName` formatter, registered in `createAppFormatters`.
- [ ] 2.2 Manifest: every user-field column and data widget field uses the formatter; `tests/vitest/userDisplayName.spec.js` walks the manifest against the merged register.
- [ ] 2.3 `lib/Listener/TaskCreatedByCreatingListener.php` fills `createdBy` of a new task; unit test.
- [ ] 3.1 `ServiceForm.vue` keeps product, quantity and unit through `serializeStep()` in `src/services/serviceSteps.js`; vitest.
- [ ] 4.1 `leadProduct.configuration.objectNameField` is `{{ product }}`; test on the merged register.
- [ ] 4.2 `src/services/pageRefreshOnCreate.js`: a created line item refreshes the page; vitest.
- [ ] 5.1 Every notification rule gets an "Open" action to the object detail; test on the merged register.
- [ ] 6.1 l10n en + nl for new strings.
- [ ] 6.2 Live check on :8099 for items 1 to 5.
