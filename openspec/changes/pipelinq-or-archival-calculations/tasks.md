# Tasks — pipelinq: archival + calculation annotation migration

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 4 — archival annotation

pipelinq has implicit retention (callback logs, automation runs, kennisbank versions). The
audit didn't flag specific retention constants; this phase asks the apply phase to confirm
which schemas need archival.

- [ ] 4.1 Inventory pipelinq schemas that need Archiefwet retention (kennisbank versions,
      task history, callback logs). Confirm with the DPO.
- [ ] 4.2 Add `x-openregister-archival.retention` per schema where needed.

## Phase 5 — calculation annotation

Resolves the `lead-management/spec.md` open question + adds calculations for
staleness/aging/lead-value.

- [ ] 5.1 `openspec/specs/lead-management/spec.md:1024` — resolve "frontend vs backend
      qualification score" as `x-openregister-calculations`. Score is a backend
      calculation, frontend reads it.
- [ ] 5.2 `openspec/specs/lead-management/spec.md:505` — staleness as a calculation
      annotation.
- [ ] 5.3 `openspec/specs/lead-management/spec.md:519` — aging as a calculation annotation.
- [ ] 5.4 `openspec/specs/lead-management/spec.md:924` — lead-value as a calculation
      annotation.
