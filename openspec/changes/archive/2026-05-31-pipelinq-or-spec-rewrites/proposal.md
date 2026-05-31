# pipelinq: spec rewrites + createObjectStore exemplar declaration

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice bundles the two spec-text-rewrite phases:

- **Phase 6 (spec rewrites)** — rewrite `contacts-sync` to consume OR's
  `contacts-actions` integration provider; minor edits to `lead-management`;
  cross-link `openregister-integration` and the reframed `adr-000`.
- **Phase 10 (createObjectStore exemplar)** — explicitly declare
  `src/store/modules/object.js` as the reference implementation so future
  audits cite the requirement rather than re-investigating.

Both phases are spec-text-only and pair as one change since neither touches
code.

## What Changes

### Spec rewrites (Phase 6)

1. Rewrite `openspec/specs/contacts-sync/spec.md` to consume OR's
   `contacts-actions` integration provider (`ContactMatchingService`); drop
   bespoke matching/scoring; document fallback when provider absent.
2. Update `openspec/specs/lead-management/spec.md` with calculation annotations
   from the archival+calc slice; keep enum patterns at lines 26/35.
3. Cross-link `openspec/specs/openregister-integration/spec.md` (CURRENT,
   exemplar) under "See Also" — do NOT rewrite.
4. Reference `openspec/changes/archive/.../adr-000` (already reframed by
   Phase 1 PR #315) — cite, do NOT repeat.

### createObjectStore exemplar declaration (Phase 10)

5. Add an EXPLICIT Requirement stating `src/store/modules/object.js` is the
   reference implementation of the `createObjectStore` pattern.
6. Add a Scenario stating future audits SHALL cite this Requirement and SHALL
   NOT flag the file as needing rewrite.

## Affected Projects

- pipelinq (spec owner)
- openregister (must ship `pluggable-integration-registry` + `contacts-actions`
  provider)

## Impact

- Affected specs: `openspec/specs/contacts-sync/spec.md` (REWRITE),
  `openspec/specs/lead-management/spec.md` (minor edits),
  `openspec/specs/openregister-integration/spec.md` (link only). New
  `pipelinq-or-adoption` capability slice.
- Affected code: none in this slice.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  (Decision 4: contacts-sync rewrite; Decision 7: exemplar declaration).
- `.claude/audit-2026-05-03/02-spec-rewrite.md` — audit source.
