# Tasks

- [x] 1.1 Move the key, slug and `$ref` in the renewal fragment, and add the shillinq pointer
      **files**: lib/Settings/register.d/96-contract-renewal.json
- [x] 1.2 Follow the slug in the manifests
      **files**: src/manifest.json, src/manifest.d/96-contracts.json
- [x] 2.1 Follow the slug in the services, controller defaults and the dashboard fetch
      **files**: lib/Service/ContractService.php, lib/Controller/SemanticHandoffController.php, lib/Service/Portal/PortalContractService.php, lib/Portal/PortalContributionProvider.php, lib/Service/Marketing/SegmentSignalService.php, lib/Service/Marketing/JourneyFlowCompiler.php, src/services/dashboardData.js
- [x] 3.1 Move the slug in `SCHEMA_SLUGS` and pin the old config key
      **files**: lib/Service/SettingsLoadService.php
- [x] 4.1 Add the rename to the colliding-slug map
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php
- [x] 4.2 Extend the repair test to the third slug
      **files**: tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 5.1 Repoint the test stubs keyed on the old slug
      **files**: tests/Unit/Controller/ContractControllerTest.php, tests/Unit/Service/Marketing/StandardAudiencesTest.php, tests/Unit/Service/Marketing/SegmentSignalServiceTest.php, tests/Unit/Service/Lifecycle/SchemaLifecycleGraphTest.php, tests/Unit/Portal/PortalContributionProviderTest.php, tests/Unit/Service/SemanticHandoffServiceTest.php
