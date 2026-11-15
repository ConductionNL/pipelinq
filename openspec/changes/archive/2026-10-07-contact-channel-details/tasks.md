## 1. Schema and l10n

- [x] Add `lib/Settings/register.d/16-contact-channel-details.json`: `emails[]`, `phones[]`, `socialProfiles[]`, `preferredChannel`, `timezone`, `language` on `client` and `contact`, each property with a `title` and `description` (gate-28 schema-property-titles).
- [x] Add nl/en l10n entries for every new schema string in `l10n/en.json`/`l10n/nl.json` and rebuild `l10n/en.js`/`l10n/nl.js` (`npm run l10n:build`); confirm `npm run check:schema-l10n` does not grow the ratchet.

## 2. Contacts sync (both directions)

- [x] `ContactDataBuilder`: typed extraction of `EMAIL`/`TEL`/`X-SOCIALPROFILE` into `emails[]`/`phones[]`/`socialProfiles[]` on import, with the legacy scalar mirror set from the primary entry.
- [x] `ContactVcardPropertyBuilder`: build multi-valued `EMAIL`/`TEL`/`X-SOCIALPROFILE` vCard properties from the typed arrays on write-back, falling back to the legacy scalar when an array is empty.
- [x] `ContactSyncService::findContactByUid()`: request the IManager `types` search option so import sees `TYPE` information.
- [x] Unit tests for both directions, including the untyped/unmapped-TYPE and empty-array fallback cases.

## 3. Upgrade backfill

- [x] `lib/Repair/BackfillContactChannelArrays.php`: idempotent, non-destructive backfill of `emails[0]`/`phones[0]` from the legacy scalar fields; registered in `appinfo/info.xml` `post-migration`, after `UnifyClientContactIdentity`.
- [x] Unit tests: seeds from scalar, leaves existing arrays untouched, skips records with neither, covers both schemas.

## 4. Segment rule builder

- [x] `SegmentService`: dotted `arrayProp.subProp` field resolution (`resolveFieldType`) and any-element-matches evaluation (`evaluateProjectedLeaf`) for array-of-object schema properties.
- [x] Fix the pre-existing `published:` argument bug in `resolveSchemaProperties()` while touching this method (merged from the sibling `marketing-segments-ui-repair` fix; verified present after merge).
- [x] Unit tests for dotted-path validate/evaluate, including the non-array-parent and unknown-sub-property rejection cases.

## 5. Detail-page display and edit

- [x] `src/components/ContactChannelsSection.vue` (`kind: 'section'`, registered in `src/registry.js`): compact linked list (mailto/tel/profile URL) with kind/network chips, primary/verified indicators, add/edit/remove.
- [x] `src/modals/ContactEmailPhoneModal.vue` and `src/modals/ContactSocialProfileModal.vue`: single-entry add/edit forms, no store access of their own.
- [x] Wire the section into `ClientDetail` and `ContactDetail` `bodyWidgets` in `src/manifest.json`.
- [x] Primary-mirror sync: `persist()` recomputes the legacy scalar `email`/`phone` from the array's primary entry on every array save; write-back sync is triggered after a successful save.

## 6. Seed data and docs

- [x] Add a LinkedIn handle, a Mastodon handle and a mobile number to a few demo contacts in `lib/Settings/demo_seed_data.json`.
- [x] Update `docs/Features/client-management.md` with the new channel fields.

## 7. Tests and verification

- [x] `tests/e2e/spec-coverage/contact-channel-details.spec.ts`: UI-observable scenarios for the detail-page display and add/edit/remove flow (not run locally; linted).
- [x] Run `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links`, `npm run check:schema-l10n`, and the Hydra gates; fix any pre-existing finding encountered in touched files.

## Acceptance Criteria

- `client`/`contact` carry the new typed properties; the legacy `email`/`phone` fields are unchanged in shape and kept current.
- Contacts sync maps the typed arrays both ways; existing single-value behaviour is preserved as a fallback.
- Existing records backfill on upgrade, idempotently and non-destructively.
- The two detail pages display and let a user edit the channels without a new custom widget.
- A segment rule can validate and evaluate a dotted `arrayProp.subProp` field.
