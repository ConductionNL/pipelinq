# Tasks: r6-contact-activity-relations-copy

## 1. Created activity per object type

- [x] 1.1 `lib/Service/ActivityService.php`: `CREATED_SUBJECTS` maps lead and request; any other type publishes nothing
- [x] 1.2 `lib/Service/ObjectEventHandlerService.php`: `displayName()` reads a contact's name from `name`
- [x] 1.3 Tests: `ActivityServiceTest::testPublishCreatedForContactPublishesNothing`, `ObjectEventHandlerServiceTest::testHandleCreatedPassesContactName` and `testCreatedActivityThroughRealServices` (real handler, dispatcher and ActivityService)
  - Verify: all three fail on the old code

## 2. Messages card follows linked contacts

- [x] 2.1 `src/components/widgets/ContactAwareObjectListWidget.js`: announce the create on the window (`cn-walkthrough:object-created`)
- [x] 2.2 `src/services/pageRefreshOnCreate.js`: a new contact sends a page refresh and a widget refresh
- [x] 2.3 `src/views/messaging/MessagingConversationSection.vue`: refetch the client's contacts on a page refresh; empty-state copy without an em-dash (en + nl)
- [x] 2.4 Tests in `tests/vitest/r6ContactActivityRelationsCopy.spec.js`
  - Verify: fail on the old code

## 3. English help text on the contact form

- [x] 3.1 `lib/Settings/register.d/99-zz-form-presentation.json`: English `description` for `verifiedBSN` and `secrecy`, rationale in `x-notes`
- [x] 3.2 `l10n/en.json` + `l10n/nl.json`: the new strings, then `npm run l10n:build`
- [x] 3.3 Test: no visible contact or client field shows a Dutch description, each has a Dutch translation, and the two fit inline
  - Verify: fails on the old schema

## 4. Tour step

- [x] 4.1 `src/menu-layout.simple.json`: the `see-modules` task names the Advanced foldout (en + nl)
- [x] 4.2 Test: the step's task mentions Advanced while the item sits in the footer section
  - Verify: fails on the old layout

## 5. Contacts in the client's Related card

- [x] 5.1 Covered by 2.2: the widget refresh reloads the Related card after a contact is created
- [x] 5.2 Test: a contact create reaches the Related card's refresh channel
  - Verify: fails on the old code

## 6. Verification

- [x] 6.1 Full checks once: check:strict, vitest, lint, format, test:l10n, check:schema-l10n, validate-manifest
