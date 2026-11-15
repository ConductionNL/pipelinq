# Tasks

- [x] 1.1 Move both slugs in the register fragments and the mock register
      **files**: lib/Settings/register.d/40-portal.json, lib/Settings/register.d/98-portal-mfa-pending-secret.json, lib/Settings/pipelinq_mock_register.json
- [x] 2.1 Follow the slugs through the portal controllers and services
      **files**: lib/Controller/PortalAuthController.php, lib/Controller/PortalAdminController.php, lib/Controller/PortalDocumentController.php, lib/Service/Portal/PortalAccountService.php, lib/Service/Portal/PortalAuthService.php, lib/Service/Portal/PortalProfileService.php, lib/Service/Portal/PortalRequestGuard.php, lib/Service/Portal/PortalDelegationService.php, lib/Service/Portal/PortalScopeResolver.php, lib/Service/Portal/PasswordResetService.php, lib/Service/Portal/PortalCleanupService.php, lib/Service/Portal/PortalSessionManager.php
- [x] 3.1 Move the slugs in `SCHEMA_SLUGS` and pin both old config keys
      **files**: lib/Service/SettingsLoadService.php
- [x] 4.1 Add both renames to the colliding-slug map
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php
- [x] 4.2 Extend the repair test to five slugs
      **files**: tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 5.1 Repoint the test stubs keyed on the old slugs
      **files**: tests/Unit/Service/Portal/, tests/Unit/Service/ConfigFileLoaderServiceTest.php
