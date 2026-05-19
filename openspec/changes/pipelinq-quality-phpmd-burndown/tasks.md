# Tasks: pipelinq PHPMD burn-down

ADR-032 cap respected (≤20 unchecked tasks).

## Phase 3 — PHPMD burn-down

Contingent on the PHPCS+inventory slice's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] If baseline captured: ElseExpression — re-shape `if/else` to
      early-return
- [ ] If baseline captured: CyclomaticComplexity / NPathComplexity —
      extract methods
- [ ] If baseline captured: MissingImport — add `use` statements
- [ ] If baseline captured: StaticAccess — replace with DI
- [ ] If baseline captured: variable-naming sniffs (Long/Short/
      Undefined/UnusedFormalParameter)
- [ ] Once baseline reaches 0 lines: delete phpmd.baseline.xml and
      drop `--baseline-file` from composer.json's phpmd script

## Phase 5 — PHPMD baseline cleanup (deferred until baseline empty)

- [ ] Once all PHPMD baseline lines are zero: confirm `phpmd.baseline.xml` is
      deleted from the working tree (was created in Phase 1 if baseline path
      was chosen)
- [ ] Confirm `composer phpmd` exits zero against current code with no
      baseline file referenced
- [ ] Re-run `composer check:strict` end-to-end and confirm green
