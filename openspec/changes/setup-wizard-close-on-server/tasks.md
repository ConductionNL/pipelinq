# Tasks: setup-wizard-close-on-server

## 1. Library

- [x] 1.1 `npm install @conduction/nextcloud-vue@2.71.0` (package.json `^2.71.0` + lockfile)
- [x] 1.2 Re-vendor `tests/schemas/app-manifest-v2.schema.json` from `node_modules/@conduction/nextcloud-vue/src/schemas/`
  - Verify: `node tests/validate-manifest.js` reports schema 2.53.0, 0 errors

## 2. Server-side close

- [x] 2.1 Add `"dismissAction": "dismiss-setup"` to `src/manifest.json` `setup`
- [x] 2.2 `SetupController::runAction('dismiss-setup')` stores `setup_dismissed_version` = the setup version and writes nothing else
- [x] 2.3 `SetupController::status()` returns `dismissed` (stored version, or false; a non-numeric value reads as false)
- [x] 2.4 `tests/Unit/Controller/SetupControllerDismissTest.php` covers REQ-SETUP-PIP-010
  - Verify: all five tests fail on development before 2.1 to 2.3 and pass after

## 3. Admin card

- [x] 3.1 `tests/vitest/setupWizardRerun.spec.js` asserts the card opens the wizard with a close recorded, and reads no dismissal record

## 4. Live check

- [x] 4.1 On :8099, close the wizard in one browser context, open the app in a fresh context (new cookies, empty storage): the wizard stays closed
  - @e2e exclude {needs two browser contexts and an admin who has not finished setup; asserted by 2.4 and the live check}
- [x] 4.2 After the close, "Run the setup wizard again" on the admin page still opens the wizard, and the organisation step shows its intro text
