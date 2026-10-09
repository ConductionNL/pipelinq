# Tasks: simple-tour-and-readable-labels

## 1. A tour in the simple menu

- [x] 1.1 `src/menu-layout.simple.json` declares `tours`: the contact centre tour `pipelinq:contact-centre`
- [x] 1.2 `buildProfiledManifest` adds a profile's `tours` to `walkthrough.tours` (`applyProfileTours`); the full file declares none
- [x] 1.3 en and nl strings for every step
- [x] 1.4 `tests/vitest/structureProfile.spec.js`: in the simple structure a tour remains after `holdUnreachableTours`, every menu entry it points at is in the simple menu, and the user settings condition (`enabled` and at least one tour) holds
  - Verify: fails on development (the simple structure keeps no tour)

## 2. Task labels

- [x] 2.1 `lib/Settings/register.d/99-zz-form-presentation.json`: `x-enum-labels` for crmTask `type`, `status` and `priority`
- [x] 2.2 en and nl catalogue entries for every label
- [x] 2.3 `tests/vitest/taskEnumLabels.spec.js`: every value of the three enums has a label in the merged register, and the label has en and nl entries
  - Verify: fails on development

## 3. Notification rule labels

- [x] 3.1 `src/services/notificationLabels.js`: a translated label for every rule pipelinq declares, keyed `<schema>.<rule>`
- [x] 3.2 `App.vue` passes them to CnAppRoot as `notificationLabels`
- [x] 3.3 `tests/vitest/notificationLabels.spec.js`: every rule in the merged register has a label with en and nl entries, and App.vue passes the map
  - Verify: fails on development

## 4. The version in the user settings footer

- [x] 4.1 `DashboardController::page()` provides the `version` initial state from `IAppManager::getAppVersion()`
- [x] 4.2 `scripts/appVersionDefine.js` builds the `appVersion` expression; `webpack.config.js` uses it with the `info.xml` version as fallback
- [x] 4.3 `tests/vitest/appVersionDefine.spec.js` evaluates the define from `webpack.config.js` against a page with and without the initial state
  - Verify: fails on development (the define reads `0.1.0`)
- [x] 4.4 `tests/Unit/Controller/DashboardControllerTest.php` asserts the `version` initial state
  - Verify: fails on development
