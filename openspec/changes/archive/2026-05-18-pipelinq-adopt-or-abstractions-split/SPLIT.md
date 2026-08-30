# Split per ADR-032

This change was archived on 2026-05-18 because its tasks.md held 47 unchecked
items — over Hydra ADR-032's spec-sizing cap (≤20 unchecked tasks per change).

The original `proposal.md`, `design.md`, and `specs/pipelinq-or-adoption/spec.md`
remain in this archive folder for reference; sub-changes reference back here for
the full design narrative.

## Split into

- `pipelinq-or-register-resolver` (9 tasks) — Phase 1 register-resolver
  migration (8 sites + verify).
- `pipelinq-or-lifecycle-notification` (7 tasks) — Phase 2 lifecycle annotation
  + Phase 3 notification annotation.
- `pipelinq-or-archival-calculations` (6 tasks) — Phase 4 archival annotation
  + Phase 5 calculation annotation.
- `pipelinq-or-spec-rewrites` (6 tasks) — Phase 6 spec rewrites + Phase 10
  createObjectStore exemplar declaration.
- `pipelinq-admin-config-magic-numbers` (12 tasks) — Phase 7 hardcoded
  magic-number cleanup (12 constants → admin-config).
- `pipelinq-manifest-i18n-tenant` (7 tasks) — Phase 8 manifest adoption +
  Phase 9 multi-tenancy + i18n adoption.

Total: 47 tasks across 6 sub-changes. Each sub-change is ≤20 unchecked tasks.
