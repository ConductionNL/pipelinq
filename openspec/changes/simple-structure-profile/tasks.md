# Tasks: simple-structure-profile

Kind: code. Follows the dossiq pilot (`PATTERN-simple-structure.md`).

- [x] 0.1 Move `@conduction/nextcloud-vue` from the exact pin `2.56.0-beta.5`
  to `^2.60.0`.
- [x] 1.1 `src/utils/structureProfile.js`, copied from dossiq (design D-1).
- [x] 1.2 `src/menu-layout.simple.json`: the simple menu, the moves to
  settings, the modules, the start page, the Reports card (design D-1, D-3,
  D-5, D-6).
- [x] 1.3 `src/utils/menuModules.js`: `resolveMenuModules`, `applyMenuModules`,
  `applyHomePage` (design D-3, D-5).
- [x] 1.4 `src/manifest.d/98-modules.json` and `src/views/ModulesPage.vue`: the
  Modules page (design D-4).
- [x] 1.5 `src/main.js` picks the layout file and the modules from initial
  state, and redirects `/` to the start page.
- [x] 1.5b `holdUnreachableTours`: the sales tour does not start in the simple
  structure (design D-5b).
- [x] 1.6 `lib/Service/Settings/MenuStructure.php`, both keys in
  `SettingsService::CONFIG_KEYS`, initial state from `DashboardController` and
  `AdminSettings` (design D-2).
- [x] 1.7 Admin settings section "Menu structure"
  (`src/views/settings/MenuStructureSettings.vue`,
  `src/services/menuStructureSetting.js`).
- [x] 1.8 l10n: the new strings in English and Dutch.
- [x] 2.1 `tests/vitest/structureProfile.spec.js`: both structures built with
  the library's real `buildManifest`; the no-loss rule; modules; the start
  page; the setting; the save; the built manifests validated against the
  installed schema (design D-7).
- [x] 2.2 `tests/Unit/Service/Settings/MenuStructureTest.php`.
- [x] 2.3 `tests/e2e/simple-structure-menu.spec.ts`, and
  `tests/e2e/ci-seed.sh` puts the CI instance on `full` (design D-8).
- [ ] 3.1 Live check on a dev instance by the coordinator: the simple menu, the
  start page, the Modules page, the admin choice and one module switch.
