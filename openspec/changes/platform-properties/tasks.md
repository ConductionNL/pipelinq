# Tasks: platform-properties

## 1. Tags
- [ ] 1.1 Add `@spec openspec/specs/platform-properties/spec.md#REQ-PLAT-004` to `lib/AppInfo/Application.php` (class docblock) and `#REQ-PLAT-005` to the class docblock of each controller that has a `#[PublicPage]` method (V1)
- [ ] 1.2 Add an XML comment `<!-- @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001, #REQ-PLAT-002 -->` above `<licence>` in `appinfo/info.xml` (V1)

## 2. Checks that keep the properties true
- [ ] 2.1 `tests/Unit/Platform/AppInfoPropertiesTest.php`: reads `appinfo/info.xml` and `LICENSE`; asserts `<licence>EUPL-1.2</licence>`, that `LICENSE` is the EUPL-1.2 text, and that `openregister` is the only dependency `src/manifest.json` marks required (the others carry `"required": false`) (REQ-PLAT-001, REQ-PLAT-002) (V1)
- [ ] 2.2 Same test: asserts no file under `lib/` contains a seat or licence-key check (`maxUsers`, `seatCount`, `licenseKey`, `licenceKey`) (REQ-PLAT-003) (V1)
- [ ] 2.3 Same test: parses `appinfo/routes.php` and the controllers and asserts every routed method carries a Nextcloud access attribute, and that every `#[PublicPage]` method is listed in an allow-list in the test with the token or signature check it relies on (REQ-PLAT-005) (V1)

## 3. House style
- [ ] 3.1 Add a stylelint rule (`color-no-hex` with `ignore` for CSS custom property fallbacks) to `.stylelintrc` scoped to `src/**/*.vue` and `css/**/*.css` (REQ-PLAT-006) (V1)
- [ ] 3.2 Replace the hard-coded hex colours in the 31 `.vue` files a grep finds on development (`grep -rlE '#[0-9a-fA-F]{6}\b' src --include=*.vue`) with Nextcloud theme variables (`--color-primary-element`, `--color-main-text`, `--color-error`, `--color-success`, ...); keep a hex only as the fallback of a `var()` (V1)
- [ ] 3.3 Verify live with thematiq on: the dashboard, a client page and the pipeline board take the configured colours (V1)

## 4. Matrix
- [ ] 4.1 When the tasks are done, archive this change and repoint the six rows in `openspec/parity/capabilities.json` from `built.change` to `built.spec: openspec/specs/platform-properties`
