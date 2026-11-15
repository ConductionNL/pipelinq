# Tasks: contact-person-page-as-board

### Task 1: The contact page is the board
- **spec_ref**: `openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-is-the-board`
- **files**: `src/manifest.json`, `src/components/widgets/sectionWidget.js`, `l10n/*`
- [x] Test first: `tests/vitest/contactPersonPage.spec.js` (red)
- [x] Implement; `detailPageWidgets.spec.js` moves to the board's strip

### Task 2: The address book card
- **spec_ref**: `openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card`
- **files**: `lib/Service/ContactSyncService.php`, `lib/Controller/ContactSyncController.php`, `appinfo/routes.php`, `src/components/ContactsSyncSection.vue`, `src/services/contactSyncApi.js`, `src/registry.js`, `src/manifest.json`
- [x] Test first: `ContactSyncCardStatusTest`, `tests/vitest/contactsSyncCard.spec.js` (red)
- [x] Implement: `GET /api/contacts-sync/status?uid=` (linked, address book, REV)

### Task 3: Compact relationships and channels in Overview
- **spec_ref**: `openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-overview-lists-relationships-and-channels-compactly`
- **files**: `src/components/ContactRelationships.vue`, `src/components/ContactChannelsSection.vue`, `src/registry.js`, `src/manifest.json`
- [x] Test first: `tests/vitest/contactChannelsCompact.spec.js` (red)
- [x] Implement: `compact` prop, `content.compact`
