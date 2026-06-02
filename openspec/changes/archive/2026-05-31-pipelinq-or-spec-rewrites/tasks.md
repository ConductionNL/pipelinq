# Tasks — pipelinq: spec rewrites + createObjectStore exemplar declaration

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. No code changes proposed in this slice.

## Phase 6 — spec rewrites (stream 2)

Audit citation: `.claude/audit-2026-05-03/02-spec-rewrite.md`.

- [x] 6.1 Rewrite `openspec/specs/contacts-sync/spec.md`:
      - Replace custom NC Contacts sync with OR's `contacts-actions` integration provider
        (`ContactMatchingService`).
      - Drop the bespoke matching/scoring logic; consume the provider's output.
      - Document fallback behavior when the provider is not registered.
      Done: added "Contact matching is delegated to the contacts-actions provider" requirement
      (delegation + graceful-degradation scenarios), reframed the already-linked/matching
      implementation note, and added a See Also cross-link. Write-back/import/addressbook
      concerns kept as Pipelinq's (only matching/scoring is provider-owned).
- [x] 6.2 Update `openspec/specs/lead-management/spec.md` with calculation annotations from
      the archival+calc slice. Keep enum patterns at lines 26/35 (correct).
      Done: cited `x-openregister-calculations.qualificationScore` (materialise:true) on the
      scoring scenario and `x-openregister-calculations.weightedValue` on the reporting
      scenario, resolved the stale "frontend vs backend scoring" open question in favour of
      the OR calculation engine, and added a See Also. Enum table rows (source/priority) left
      untouched.
- [x] 6.3 Cross-link `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar)
      from this change's spec under "See Also". Do NOT rewrite it.
      Done: present in the capability spec See Also and additionally referenced from the
      contacts-sync and lead-management See Also sections. openregister-integration not modified.
- [x] 6.4 Reference `openspec/changes/archive/.../adr-000` (already reframed by Phase 1
      PR #315) — cite, do NOT repeat its content.
      Done with correction: the canonical ADR is `openspec/architecture/adr-000-data-model.md`,
      NOT under `openspec/changes/archive/`. The placeholder path in this task did not resolve
      to a real file; cited the real architecture path and noted the correction inline in the
      capability spec See Also. Content not repeated.

## Phase 10 — spec note: createObjectStore exemplar status

Distinct from apply work; spec-side declaration so the exemplar status doesn't get lost.

- [x] 10.1 Add an EXPLICIT requirement in the capability spec stating
      `src/store/modules/object.js` is the reference implementation of the
      `createObjectStore` pattern.
      Done: "pipelinq is the createObjectStore exemplar" requirement names the file and its
      `[filesPlugin(), auditTrailsPlugin(), relationsPlugin(), registerMappingPlugin()]`
      plugin list. Verified the live file (`src/store/modules/object.js`) matches that exact
      plugin list — no code change needed (exemplar, Options-API `createObjectStore`, not a
      custom store).
- [x] 10.2 Add a scenario stating future audits SHALL cite this Requirement and SHALL NOT
      flag the file as needing rewrite.
      Done: "createObjectStore usage is preserved" + "Other apps reference pipelinq for the
      pattern" scenarios in the capability spec.
