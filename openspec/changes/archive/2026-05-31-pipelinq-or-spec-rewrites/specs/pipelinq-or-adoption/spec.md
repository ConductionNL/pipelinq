# Capability — pipelinq-or-adoption (spec rewrites + exemplar slice)

## ADDED Requirements

### Requirement: Contacts-sync consumes contacts-actions integration provider

`openspec/specs/contacts-sync/spec.md` SHALL be rewritten to consume OR's
`contacts-actions` integration provider (`ContactMatchingService`). The custom NC Contacts
sync SHALL be removed. Fallback behavior (provider not registered) SHALL be documented.

#### Scenario: Sync uses ContactMatchingService

- **GIVEN** OR registers `ContactMatchingService` via `pluggable-integration-registry`
- **WHEN** a pipelinq sync runs
- **THEN** matching SHALL be delegated to `ContactMatchingService`
- **AND** no bespoke matching/scoring logic SHALL exist in pipelinq's contact-sync code.

#### Scenario: Graceful degradation when provider absent

- **GIVEN** the `contacts-actions` provider is NOT registered
- **WHEN** a pipelinq sync runs
- **THEN** the sync SHALL log a warning and SHALL skip the matching step (not crash)
- **AND** the spec SHALL document this fallback behavior explicitly.

### Requirement: pipelinq is the createObjectStore exemplar

The pipelinq frontend SHALL retain `src/store/modules/object.js` as the reference
implementation of the `createObjectStore` pattern with plugins
`[filesPlugin(), auditTrailsPlugin(), relationsPlugin(), registerMappingPlugin()]`. The
file SHALL NOT be migrated or rewritten. Future audits SHALL cite this Requirement and
SHALL NOT flag the file as needing rewrite.

#### Scenario: createObjectStore usage is preserved

- **GIVEN** this Requirement exists in the capability spec
- **WHEN** a future OR-abstraction audit reviews pipelinq
- **THEN** the auditor SHALL cite this Requirement and SHALL NOT flag
  `src/store/modules/object.js` as duplication.

#### Scenario: Other apps reference pipelinq for the pattern

- **GIVEN** the pipelinq manifest declares the exemplar role
- **WHEN** another app's openspec proposal seeks a `createObjectStore` reference
- **THEN** it SHALL cite pipelinq's `src/store/modules/object.js`.

## See Also

- `openspec/specs/openregister-integration/spec.md` — CURRENT, exemplar; not rewritten.
- `openspec/specs/lead-management/spec.md` — minor edits per the archival+calc slice;
  existing enums kept.
- `openspec/specs/contacts-sync/spec.md` — REWRITE per Phase 6.
- `openspec/architecture/adr-000-data-model.md` — the data-model ADR (reframed in the
  OR-adoption work) that establishes "OpenRegister owns built-in fields/capabilities; do
  NOT redefine or rebuild". Cite, do not repeat. (Task 6.4 originally pointed at an
  `openspec/changes/archive/.../adr-000` path; the canonical ADR actually lives under
  `openspec/architecture/` — corrected here.)
