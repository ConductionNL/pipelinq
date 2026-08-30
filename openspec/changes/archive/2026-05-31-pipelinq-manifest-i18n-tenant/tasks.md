# Tasks — pipelinq: manifest + multi-tenancy + i18n adoption

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Definition of Done & deferral policy

This change has two phases with different readiness:

- **Phase 8 (manifest adoption)** — the in-scope deliverable. The OpenSpec
  coordination manifest (`openspec/manifest.yaml`) is the artifact and it is
  authored, declaring `tier`, `dependencies`, all six `consumes` entries, the OR
  min-version pin, and the `object-store-exemplar` role. 8.1–8.3 are DONE. 8.4
  (Hydra schema validation) is BLOCKED on an unshipped Hydra prerequisite (no
  `check:manifest` script/schema exists yet) and is explicitly DEFERRED.

- **Phase 9 (multi-tenancy + i18n runtime adoption)** — per the proposal's
  "Affected Projects", every Phase-9 task is GATED on an external prerequisite
  shipping first (nextcloud-vue `multi-tenancy-context`; OpenRegister
  `i18n-source-of-truth` + `i18n-api-language-negotiation`). As of this apply,
  all three prerequisite changes are unshipped (0 tasks done, not promoted to
  their owners' main specs, composable/contract absent at runtime). Per the
  proposal, the manifest is the declarative home for these `consumes` entries —
  **declaring the consume IS the Phase-8 deliverable** and is complete; runtime
  adoption (9.1–9.3) is correctly DEFERRED to a follow-up change once each
  prerequisite ships. No Phase-9 task is faked.

DoD for archiving THIS change: Phase-8 manifest authored + consumes declared
(met). Gated Phase-9 runtime-adoption tasks are out of this change's deliverable
scope and tracked as deferred above; they do not block archival.

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- [x] 8.1 Create `openspec/manifest.yaml` with: `tier: 3` (frontend exemplar),
      `dependencies: ["openregister"]`,
      `consumes: [register-resolver-service, pluggable-integration-registry,
      i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
      (Also lists `contacts-actions` in `consumes` per the OR version-pin requirement.)
- [x] 8.2 Pin minimum OR version in the manifest (must include
      `register-resolver-service` and `contacts-actions` integration provider).
      Pinned `openregister.min-version: "1.0.2"` — the OR stable line that ships both
      capabilities (contacts-actions archived 2026-05-01; register-resolver present).
- [x] 8.3 In the manifest, declare `pipelinq.role: object-store-exemplar` (or equivalent
      key as defined by `adopt-app-manifest`) so other apps can find the reference
      implementation.
- [ ] 8.4 Validate the manifest with the Hydra manifest schema once it ships.
      BLOCKED-ON-PREREQ: Hydra `adopt-app-manifest` is proposal-only — no
      coordination-manifest schema and no `npm run check:manifest` script exist yet.
      The manifest parses as valid YAML and matches the `tier`/`consumes`/`dependencies`
      shape the pipelinq spec requires; formal schema validation must wait for the
      Hydra schema to ship. Left unchecked.

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- [ ] 9.1 Adopt `multi-tenancy-context` formally: `src/store/modules/object.js` already
      receives tenant context implicitly via `createObjectStore`; declare the dependency
      explicitly in the store factory call.
      BLOCKED-ON-PREREQ: nextcloud-vue `multi-tenancy-context` is an active change
      (`nextcloud-vue/openspec/changes/multi-tenancy-context/`, 0/25 tasks done). The
      `useTenantContext()` composable does not exist in `nextcloud-vue/src/composables/`
      and the capability is not in nc-vue's main specs. There is nothing to import; faking
      the call would reference an undefined export. Deferred until nc-vue ships the
      composable. The consume is already DECLARED in `openspec/manifest.yaml`
      (Phase 8 deliverable). Left unchecked.
- [ ] 9.2 Adopt `i18n-source-of-truth` for translatable fields on kennisbank, lead, task,
      callback schemas (label, description, lifecycle-state-display-name, notification
      copy from the lifecycle+notification slice).
      BLOCKED-ON-PREREQ: OpenRegister `i18n-source-of-truth` is an active OR change
      (`openregister/openspec/changes/i18n-source-of-truth/`, 0/20 tasks done) not yet
      promoted to OR's main specs. The OR translatable-field source-of-truth contract
      pipelinq's schemas would target does not exist at runtime yet. (Note: pipelinq's
      front-end UI strings already ship full nl + en via `l10n/nl.json` + `l10n/en.json`
      with ~1,150 `t()` call sites — the repo i18n minimum is met; this task is about the
      OR *schema-field* translation layer specifically.) Deferred. The consume is DECLARED
      in `openspec/manifest.yaml`. Left unchecked.
- [ ] 9.3 Adopt `i18n-api-language-negotiation` for the pipelinq API: respect the
      `Accept-Language` header on read responses.
      BLOCKED-ON-PREREQ: OpenRegister `i18n-api-language-negotiation` is an active OR
      change (`openregister/openspec/changes/i18n-api-language-negotiation/`) not yet in
      OR's main specs. pipelinq reads through OR's object API, so Accept-Language
      negotiation must land in OR first; pipelinq cannot negotiate a translation OR does
      not yet store/serve. Deferred. The consume is DECLARED in
      `openspec/manifest.yaml`. Left unchecked.
