# Tasks

- [x] 1.1 Move the key, slug and the four register-list entries
      **files**: lib/Settings/pipelinq_register.json, lib/Settings/register.d/40-pos-cash-management.json, lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json, lib/Settings/register.d/60-pos-split-tender.json, lib/Settings/register.d/97-lead-activity.json
- [x] 1.2 Follow the slug in the mock register and the manifests
      **files**: lib/Settings/pipelinq_mock_register.json, src/manifest.json, src/manifest.d/85-kcc-werkplek.json
- [x] 2.1 Follow the slug in the callback service, schema map and journey runner
      **files**: lib/Service/CallbackService.php, lib/Service/SchemaMapService.php, lib/Service/Marketing/JourneyStepRunner.php
- [x] 2.2 Follow the slug in the object-type map, export list and werkplek dialog
      **files**: src/config/objectTypes.js, src/views/export/ExportJobForm.vue, src/components/werkplek/WerkplekNewTaskDialog.vue
- [x] 3.1 Move the slug in `SCHEMA_SLUGS`, pin the config key, extend the map to ten renames
      **files**: lib/Service/SettingsLoadService.php, lib/Repair/RenameCollidingSchemaSlugs.php, tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 4.1 Repoint the schema-slug test stubs, leaving activity types alone
      **files**: tests/Unit/Service/CallbackServiceTest.php, tests/Unit/Service/Lifecycle/SchemaLifecycleGraphTest.php, tests/Unit/Service/Marketing/JourneyStepRunnerTest.php
