# Tasks: round4-contact-write-back

- [x] 1.1 `lib/Service/ContactVcardWriterService.php`: update an existing card by its URI in the addressbook that holds it; create only when no addressbook holds the UID; skip the system and read-only addressbooks.
- [x] 1.2 `tests/Unit/Service/ContactVcardWriterServiceUpdateTest.php` with an addressbook that follows Nextcloud's create/update rules; fails on the old code (4 of 4).
- [x] 2.1 `src/services/contactSyncApi.js`: a failed write-back shows an error toast; en + nl in `l10n/`.
- [x] 2.2 `tests/vitest/contactWriteBackFailure.spec.js`; fails on the old code (2 of 3).
- [x] 3.1 Live check on :8099: edit the phone of a client whose contact already existed, read the card back.
