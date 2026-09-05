# Tasks

- [x] 1.1 Move both keys, slugs, register-list entries and schema references
      **files**: lib/Settings/register.d/30-expense-shillinq-ap.json, lib/Settings/register.d/90-master-data-management.json, lib/Settings/pipelinq_mock_register.json
- [x] 2.1 Follow the entity type in the schema map and the approval listener
      **files**: lib/Service/SchemaMapService.php, lib/Listener/ExpenseApprovalListener.php
- [x] 3.1 Move both slugs in `SCHEMA_SLUGS` and pin both config keys
      **files**: lib/Service/SettingsLoadService.php
- [x] 4.1 Add both renames to the colliding-slug map and extend its test to twelve
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 5.1 Repoint the entity-type stubs, leaving the notification objectType alone
      **files**: tests/Integration/ExpenseApSyncTest.php, tests/Unit/Listener/ExpenseApprovalListenerTest.php
