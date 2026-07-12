## 1. Baseline

- [ ] 1.1 Capture BEFORE baseline: `phpunit --configuration phpunit-unit.xml --no-coverage` pass/fail counts + scoped `phpcs` on `lib/Service/KlantbeeldSummaryService.php`, `lib/Controller/KlantbeeldController.php`

## 2. Backend rename

- [ ] 2.1 `git mv lib/Service/KlantbeeldSummaryService.php lib/Service/Customer360SummaryService.php`; rename class + update namespaced references/DI
- [ ] 2.2 `git mv lib/Controller/KlantbeeldController.php lib/Controller/Customer360Controller.php`; rename class + update namespaced references
- [ ] 2.3 Update route in `appinfo/routes.php`: `GET /api/klantbeeld/summary` → `GET /api/customer-360/summary`, controller reference
- [ ] 2.4 `git mv` + rename the two unit test classes (`Customer360SummaryServiceTest`, `Customer360ControllerTest`) and their internal references

## 3. Frontend + spec rename

- [ ] 3.1 Update `src/manifest.json`: 5 stat-widget `source.url` bindings `/apps/pipelinq/api/klantbeeld/summary` → `/apps/pipelinq/api/customer-360/summary`; update the `_note` field's `KlantbeeldSummaryService`/`klantbeeld/summary` mentions; validate JSON + `node scripts/check-manifest.js`
- [ ] 3.2 `git mv openspec/specs/klantbeeld-360/ openspec/specs/customer-360/`; rename "Klantbeeld"-titled requirements/prose to "Customer 360" per this change's spec delta; update `@spec` tags in `src/App.vue`, `src/views/leads/cells/LeadProbabilityCell.vue`, `src/views/leads/cells/LeadCloseDateCell.vue`

## 4. Cross-change + docs cleanup

- [ ] 4.1 Rename `openspec/changes/customer-satisfaction-closed-loop/specs/klantbeeld-360/` → `specs/customer-360/`; update its `klantbeeld-360`/`klantbeeld` prose references (proposal/design/tasks) — mechanical rename only, no scope change
- [ ] 4.2 Rename `docs/Features/klantbeeld-360.md` → `customer-360.md`; update its content + any `features.json`/sidebar references; update `CHANGELOG.md` Unreleased/Changed entry
- [ ] 4.3 Grep repo-wide for remaining `klantbeeld`/`Klantbeeld` (excluding vendor/node_modules and the three ARCHIVED change dirs, which stay untouched as history); confirm only expected leftovers remain (archived dirs, l10n Dutch translation values, historical CHANGELOG "Added" entries)

## 5. Verify

- [ ] 5.1 Capture AFTER baseline: same `phpunit` + scoped `phpcs` commands on the renamed files; confirm zero new failures vs. 1.1
