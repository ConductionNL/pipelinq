# pipelinq: archival + calculation annotation migration

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice covers Phase 4 (archival annotation per ADR-024) and Phase 5
(calculation annotation per ADR-031). Both are schema-declarative business-logic
migrations and pair naturally as one change.

## What Changes

### Archival annotation (Phase 4)

1. Inventory pipelinq schemas that need Archiefwet retention (kennisbank
   versions, task history, callback logs). Confirm with the DPO.
2. Add `x-openregister-archival.retention` per schema where needed.

### Calculation annotation (Phase 5)

3. `openspec/specs/lead-management/spec.md:1024` — resolve "frontend vs backend
   qualification score" open question as `x-openregister-calculations`.
4. Staleness, aging, and lead-value computations declared as calculation
   annotations on the lead schema.

## Affected Projects

- pipelinq (consumer)
- openregister (must ship ADR-024 archival + ADR-031 calculation annotation
  runtime)

## Impact

- Affected specs: new `pipelinq-or-adoption` capability slice (delta only),
  edits to `openspec/specs/lead-management/spec.md` (lines 505/519/924/1024).
- Affected code: none in this slice (annotations are schema-side).
- Breaking changes: none — computed fields' on-wire values preserved.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  (Decision 3: lead-management keeps enums; adds calculations).
- `hydra/openspec/architecture/ADR-024.md` (archival).
- `hydra/openspec/architecture/ADR-031.md` (calculations).
