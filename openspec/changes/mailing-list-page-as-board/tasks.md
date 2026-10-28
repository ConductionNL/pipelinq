# Tasks: mailing-list-page-as-board

### Task 1: The mailing list page is the board
- **spec_ref**: `openspec/changes/mailing-list-page-as-board/specs/marketing-lists/spec.md#requirement-the-mailing-list-page-is-the-board`
- **files**: `src/manifest.d/76-marketing-lists.json`, `src/registry.js`, `src/components/marketing/SubscriptionsSection.vue`, `src/components/marketing/ListSubscriberViewSection.vue`
- [x] Test first: `tests/vitest/mailingListPage.spec.js` (red)
- [x] Implement

### Task 2: Subscribers read by name
- **spec_ref**: `openspec/changes/mailing-list-page-as-board/specs/marketing-lists/spec.md#requirement-a-mailing-lists-subscribers-read-by-name`
- **files**: `lib/Service/Marketing/SubscriptionQueryService.php`, `lib/Service/Marketing/RecipientNameReader.php`
- [x] Test first: `SubscriptionServiceTest::testListRowsCarryTheContactsName`, `RecipientNameReaderTest::testAListReadsTheFullName` (red)
- [x] Implement
