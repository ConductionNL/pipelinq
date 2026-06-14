# pipelinq: manifest + multi-tenancy + i18n adoption

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice covers Phase 8 (manifest adoption per Hydra
`adopt-app-manifest`) and Phase 9 (multi-tenancy + i18n adoption gated on
nc-vue and OR shipping the prerequisites). Both phases declare consumed
shared specs and pair naturally as the manifest is the declarative home for
those `consumes` entries.

## What Changes

### Manifest adoption (Phase 8)

1. Create `openspec/manifest.yaml` with `tier: 3` (frontend exemplar),
   `dependencies: ["openregister"]`, the consumed shared specs.
2. Pin minimum OR version (must include `register-resolver-service` and
   `contacts-actions` integration provider).
3. Declare `pipelinq.role: object-store-exemplar` so other apps can find the
   reference implementation.
4. Validate the manifest with the Hydra manifest schema once it ships.

### Multi-tenancy + i18n adoption (Phase 9)

5. Adopt `multi-tenancy-context` formally — pass tenant context explicitly
   into `createObjectStore`.
6. Adopt `i18n-source-of-truth` for translatable fields on kennisbank, lead,
   task, callback schemas.
7. Adopt `i18n-api-language-negotiation` for the pipelinq API.

## Affected Projects

- pipelinq (consumer)
- nextcloud-vue (must ship `multi-tenancy-context`)
- openregister (must ship `i18n-source-of-truth` +
  `i18n-api-language-negotiation`)
- hydra (must ship `adopt-app-manifest`)

## Impact

- Affected files: new `openspec/manifest.yaml`,
  `src/store/modules/object.js` (explicit tenant context),
  pipelinq schemas (`label`, `description`, lifecycle-state names).
- Affected specs: new `pipelinq-or-adoption` capability slice (delta only).
- Breaking changes: none — additive.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  (Decision 7: createObjectStore exemplar role flows into the manifest).
- `hydra/openspec/changes/adopt-app-manifest/` — manifest schema.
