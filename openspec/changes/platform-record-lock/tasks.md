# Tasks: platform-record-lock

## 1. Schemas

- [x] 1.1 (`lib/Settings/register.d/99-zzzzz-record-lock.json`, a fragment per ADR-037 rather than an edit of the monolith, so the import version moves on its own; `tests/Unit/Settings/RecordLockSchemaTest.php` reads the merged register) Add `"x-openregister-archive": {"enabled": true}` to the `configuration` of the `client` and `lead` schemas in `lib/Settings/pipelinq_register.json` and of the `ticket` schema in `lib/Settings/register.d/99-unify-ticket-supertype.json`
  - Verify: PHPUnit `tests/Unit/Settings/RegisterAnnotationsTest.php` asserts the switch on all three schemas; after `occ openregister:import` a `POST .../freeze` on a client answers 200 instead of a refusal
- [x] 1.2 (same fragment; `RecordLockSchemaTest::testIsFinishedNamesExactlyTheFinalStates` compares against the merged lifecycle) Add a non-materialised calculation `isFinished` to `lead` (status is won or lost) and `ticket` (status is one of its five final states), built from `or` and `eq`
  - Verify: PHPUnit asserts each `isFinished` names exactly the lifecycle's `final` list; a read of a won lead returns `isFinished: true`
- [x] 1.3 (`SlaDeadlineSweepJob::processEntity()` skips a frozen row before it evaluates or saves, `tests/Unit/BackgroundJob/SlaDeadlineSweepJobTest.php`. The other background writers to client, lead or ticket already catch per record and log: `DealCreatedListener` and `DealUpdatedListener` (lead), `SlaObjectCreatedListener` and `SlaObjectUpdatedListener` (ticket, and they fire on a save a frozen ticket never gets), `WebhookProcessorJob`. `BrpRetentionJob` writes the contact schema, not the client. Contacts sync only creates.) List every `saveObject` on client, lead and ticket under `lib/` (`git grep -n saveObject lib/`), and make each background path skip a frozen record or log the refusal instead of failing the run
  - Verify: PHPUnit for `SlaDeadlineSweepJob` and the contact sync with a frozen record: the run completes and the record is unchanged

## 2. Lock and unlock on the detail pages

The Playwright verifies of 2.1 to 2.4 (`tests/e2e/record-lock.spec.ts`) wait for the app's live pass; the build lane did not run against an instance. Unlock needs a nextcloud-vue release with the api-call DELETE.

- [x] 2.1 (header action `lock-record` on the three pages; `npm run check:manifest`) Add a Lock header action to ClientDetail, LeadDetail and TicketDetail in `src/manifest.json`: `api-call`, POST `/apps/openregister/api/objects/pipelinq/<schema>/@objectId/freeze`, `confirm: true`, visible when `@self.frozen` is empty and, on lead and ticket, `isFinished` is true
  - Verify: `npm run check:manifest` exit 0; Playwright `tests/e2e/record-lock.spec.ts` locks a client and sees Unlock in the Actions menu
- [x] 2.2 (an `api-call` with `method: DELETE` instead of a registry handler: a registered handler on a detail page receives no object id in nextcloud-vue 2.165, so the api-call learned DELETE in nextcloud-vue instead, see design D4) Add `unlockRecord` (`kind: 'handler'`) to `src/registry.js`: DELETE on the same address, toast, page refresh; and an Unlock header action naming it, visible when `@self.frozen` is not empty
  - Verify: Vitest mocks axios and asserts the DELETE address and the refresh; Playwright unlocks the client from 2.1 and edits its phone number
- [x] 2.3 (`client-locked`, `lead-locked`, `ticket-locked` banners, first in each page's band; local `visibleWhen` on `@self.frozen` works in 2.165) Add a banner widget to the three pages that shows while `@self.frozen` is not empty and names who locked the record and when; if the banner's `visibleWhen` cannot read the page object in 2.57.1, use its `source` mode filtered on `@objectId`
  - Verify: Playwright sees the banner on a locked lead and not on an unlocked one
- [ ] 2.4 An edit to a locked record shows OpenRegister's refusal in the edit dialog and changes nothing
  - Verify: Playwright edits a locked ticket, reads the refusal text naming the user, and reloads to find the old value

## 3. The delete half

- [x] 3.1 (OpenRegister already carries it as open change `archival-frozen-refuses-delete-and-dates-follow`, task 1.1 `FrozenObjectDeleteGuardListener`; no new issue) Open the OpenRegister issue for "a frozen object refuses deletion" (guard on `ObjectDeletingEvent`), link it from this change, and record its number in the PR body
  - Verify: the issue exists on ConductionNL/openregister and is linked here
- [ ] 3.2 Once OpenRegister ships it, add the delete case to `tests/e2e/record-lock.spec.ts`
  - Verify: Playwright deletes a locked client from the Clients list and sees the refusal; the client is still there

## 4. Text and docs

- [x] 4.1 (en and nl in `l10n/*.json`, `.js` regenerated with `npm run l10n:build`) English strings and Dutch translations for Lock, Unlock, the confirm text and the banner, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [x] 4.2 User doc page `docs/Features/record-lock.md`: when to lock, who can unlock, and that a lock does not stop retention
  - Verify: docs build exit 0
