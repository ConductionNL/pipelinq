# Tasks: close-pipelinq-register-to-staff

- [x] 1.1 `register.d/99-zzz-register-access-registers.json` and `register.d/99-zzz-register-access.json`: staff-only blocks on the three registers (version 1.1.0), `authenticated` replaced by the staff on 8 schemas, vocabularies readable and staff-written.
  - unit: `RegisterAccessDeclaredTest`
- [x] 1.2 `RegisterAccessSync` grants `crm_group` wherever `sales` is granted and takes a replaced group out; called from `SettingsService::loadSettings` and `updateSettings`.
  - unit: `RegisterAccessSyncTest`, `SettingsServiceRegisterAccessTest`
- [x] 1.3 `crm_read_group`: read and export on client, contact, ticket and partyIndicatorValue, never a write; applied groups recorded so a replaced group is taken out; `SyncRegisterAccessJob`; the Zuiddrecht example set sets `dossiq-medewerkers`.
  - unit: `RegisterAccessSyncTest`, `SettingsServiceRegisterAccessTest`, `RegisterAccessDeclaredTest`
- [ ] 2.1 (live pass, after merge and re-import) The probe in the PR body returns 0 tickets and clients for an account in no group and for a member of `inwoners`, a member of the CRM group still works the KCC queue, and a member of `dossiq-medewerkers` reads a client and cannot update it.
