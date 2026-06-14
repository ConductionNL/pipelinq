# Capability — pipelinq-or-adoption (manifest + i18n + tenancy slice)

## ADDED Requirements

### Requirement: pipelinq declares its manifest

pipelinq SHALL ship `openspec/manifest.yaml` declaring `tier: 3` (frontend exemplar),
`dependencies: ["openregister"]`, the consumed shared specs, the minimum OR version, and
its exemplar role.

#### Scenario: Manifest declares exemplar role

- **GIVEN** `openspec/manifest.yaml` declares
  `pipelinq.role: object-store-exemplar` (or equivalent key from `adopt-app-manifest`)
- **WHEN** Hydra coordination loads the manifest
- **THEN** it SHALL recognize pipelinq as the reference implementation.

#### Scenario: Manifest pins minimum OR version including contacts-actions

- **GIVEN** the spec-rewrites slice depends on the `contacts-actions` provider in OR
- **WHEN** the manifest declares minimum OR version
- **THEN** the version pin SHALL include the OR release that ships
  `contacts-actions`.

### Requirement: pipelinq consumes shared multi-tenancy + i18n specs

pipelinq SHALL consume `multi-tenancy-context`, `i18n-source-of-truth`, and
`i18n-api-language-negotiation`.

#### Scenario: createObjectStore receives tenant context explicitly

- **GIVEN** the nc-vue `multi-tenancy-context` composable is available
- **WHEN** `src/store/modules/object.js` invokes `createObjectStore('object', {...})`
- **THEN** the factory call SHALL pass the tenant context from `useTenantContext()`
  explicitly (formalising the implicit dependency).

#### Scenario: API respects Accept-Language

- **GIVEN** a client sends `Accept-Language: nl-NL` to pipelinq
- **WHEN** the response includes a translatable label or description
- **THEN** the field SHALL return the Dutch translation per OR's negotiation spec.
