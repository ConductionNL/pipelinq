# Tasks: contacts-sync

## 1. Schema Update

- [x] 1.1 Add `contactsUid` string property to `client` schema in `pipelinq_register.json`. Optional, no default.
- [x] 1.2 Add `contactsUid` string property to `contact` schema in `pipelinq_register.json`. Optional, no default.

## 2. PHP ContactSyncService

- [x] 2.1 Create `lib/Service/ContactSyncService.php` with constructor injecting `IManager`, `IAppConfig`, `ContainerInterface`, `LoggerInterface`.
- [x] 2.2 Implement `searchContacts(string $query): array` — search via `IManager::search()` across all user addressbooks. Return array of `[uid, name, email, phone, org, addressBookKey]`. Check each result against existing Pipelinq objects to set `alreadyLinked` flag.
- [x] 2.3 Implement `importContact(string $uid, string $addressBookKey, string $type, ?string $clientId): array` — fetch the Nextcloud contact by UID, map vCard fields to Pipelinq schema, create via ObjectService, store contactsUid. Return created object.
- [x] 2.4 Implement `syncToContacts(string $objectType, string $objectId): ?string` — read Pipelinq object, map to vCard properties, create or update via `IManager::createOrUpdate()`. Return the contactsUid. If IManager not available, log and return null.

## 3. PHP Controller & Routes

- [x] 3.1 Create `lib/Controller/ContactSyncController.php` with `search`, `import`, and `writeBack` actions. Inject `ContactSyncService`.
- [x] 3.2 Add 3 routes to `appinfo/routes.php`: GET search, POST import, POST write-back.

## 4. Frontend Import Dialog

- [x] 4.1 Create `src/components/ContactImportDialog.vue` — modal with search input, results list showing name/email/org/alreadyLinked badge, import button per result. Calls `/api/contacts-sync/search` and `/api/contacts-sync/import`.
- [x] 4.2 Add "Import from Contacts" button to ClientList.vue header that opens the import dialog.

## 5. Frontend Sync Integration

- [x] 5.1 Add sync badge to ClientDetail.vue — show "Synced with Contacts" when `contactsUid` is present.
- [x] 5.2 Add sync badge to ContactDetail.vue — show "Synced with Contacts" when `contactsUid` is present.
- [x] 5.3 After save in ClientDetail.vue and ContactDetail.vue, call `/api/contacts-sync/write-back` to sync changes to Nextcloud Contacts. Update `contactsUid` on the local object if returned.

## 6. Build and Verify

- [x] 6.1 Run `npm run build` and verify no errors.
- [ ] 6.2 Test import and write-back via browser.

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
