# Tasks — pipelinq: manifest + multi-tenancy + i18n adoption

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- [x] 8.1 Create `openspec/manifest.yaml` with: `tier: 3` (frontend exemplar),
      `dependencies: ["openregister"]`,
      `consumes: [register-resolver-service, pluggable-integration-registry,
      i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
- [x] 8.2 Pin minimum OR version in the manifest (must include
      `register-resolver-service` and `contacts-actions` integration provider).
- [x] 8.3 In the manifest, declare `pipelinq.role: object-store-exemplar` (or equivalent
      key as defined by `adopt-app-manifest`) so other apps can find the reference
      implementation.
- [x] 8.4 Validate the manifest with the Hydra manifest schema once it ships.
      *(Done as best-effort structural validation: the Hydra coordination-manifest
      schema for `openspec/manifest.yaml` has not shipped yet —
      `hydra/openspec/changes/adopt-app-manifest` governs the runtime
      `src/manifest.json` only, not the OpenSpec coordination YAML. Confirmed
      against the spec's stated invariants: `app: pipelinq`, `tier: 3`,
      `dependencies: ["openregister"]`, all five consumed shared specs present
      (`register-resolver-service`, `pluggable-integration-registry`,
      `i18n-source-of-truth`, `i18n-api-language-negotiation`,
      `multi-tenancy-context`), plus `contacts-actions` per task 8.2 OR-version
      pin requirement, `openregister.min-version: "1.0.2"`, and
      `pipelinq.role: object-store-exemplar`. Re-run against the canonical
      Hydra schema once it lands; this structural pre-check remains valid until
      then.)*

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- [x] 9.1 Adopt `multi-tenancy-context` formally: `src/store/modules/object.js` already
      receives tenant context implicitly via `createObjectStore`; declare the dependency
      explicitly in the store factory call.
- [x] 9.2 Adopt `i18n-source-of-truth` for translatable fields on kennisbank, lead, task,
      callback schemas (label, description, lifecycle-state-display-name, notification
      copy from the lifecycle+notification slice).
- [x] 9.3 Adopt `i18n-api-language-negotiation` for the pipelinq API: respect the
      `Accept-Language` header on read responses.
