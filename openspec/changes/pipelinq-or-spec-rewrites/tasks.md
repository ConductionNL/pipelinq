# Tasks — pipelinq: spec rewrites + createObjectStore exemplar declaration

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. No code changes proposed in this slice.

## Phase 6 — spec rewrites (stream 2)

Audit citation: `.claude/audit-2026-05-03/02-spec-rewrite.md`.

- [ ] 6.1 Rewrite `openspec/specs/contacts-sync/spec.md`:
      - Replace custom NC Contacts sync with OR's `contacts-actions` integration provider
        (`ContactMatchingService`).
      - Drop the bespoke matching/scoring logic; consume the provider's output.
      - Document fallback behavior when the provider is not registered.
- [ ] 6.2 Update `openspec/specs/lead-management/spec.md` with calculation annotations from
      the archival+calc slice. Keep enum patterns at lines 26/35 (correct).
- [ ] 6.3 Cross-link `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar)
      from this change's spec under "See Also". Do NOT rewrite it.
- [ ] 6.4 Reference `openspec/changes/archive/.../adr-000` (already reframed by Phase 1
      PR #315) — cite, do NOT repeat its content.

## Phase 10 — spec note: createObjectStore exemplar status

Distinct from apply work; spec-side declaration so the exemplar status doesn't get lost.

- [ ] 10.1 Add an EXPLICIT requirement in the capability spec stating
      `src/store/modules/object.js` is the reference implementation of the
      `createObjectStore` pattern.
- [ ] 10.2 Add a scenario stating future audits SHALL cite this Requirement and SHALL NOT
      flag the file as needing rewrite.
