# Tasks: platform-accessibility-statement

## 1. Automated evidence

- [ ] 1.1 Add `tests/e2e/backoffice-axe.spec.ts`: inject `axe-core/axe.min.js`, run it with the `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa` tags on each sample page from design D2, scoped to the app content root
  - Verify: the spec runs green locally against a seeded instance; a deliberately removed `aria-label` on a sample control makes it fail
- [ ] 1.2 Add the known-issues allow list `tests/e2e/a11y-known-issues.json` with the four controls of `keyboard-accessible-click-toggles`; the spec fails on a new violation and on a listed one that no longer occurs
  - Verify: Vitest or a unit check over the list format; removing one entry makes the axe spec fail
- [ ] 1.3 Add `tests/e2e/backoffice-keyboard.spec.ts`: on each sample page, Tab reaches every interactive control, the focused control has a visible outline, and Escape closes an open dialog
  - Verify: the spec runs green locally; `npm run check:e2e-nav` exit 0
- [ ] 1.4 Add both specs to the nightly e2e job on `development`
  - Verify: the workflow file lists them; the next nightly run shows them

## 2. Manual audit

- [ ] 2.1 Run a WCAG-EM audit of the sample with NVDA and Orca and record it in `docs/compliance/wcag-audit-<date>.md`: per criterion, result, method, page and finding
  - Verify: the record covers all 50 WCAG 2.1 AA criteria, each with a result or "not evaluated"
- [ ] 2.2 Open one issue per failing criterion that no open change covers, and name it in the audit record
  - Verify: every failing row in the record carries an issue or change name

## 3. Report and draft statement

- [ ] 3.1 Write `docs/compliance/accessibility-conformance-report.md`: pipelinq version, date, sample, and one row per WCAG 2.1 AA criterion with its conformance level and the method that decided it
  - Verify: docs build exit 0; the report and the audit record agree row by row
- [ ] 3.2 Write `docs/compliance/toegankelijkheidsverklaring-concept.md` in Dutch: what pipelinq can state, the known issues, and the fields the municipality fills in
  - Verify: docs build exit 0; the writing skill's checks pass (no em-dashes, sentence case)
- [ ] 3.3 Point rows A-01 to A-04 in `docs/Features/government-compliance.md` at the report, and replace the bare claim at `README.md:242` with a link to it
  - Verify: docs build exit 0

## 4. Admin page

- [ ] 4.1 Add an Accessibility `NcSettingsSection` to `src/views/settings/Settings.vue` with the two links and the date of the last manual audit
  - Verify: Vitest mounts the section and finds both links; `npm run test:l10n` exit 0
